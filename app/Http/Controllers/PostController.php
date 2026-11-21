<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\SavedPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    /**
     * Display a paginated listing of posts with search and filter support.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Post::query()
            ->with(['user', 'images'])
            ->withCount('comments');

        $this->applyFilters($query, $request);

        $limit = max(1, min(100, (int) $request->input('limit', 10)));
        $paginator = $query->paginate($limit);

        /** @var User|null $currentUser */
        $currentUser = auth('sanctum')->user();
        $savedPostIds = $this->getSavedPostIds($currentUser, collect($paginator->items())->pluck('id')->all());

        $data = collect($paginator->items())
            ->map(fn (Post $post): array => $post->toApiArray($currentUser, $savedPostIds))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar laporan',
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
     * Display a paginated listing of posts created by the authenticated user.
     */
    public function myPosts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Post::query()
            ->where('user_id', $user->id)
            ->with(['user', 'images'])
            ->withCount('comments');

        $this->applyFilters($query, $request);

        $limit = max(1, min(100, (int) $request->input('limit', 10)));
        $paginator = $query->paginate($limit);

        $savedPostIds = $this->getSavedPostIds($user, collect($paginator->items())->pluck('id')->all());

        $data = collect($paginator->items())
            ->map(fn (Post $post): array => $post->toApiArray($user, $savedPostIds))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar laporan saya',
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
     * Store a newly created post along with uploaded images.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:kehilangan,ditemukan'],
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:80'],
            'location' => ['required', 'string', 'max:120'],
            'incident_date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'image' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $post = Post::create([
            'user_id' => $user->id,
            'type' => $validated['type'],
            'status' => 'open',
            'title' => $validated['title'],
            'category' => $validated['category'],
            'location' => $validated['location'],
            'incident_date' => $validated['incident_date'],
            'description' => $validated['description'],
        ]);

        $this->storeUploadedImages($request, $post);

        Notification::create([
            'user_id' => $user->id,
            'actor_id' => $user->id,
            'post_id' => $post->id,
            'type' => 'post_created',
            'message' => "Laporan Anda \"{$post->title}\" berhasil dipublikasikan.",
            'is_read' => false,
        ]);

        $post->load(['user', 'images'])->loadCount('comments');

        return response()->json([
            'success' => true,
            'message' => 'Laporan berhasil dibuat',
            'data' => $post->toApiArray($user, []),
        ], 201);
    }

    /**
     * Display the specified post detail including images and comments.
     */
    public function show(Post $post): JsonResponse
    {
        $post->load([
            'user',
            'images',
            'comments' => fn ($query) => $query
                ->whereNull('parent_id')
                ->with(['user', 'replies.user'])
                ->orderBy('created_at'),
        ])->loadCount('comments');

        /** @var User|null $currentUser */
        $currentUser = auth('sanctum')->user();

        $data = $post->toApiArray($currentUser);
        $data['comments'] = $post->comments
            ->map(fn (Comment $comment): array => $comment->toApiArray())
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil detail laporan',
            'data' => $data,
        ]);
    }

    /**
     * Update the specified post data or status (open / selesai / resolved).
     */
    public function update(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($post->user_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah laporan ini',
            ], 403);
        }

        $validated = $request->validate([
            'type' => ['sometimes', 'required', 'string', 'in:kehilangan,ditemukan'],
            'status' => ['sometimes', 'required', 'string', 'in:open,selesai,resolved'],
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'category' => ['sometimes', 'required', 'string', 'max:80'],
            'location' => ['sometimes', 'required', 'string', 'max:120'],
            'incident_date' => ['sometimes', 'required', 'date'],
            'description' => ['sometimes', 'required', 'string'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'image' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $previousStatus = $post->status;

        $post->fill(collect($validated)->only([
            'type',
            'status',
            'title',
            'category',
            'location',
            'incident_date',
            'description',
        ])->all());
        $post->save();

        if ($request->hasFile('images') || $request->hasFile('image')) {
            $this->deletePostImagesFromStorage($post);
            $post->images()->delete();
            $this->storeUploadedImages($request, $post);
        }

        $this->notifyPostUpdate($post, $user, $previousStatus);

        $post->load(['user', 'images'])->loadCount('comments');

        return response()->json([
            'success' => true,
            'message' => 'Laporan berhasil diperbarui',
            'data' => $post->toApiArray($user),
        ]);
    }

    /**
     * Remove the specified post from storage.
     */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($post->user_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus laporan ini',
            ], 403);
        }

        $this->deletePostImagesFromStorage($post);
        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Laporan berhasil dihapus',
            'data' => null,
        ]);
    }

    /**
     * Apply search, filter, and sorting query parameters to the Post query.
     *
     * @param  Builder<Post>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            if ($search !== '') {
                $query->where(function (Builder $subQuery) use ($search): void {
                    $subQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            }
        }

        if ($request->filled('type')) {
            $type = strtolower($request->string('type')->trim()->toString());
            if ($type !== '' && $type !== 'semua') {
                $query->where('type', $type);
            }
        }

        if ($request->filled('category')) {
            $category = $request->string('category')->trim()->toString();
            if ($category !== '' && strtolower($category) !== 'semua kategori' && strtolower($category) !== 'semua') {
                $query->where('category', $category);
            }
        }

        if ($request->filled('location')) {
            $location = $request->string('location')->trim()->toString();
            if ($location !== '' && strtolower($location) !== 'semua') {
                $query->where('location', 'like', "%{$location}%");
            }
        }

        if ($request->filled('status')) {
            $status = strtolower($request->string('status')->trim()->toString());
            if (in_array($status, ['selesai', 'resolved'], true)) {
                $query->whereIn('status', ['selesai', 'resolved']);
            } elseif ($status !== '' && $status !== 'semua') {
                $query->where('status', $status);
            }
        }

        if ($request->input('sort') === 'oldest') {
            $query->orderBy('created_at', 'asc')->orderBy('id', 'asc');
        } else {
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
        }
    }

    /**
     * Store uploaded images (`images[]` or single `image`) for a post.
     */
    private function storeUploadedImages(Request $request, Post $post): void
    {
        /** @var array<int, UploadedFile> $files */
        $files = [];

        if ($request->hasFile('images')) {
            $uploadedImages = $request->file('images');
            $files = is_array($uploadedImages) ? $uploadedImages : [$uploadedImages];
        } elseif ($request->hasFile('image')) {
            $singleImage = $request->file('image');
            if ($singleImage instanceof UploadedFile) {
                $files = [$singleImage];
            }
        }

        foreach (array_values($files) as $index => $file) {
            $storedPath = $file->store('posts', 'public');
            $post->images()->create([
                'image_url' => Storage::url($storedPath),
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Delete physical image files associated with a post from public disk.
     */
    private function deletePostImagesFromStorage(Post $post): void
    {
        $post->loadMissing('images');

        foreach ($post->images as $image) {
            if (str_starts_with($image->image_url, '/storage/')) {
                $relativePath = substr($image->image_url, strlen('/storage/'));
                Storage::disk('public')->delete($relativePath);
            }
        }
    }

    /**
     * Get IDs of posts saved by the given user among the provided post IDs.
     *
     * @param  array<int, int>  $postIds
     * @return array<int, int>
     */
    private function getSavedPostIds(?User $user, array $postIds): array
    {
        if ($user === null || $postIds === []) {
            return [];
        }

        return SavedPost::query()
            ->where('user_id', $user->id)
            ->whereIn('post_id', $postIds)
            ->pluck('post_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Create notifications when a post or its status is updated.
     */
    private function notifyPostUpdate(Post $post, User $actor, string $previousStatus): void
    {
        $statusChanged = $previousStatus !== $post->status;
        $isResolved = in_array($post->status, ['selesai', 'resolved'], true);
        $statusLabel = $isResolved ? 'selesai' : $post->status;

        $ownerMessage = $statusChanged
            ? ($actor->id === $post->user_id
                ? "Status laporan Anda \"{$post->title}\" telah diubah menjadi {$statusLabel}."
                : "{$actor->name} mengubah status laporan Anda \"{$post->title}\" menjadi {$statusLabel}.")
            : ($actor->id === $post->user_id
                ? "Laporan Anda \"{$post->title}\" berhasil diperbarui."
                : "{$actor->name} memperbarui laporan Anda \"{$post->title}\".");

        Notification::create([
            'user_id' => $post->user_id,
            'actor_id' => $actor->id,
            'post_id' => $post->id,
            'type' => $statusChanged ? 'status_update' : 'post_updated',
            'message' => $ownerMessage,
            'is_read' => false,
        ]);

        if ($statusChanged) {
            $commenterIds = Comment::query()
                ->where('post_id', $post->id)
                ->pluck('user_id')
                ->all();

            $bookmarkerIds = SavedPost::query()
                ->where('post_id', $post->id)
                ->pluck('user_id')
                ->all();

            $interestedUserIds = array_unique(array_merge($commenterIds, $bookmarkerIds));

            foreach ($interestedUserIds as $recipientId) {
                $recipientId = (int) $recipientId;
                if ($recipientId === $post->user_id || $recipientId === $actor->id) {
                    continue;
                }

                Notification::create([
                    'user_id' => $recipientId,
                    'actor_id' => $actor->id,
                    'post_id' => $post->id,
                    'type' => 'status_update',
                    'message' => "Laporan \"{$post->title}\" yang Anda ikuti kini berstatus {$statusLabel}.",
                    'is_read' => false,
                ]);
            }
        }
    }
}
