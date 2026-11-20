<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Throwable;

class AuthController extends Controller
{
    /**
     * Register a new user manually (or link manual password to an existing Google-only account with the same email).
     */
    public function register(Request $request): JsonResponse
    {
        if (! $request->filled('whatsapp') && $request->filled('contact')) {
            $request->merge(['whatsapp' => $request->input('contact')]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:6'],
            'whatsapp' => ['required', 'string', 'max:25'],
        ]);

        $existingUser = User::where('email', $validated['email'])->first();

        if ($existingUser !== null) {
            if ($existingUser->password !== null) {
                throw ValidationException::withMessages([
                    'email' => ['The email has already been taken.'],
                ]);
            }

            // Account was created via Google first (no manual password yet): link manual credentials to the same email
            $existingUser->name = $validated['name'];
            $existingUser->password = $validated['password'];
            $existingUser->whatsapp = $existingUser->whatsapp ?: $validated['whatsapp'];
            $existingUser->auth_provider = $existingUser->google_id ? 'manual,google' : 'manual';
            $existingUser->save();

            $token = $existingUser->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Akun Google berhasil dihubungkan dengan password manual',
                'data' => [
                    'token' => $token,
                    'user' => $this->formatUser($existingUser),
                ],
            ], 201);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'whatsapp' => $validated['whatsapp'],
            'auth_provider' => 'manual',
            'role' => 'user',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil',
            'data' => [
                'token' => $token,
                'user' => $this->formatUser($user),
            ],
        ], 201);
    }

    /**
     * Authenticate a user manually with email & password and issue an access token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user !== null && $user->password === null) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini terdaftar melalui Google. Silakan login menggunakan Google atau daftar password dengan email yang sama.',
            ], 401);
        }

        if (! $user || ! Hash::check($validated['password'], (string) $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'token' => $token,
                'user' => $this->formatUser($user),
            ],
        ]);
    }

    /**
     * Authenticate or register via real Google Auth Library (Laravel Socialite + Google JWT/OAuth2 verification).
     * Expects `credential` / `id_token` (Google ID token JWT) or `access_token` / `token` from Google Sign-In.
     */
    public function googleAuth(Request $request): JsonResponse
    {
        $googleToken = $request->input('credential')
            ?? $request->input('id_token')
            ?? $request->input('access_token')
            ?? $request->input('token');

        if (! is_string($googleToken) || trim($googleToken) === '') {
            throw ValidationException::withMessages([
                'token' => ['Token Google (credential / id_token / access_token) wajib dikirim.'],
            ]);
        }

        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $googleUser = $driver->stateless()->userFromToken($googleToken);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Token Google tidak valid atau sudah kedaluwarsa.',
            ], 401);
        }

        if (! $googleUser->getEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email tidak ditemukan pada akun Google.',
            ], 422);
        }

        $user = $this->findOrLinkGoogleUser(
            $googleUser,
            $request->input('whatsapp') ?? $request->input('contact')
        );

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login Google berhasil',
            'data' => [
                'token' => $token,
                'user' => $this->formatUser($user),
            ],
        ]);
    }

    /**
     * Redirect or return the Google OAuth 2.0 authorization URL via Laravel Socialite.
     */
    public function redirectToGoogle(Request $request): JsonResponse|RedirectResponse
    {
        if (empty(config('services.google.client_id')) || empty(config('services.google.client_secret'))) {
            return response()->json([
                'success' => false,
                'message' => 'GOOGLE_CLIENT_ID dan GOOGLE_CLIENT_SECRET belum diisi di file .env',
            ], 500);
        }

        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');
        $redirectResponse = $driver->stateless()->redirect();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'URL OAuth Google berhasil dibuat',
                'data' => [
                    'url' => $redirectResponse->getTargetUrl(),
                ],
            ]);
        }

        return $redirectResponse;
    }

    /**
     * Handle Google OAuth 2.0 callback via Laravel Socialite, link/create user with the same email, and issue token.
     */
    public function handleGoogleCallback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $googleUser = $driver->stateless()->user();
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memverifikasi otorisasi Google.',
            ], 401);
        }

        if (! $googleUser->getEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email tidak ditemukan pada akun Google.',
            ], 422);
        }

        $user = $this->findOrLinkGoogleUser($googleUser);
        $token = $user->createToken('auth_token')->plainTextToken;

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Login Google berhasil',
                'data' => [
                    'token' => $token,
                    'user' => $this->formatUser($user),
                ],
            ]);
        }

        $frontendUrl = rtrim((string) config('services.google.frontend_url', 'http://localhost:5173'), '/');

        return redirect()->away($frontendUrl.'/login?token='.urlencode($token));
    }

    /**
     * Revoke the current user's access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil',
            'data' => null,
        ]);
    }

    /**
     * Find an existing user by email (or google_id) and link Google account, or create a new user.
     */
    private function findOrLinkGoogleUser(SocialiteUser $googleUser, ?string $whatsapp = null): User
    {
        $email = (string) $googleUser->getEmail();
        $googleId = (string) $googleUser->getId();
        $name = (string) ($googleUser->getName() ?: $googleUser->getNickname() ?: explode('@', $email)[0]);
        $avatar = $googleUser->getAvatar();

        $user = User::where('email', $email)
            ->orWhere('google_id', $googleId)
            ->first();

        if ($user !== null) {
            $user->google_id = $googleId;
            $user->auth_provider = $user->password !== null ? 'manual,google' : 'google';

            if (! $user->avatar_url && ! empty($avatar)) {
                $user->avatar_url = $avatar;
            }

            if (! $user->whatsapp && ! empty($whatsapp)) {
                $user->whatsapp = $whatsapp;
            }

            if ($user->email_verified_at === null) {
                $user->email_verified_at = now();
            }

            $user->save();

            return $user;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'google_id' => $googleId,
            'auth_provider' => 'google',
            'email_verified_at' => now(),
            'password' => null,
            'whatsapp' => $whatsapp,
            'avatar_url' => $avatar,
            'role' => 'user',
        ]);
    }

    /**
     * Format user payload for auth responses.
     *
     * @return array{id: int, name: string, email: string, whatsapp: string|null, avatar: string|null, avatar_url: string|null, role: string, auth_provider: string, has_password: bool}
     */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'whatsapp' => $user->whatsapp ?? null,
            'avatar' => $user->avatar_url ?? null,
            'avatar_url' => $user->avatar_url ?? null,
            'role' => $user->role ?? 'user',
            'auth_provider' => $user->auth_provider ?? 'manual',
            'has_password' => $user->password !== null,
        ];
    }
}
