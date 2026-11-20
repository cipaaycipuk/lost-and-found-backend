<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'type',
    'status',
    'title',
    'category',
    'location',
    'incident_date',
    'description',
])]
class Post extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'incident_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<PostImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(PostImage::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<SavedPost, $this>
     */
    public function savedPosts(): HasMany
    {
        return $this->hasMany(SavedPost::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_posts')
            ->withTimestamps();
    }

    /**
     * Transform the post model into the BACKEND_SPEC.md API payload format.
     *
     * @param  array<int, int>|null  $savedPostIds
     * @return array<string, mixed>
     */
    public function toApiArray(?User $currentUser = null, ?array $savedPostIds = null): array
    {
        $isSaved = false;

        if ($savedPostIds !== null) {
            $isSaved = in_array($this->id, $savedPostIds, true);
        } elseif (isset($this->is_saved)) {
            $isSaved = (bool) $this->is_saved;
        } elseif ($currentUser !== null) {
            if ($this->relationLoaded('savedPosts')) {
                $isSaved = $this->savedPosts->contains('user_id', $currentUser->id);
            } else {
                $isSaved = $this->savedPosts()->where('user_id', $currentUser->id)->exists();
            }
        }

        $images = $this->relationLoaded('images')
            ? $this->images->pluck('image_url')->values()->all()
            : $this->images()->pluck('image_url')->values()->all();

        $author = $this->relationLoaded('user') ? $this->user : $this->user()->first();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'title' => $this->title,
            'category' => $this->category,
            'location' => $this->location,
            'incident_date' => $this->incident_date?->format('Y-m-d'),
            'description' => $this->description,
            'image' => $images[0] ?? null,
            'images' => $images,
            'comments_count' => $this->comments_count ?? $this->comments()->count(),
            'is_saved' => $isSaved,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'author' => $author ? [
                'id' => $author->id,
                'name' => $author->name,
                'email' => $author->email,
                'whatsapp' => $author->whatsapp,
                'avatar' => $author->avatar_url,
                'avatar_url' => $author->avatar_url,
            ] : null,
        ];
    }
}
