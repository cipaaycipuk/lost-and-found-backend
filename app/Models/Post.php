<?php

namespace App\Models;

use App\Domain\Reports\ItemReport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use HasFactory, SoftDeletes;

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
     * Instantiate the OOP ItemReport domain object (LostItem or FoundItem).
     */
    public function asReport(): ItemReport
    {
        return ItemReport::fromPost($this);
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
     * @return HasMany<Claim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
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
     * Transform the post model into the API payload format.
     *
     * @param  array<int, int>|null  $savedPostIds
     * @param  array<int, array<string, mixed>>|null  $myClaimsByPostId
     * @return array<string, mixed>
     */
    public function toApiArray(
        ?User $currentUser = null,
        ?array $savedPostIds = null,
        ?array $myClaimsByPostId = null,
    ): array {
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

        $canSeeAuthorContact = $author !== null
            && $currentUser !== null
            && ($currentUser->id === $author->id || $currentUser->role === 'admin');

        $myClaim = $myClaimsByPostId[$this->id] ?? null;
        if ($myClaim === null && $currentUser !== null && $this->relationLoaded('claims')) {
            $matchedClaim = $this->claims->firstWhere('claimer_id', $currentUser->id);
            if ($matchedClaim !== null) {
                $myClaim = [
                    'id' => $matchedClaim->id,
                    'status' => $matchedClaim->status,
                    'owner_whatsapp' => $matchedClaim->status === 'accepted' ? ($author?->whatsapp) : null,
                ];
            }
        }

        $reportMeta = $this->asReport()->toMetadata();

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
            'comments_count' => $this->comments_count ?? 0,
            'claims_count' => $this->claims_count ?? 0,
            'is_saved' => $isSaved,
            'my_claim' => $myClaim,
            'report_meta' => $reportMeta,
            'deleted_at' => $this->deleted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'author' => $author ? [
                'id' => $author->id,
                'name' => $author->name,
                'email' => $canSeeAuthorContact ? $author->email : null,
                'whatsapp' => $canSeeAuthorContact ? $author->whatsapp : null,
                'avatar' => $author->avatar_url,
                'avatar_url' => $author->avatar_url,
            ] : null,
        ];
    }
}
