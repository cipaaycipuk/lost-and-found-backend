<?php

namespace App\Domain\Reports;

use App\Models\User;

class LostItem extends ItemReport
{
    public function getType(): string
    {
        return 'kehilangan';
    }

    public function getTypeLabel(): string
    {
        return 'Kehilangan';
    }

    public function getStatusLabel(): string
    {
        return $this->isOpen() ? 'Masih dicari' : 'Sudah ditemukan';
    }

    public function getClaimActionLabel(): string
    {
        return 'Saya Menemukan Ini';
    }

    public function getClaimNotificationMessage(User $claimer): string
    {
        return "{$claimer->name} melaporkan telah menemukan barang \"{$this->post->title}\".";
    }
}
