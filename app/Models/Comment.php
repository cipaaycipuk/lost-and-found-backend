<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['post_id', 'user_id', 'parent_id', 'content'])]
class Comment extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
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
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    /**
     * Transform the comment model into API array format.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $author = $this->relationLoaded('user') ? $this->user : $this->user()->first();

        $data = [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'user_id' => $this->user_id,
            'parent_id' => $this->parent_id,
            'content' => $this->content,
            'text' => $this->content,
            'user' => $author?->name,
            'avatar' => $author?->avatar_url,
            'isReply' => $this->parent_id !== null,
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

        if ($this->relationLoaded('replies')) {
            $data['replies'] = $this->replies
                ->map(fn (self $reply): array => $reply->toApiArray())
                ->values()
                ->all();
        }

        return $data;
    }
}
