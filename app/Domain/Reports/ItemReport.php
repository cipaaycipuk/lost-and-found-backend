<?php

namespace App\Domain\Reports;

use App\Models\Post;
use App\Models\User;
use InvalidArgumentException;

/**
 * Abstract base class representing an item report (PBO / OOP hierarchy).
 */
abstract class ItemReport
{
    public function __construct(
        protected readonly Post $post,
    ) {}

    /**
     * Factory method to instantiate the concrete ItemReport subclass for a Post.
     */
    public static function fromPost(Post $post): self
    {
        return match ($post->type) {
            'kehilangan' => new LostItem($post),
            'ditemukan' => new FoundItem($post),
            default => throw new InvalidArgumentException("Tipe laporan tidak dikenal: {$post->type}"),
        };
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    abstract public function getType(): string;

    abstract public function getTypeLabel(): string;

    abstract public function getStatusLabel(): string;

    abstract public function getClaimActionLabel(): string;

    abstract public function getClaimNotificationMessage(User $claimer): string;

    /**
     * Check whether the report is still open for claims.
     */
    public function isOpen(): bool
    {
        return $this->post->status === 'open';
    }

    /**
     * Common metadata array derived from OOP polymorphism.
     *
     * @return array{type: string, type_label: string, status_label: string, claim_action_label: string, is_open: bool}
     */
    public function toMetadata(): array
    {
        return [
            'type' => $this->getType(),
            'type_label' => $this->getTypeLabel(),
            'status_label' => $this->getStatusLabel(),
            'claim_action_label' => $this->getClaimActionLabel(),
            'is_open' => $this->isOpen(),
        ];
    }
}
