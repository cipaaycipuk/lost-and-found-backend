<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'actor_id',
    'post_id',
    'type',
    'message',
    'is_read',
])]
class Notification extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Format notification for frontend and BACKEND_SPEC.md compatibility.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'actor_id' => $this->actor_id,
            'post_id' => $this->post_id,
            'postId' => $this->post_id,
            'type' => $this->type,
            'message' => $this->message,
            'is_read' => (bool) $this->is_read,
            'isRead' => (bool) $this->is_read,
            'timeAgo' => $this->created_at?->locale('id')->diffForHumans() ?? 'Baru saja',
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
