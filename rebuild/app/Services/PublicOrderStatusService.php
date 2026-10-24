<?php

namespace App\Services;

final class PublicOrderStatusService
{
    public function value(string $status): string
    {
        return match (strtoupper($status)) {
            'PENDING_PAYMENT' => 'PENDING_PAYMENT',
            'PAID', 'PROCESSING' => 'PROCESSING',
            'SUCCESS' => 'SUCCESS',
            'EXPIRED' => 'EXPIRED',
            'CANCELLED' => 'CANCELLED',
            default => 'FAILED',
        };
    }

    public function slug(string $status): string
    {
        return match ($this->value($status)) {
            'PENDING_PAYMENT' => 'pending',
            'PROCESSING' => 'processing',
            'SUCCESS' => 'success',
            'EXPIRED' => 'expired',
            'CANCELLED' => 'cancelled',
            default => 'failed',
        };
    }

    public function label(string $status): string
    {
        return match ($this->value($status)) {
            'PENDING_PAYMENT' => 'Menunggu Pembayaran',
            'PROCESSING' => 'Sedang Diproses',
            'SUCCESS' => 'Berhasil',
            'EXPIRED' => 'Kedaluwarsa',
            'CANCELLED' => 'Dibatalkan',
            default => 'Gagal',
        };
    }
}
