<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Post;
use App\Models\SavedPost;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedPostController extends Controller
{
    /**
     * Display a listing of posts saved by the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $limit = max(1, min(100, (int) $request->input('limit', 10)));

        $paginator = SavedPost::query()
            ->where('user_id', $user->id)
            ->whereHas('post')
            ->with([
                'post' => fn ($query) => $query
                    ->with(['user', 'images'])
                    ->withCount('comments'),
            ])
            ->latest()
            ->paginate($limit);

        $savedPostIds = collect($paginator->items())
            ->pluck('post_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $data = collect($paginator->items())
            ->map(fn (SavedPost $savedPost): array => $savedPost->post->toApiArray($user, $savedPostIds))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar laporan tersimpan',
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Toggle save / unsave a post for the authenticated user.
     */
    public function toggle(Request $request, ?Post $post = null): JsonResponse
    {
        if ($post === null) {
            $validated = $request->validate([
                'post_id' => ['required', 'integer', 'exists:posts,id'],
            ]);
            $post = Post::findOrFail($validated['post_id']);
        }

        /** @var User $user */
        $user = $request->user();

        $existing = SavedPost::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return response()->json([
                'success' => true,
                'message' => 'Laporan dihapus dari daftar simpanan',
                'data' => [
                    'post_id' => $post->id,
                    'is_saved' => false,
                ],
            ]);
        }

        SavedPost::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
        ]);

        if ($post->user_id !== $user->id) {
            Notification::create([
                'user_id' => $post->user_id,
                'actor_id' => $user->id,
                'post_id' => $post->id,
                'type' => 'bookmark',
                'message' => "{$user->name} menyimpan laporan Anda \"{$post->title}\".",
                'is_read' => false,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Laporan berhasil disimpan',
            'data' => [
                'post_id' => $post->id,
                'is_saved' => true,
            ],
        ]);
    }
}
