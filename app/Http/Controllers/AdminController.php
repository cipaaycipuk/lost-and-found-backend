<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Ensure the authenticated user is an administrator.
     */
    private function ensureAdmin(Request $request): ?JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya admin yang dapat mengakses halaman ini.',
            ], 403);
        }

        return null;
    }

    /**
     * Return dashboard overview statistics for admin.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil statistik admin',
            'data' => [
                'posts_total' => Post::withTrashed()->count(),
                'posts_open' => Post::where('status', 'open')->count(),
                'posts_resolved' => Post::whereIn('status', ['selesai', 'resolved'])->count(),
                'posts_trashed' => Post::onlyTrashed()->count(),
                'users_total' => User::count(),
                'users_admin' => User::where('role', 'admin')->count(),
                'claims_total' => Claim::count(),
                'claims_pending' => Claim::where('status', 'pending')->count(),
                'claims_accepted' => Claim::where('status', 'accepted')->count(),
            ],
        ]);
    }

    /**
     * List all posts (including soft-deleted when requested) for admin management.
     */
    public function posts(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) {
            return $denied;
        }

        /** @var User $admin */
        $admin = $request->user();

        $query = Post::withTrashed()
            ->with(['user', 'images'])
            ->withCount(['comments', 'claims'])
            ->latest('id');

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where(function ($sub) use ($search): void {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->input('trashed') === 'only') {
            $query->onlyTrashed();
        }

        $posts = $query->get()
            ->map(fn (Post $post): array => $post->toApiArray($admin, []))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar postingan admin',
            'data' => $posts,
        ]);
    }

    /**
     * List all users for admin management.
     */
    public function users(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) {
            return $denied;
        }

        $query = User::query()
            ->withCount(['posts', 'claimsSubmitted'])
            ->latest('id');

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where(function ($sub) use ($search): void {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->get()->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'whatsapp' => $u->whatsapp,
            'avatar' => $u->avatar_url,
            'avatar_url' => $u->avatar_url,
            'role' => $u->role ?? 'user',
            'auth_provider' => $u->auth_provider ?? 'manual',
            'posts_count' => $u->posts_count ?? 0,
            'claims_count' => $u->claims_submitted_count ?? 0,
            'created_at' => $u->created_at?->toISOString(),
        ])->values()->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar pengguna',
            'data' => $users,
        ]);
    }

    /**
     * Update a user's role (user / admin).
     */
    public function updateUserRole(Request $request, User $user): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:user,admin'],
        ]);

        $user->role = $validated['role'];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Role pengguna berhasil diperbarui',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * List all claims across the system for admin review.
     */
    public function claims(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) {
            return $denied;
        }

        /** @var User $admin */
        $admin = $request->user();

        $query = Claim::query()
            ->with(['post.images', 'claimer', 'owner'])
            ->latest('id');

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }

        $claims = $query->get()
            ->map(fn (Claim $claim): array => $claim->toApiArray($admin))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar klaim',
            'data' => $claims,
        ]);
    }
}
