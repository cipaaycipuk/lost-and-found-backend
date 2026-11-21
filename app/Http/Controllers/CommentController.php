<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    /**
     * Display a listing of comments (and replies) for a post.
     */
    public function index(Post $post): JsonResponse
    {
        $rootComments = $post->comments()
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->orderBy('created_at')
            ->get();

        $comments = [];

        foreach ($rootComments as $rootComment) {
            $comments[] = $rootComment->toApiArray();

            foreach ($rootComment->replies as $reply) {
                $comments[] = $reply->toApiArray();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil komentar',
            'data' => $comments,
        ]);
    }

    /**
     * Store a newly created comment or reply (`parent_id`).
     */
    public function store(Request $request, ?Post $post = null): JsonResponse
    {
        $rules = [
            'content' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
        ];

        if ($post === null) {
            $rules['post_id'] = ['required', 'integer', 'exists:posts,id'];
        }

        $validated = $request->validate($rules);

        $targetPost = $post ?? Post::findOrFail((int) $validated['post_id']);
        $parentComment = null;

        if (! empty($validated['parent_id'])) {
            $parentComment = Comment::find($validated['parent_id']);
            if ($parentComment === null || $parentComment->post_id !== $targetPost->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Komentar induk tidak valid untuk laporan ini.'],
                ]);
            }
        }

        /** @var User $user */
        $user = $request->user();

        $comment = Comment::create([
            'post_id' => $targetPost->id,
            'user_id' => $user->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'content' => $validated['content'],
        ]);

        $excerpt = Str::limit(trim($validated['content']), 60);
        $notifiedUserIds = [$user->id];

        // 1. Jika ini adalah balasan (reply) atas komentar seseorang, beri notifikasi ke pemilik komentar induk
        if ($parentComment !== null && ! in_array($parentComment->user_id, $notifiedUserIds, true)) {
            Notification::create([
                'user_id' => $parentComment->user_id,
                'actor_id' => $user->id,
                'post_id' => $targetPost->id,
                'type' => 'reply',
                'message' => "{$user->name} membalas komentar Anda pada laporan \"{$targetPost->title}\": \"{$excerpt}\"",
                'is_read' => false,
            ]);
            $notifiedUserIds[] = $parentComment->user_id;
        }

        // 2. Beri notifikasi ke pemilik post (baik untuk komentar baru maupun balasan di post miliknya)
        if (! in_array($targetPost->user_id, $notifiedUserIds, true)) {
            $message = $parentComment !== null
                ? "{$user->name} membalas komentar pada laporan Anda \"{$targetPost->title}\": \"{$excerpt}\""
                : "{$user->name} mengomentari laporan Anda \"{$targetPost->title}\": \"{$excerpt}\"";

            Notification::create([
                'user_id' => $targetPost->user_id,
                'actor_id' => $user->id,
                'post_id' => $targetPost->id,
                'type' => $parentComment !== null ? 'reply' : 'comment',
                'message' => $message,
                'is_read' => false,
            ]);
            $notifiedUserIds[] = $targetPost->user_id;
        }

        // 3. Jika ada partisipan lain di thread balasan yang sama, beri tahu juga mereka
        if ($parentComment !== null) {
            $threadParticipantIds = Comment::query()
                ->where('parent_id', $parentComment->id)
                ->whereNotIn('user_id', $notifiedUserIds)
                ->distinct()
                ->pluck('user_id')
                ->all();

            foreach ($threadParticipantIds as $participantId) {
                Notification::create([
                    'user_id' => (int) $participantId,
                    'actor_id' => $user->id,
                    'post_id' => $targetPost->id,
                    'type' => 'reply',
                    'message' => "{$user->name} juga membalas diskusi pada laporan \"{$targetPost->title}\": \"{$excerpt}\"",
                    'is_read' => false,
                ]);
                $notifiedUserIds[] = (int) $participantId;
            }
        }

        $comment->load(['user', 'replies.user']);

        return response()->json([
            'success' => true,
            'message' => 'Komentar berhasil dikirim',
            'data' => $comment->toApiArray(),
        ], 201);
    }

    /**
     * Remove the specified comment from storage.
     */
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($comment->user_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus komentar ini',
            ], 403);
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Komentar berhasil dihapus',
            'data' => null,
        ]);
    }
}
