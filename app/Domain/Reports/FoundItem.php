<?php

namespace App\Domain\Reports;

use App\Models\User;

class FoundItem extends ItemReport
{
    public function getType(): string
    {
        return 'ditemukan';
    }

    public function getTypeLabel(): string
    {
        return 'Ditemukan';
    }

    public function getStatusLabel(): string
    {
        return $this->isOpen() ? 'Menunggu pemilik' : 'Sudah dikembalikan';
    }

    public function getClaimActionLabel(): string
    {
        return 'Ajukan Klaim';
    }

    public function getClaimNotificationMessage(User $claimer): string
    {
        return "{$claimer->name} mengajukan klaim kepemilikan untuk barang \"{$this->post->title}\".";
    }
}
