<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Display the authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil data profil',
            'data' => $this->formatProfile($user),
        ]);
    }

    /**
     * Display a user's public profile (hiding sensitive email & whatsapp unless viewing own profile).
     */
    public function publicShow(User $user): JsonResponse
    {
        /** @var User|null $viewer */
        $viewer = auth('sanctum')->user();
        $isSelfOrAdmin = $viewer !== null && ($viewer->id === $user->id || $viewer->role === 'admin');

        $openPosts = $user->posts()
            ->where('status', 'open')
            ->with(['user', 'images'])
            ->withCount(['comments', 'claims'])
            ->latest('created_at')
            ->get()
            ->map(fn ($post): array => $post->toApiArray($viewer, [], []))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil profil pengguna',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $isSelfOrAdmin ? $user->email : null,
                    'whatsapp' => $isSelfOrAdmin ? $user->whatsapp : null,
                    'avatar' => $user->avatar_url,
                    'avatar_url' => $user->avatar_url,
                    'role' => $user->role ?? 'user',
                    'is_self' => $isSelfOrAdmin,
                ],
                'posts' => $openPosts,
            ],
        ]);
    }

    /**
     * Update the authenticated user's name, contact, and avatar.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'whatsapp' => ['sometimes', 'required', 'string', 'max:25'],
            'contact' => ['sometimes', 'required', 'string', 'max:25'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'avatar' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('whatsapp', $validated)) {
            $user->whatsapp = $validated['whatsapp'];
        } elseif (array_key_exists('contact', $validated)) {
            $user->whatsapp = $validated['contact'];
        }

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
            $user->auth_provider = $user->google_id ? 'manual,google' : 'manual';
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_url && str_starts_with($user->avatar_url, '/storage/')) {
                $oldPath = substr($user->avatar_url, strlen('/storage/'));
                Storage::disk('public')->delete($oldPath);
            }

            $storedPath = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_url = Storage::url($storedPath);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui',
            'data' => $this->formatProfile($user),
        ]);
    }

    /**
     * Format profile data for API response.
     *
     * @return array{id: int, name: string, email: string, whatsapp: string|null, avatar: string|null, avatar_url: string|null, role: string, auth_provider: string, has_password: bool, created_at: string|null, updated_at: string|null}
     */
    private function formatProfile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'whatsapp' => $user->whatsapp,
            'avatar' => $user->avatar_url,
            'avatar_url' => $user->avatar_url,
            'role' => $user->role ?? 'user',
            'auth_provider' => $user->auth_provider ?? 'manual',
            'has_password' => $user->password !== null,
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
