<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'post_id',
    'claimer_id',
    'owner_id',
    'message',
    'contact_whatsapp',
    'status',
    'owner_note',
    'responded_at',
])]
class Claim extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Transform the claim model into API array format.
     * Contact details (whatsapp/email) are revealed to claimer/owner only after acceptance (or to owner/admin).
     *
     * @return array<string, mixed>
     */
    public function toApiArray(?User $viewer = null): array
    {
        $post = $this->relationLoaded('post') ? $this->post : null;
        $claimer = $this->relationLoaded('claimer') ? $this->claimer : null;
        $owner = $this->relationLoaded('owner') ? $this->owner : null;

        $isAccepted = $this->status === 'accepted';
        $isAdmin = $viewer !== null && $viewer->role === 'admin';
        $isOwnerViewer = $viewer !== null && $viewer->id === $this->owner_id;
        $isClaimerViewer = $viewer !== null && $viewer->id === $this->claimer_id;

        $canSeeOwnerContact = $isAccepted || $isOwnerViewer || $isAdmin;
        $canSeeClaimerContact = $isAccepted || $isClaimerViewer || $isOwnerViewer || $isAdmin;

        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'claimer_id' => $this->claimer_id,
            'owner_id' => $this->owner_id,
            'message' => $this->message,
            'contact_whatsapp' => $canSeeClaimerContact ? $this->contact_whatsapp : null,
            'status' => $this->status,
            'owner_note' => $this->owner_note,
            'responded_at' => $this->responded_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'post' => $post ? [
                'id' => $post->id,
                'title' => $post->title,
                'type' => $post->type,
                'status' => $post->status,
                'location' => $post->location,
                'image' => $post->relationLoaded('images') ? ($post->images->first()?->image_url) : null,
            ] : null,
            'claimer' => $claimer ? [
                'id' => $claimer->id,
                'name' => $claimer->name,
                'avatar' => $claimer->avatar_url,
                'avatar_url' => $claimer->avatar_url,
                'whatsapp' => $canSeeClaimerContact ? ($this->contact_whatsapp ?: $claimer->whatsapp) : null,
            ] : null,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'avatar' => $owner->avatar_url,
                'avatar_url' => $owner->avatar_url,
                'whatsapp' => $canSeeOwnerContact ? $owner->whatsapp : null,
            ] : null,
        ];
    }
}
