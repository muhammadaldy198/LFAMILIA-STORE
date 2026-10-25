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
        $code = $this->value($status);
        $default = match ($code) {
            'PENDING_PAYMENT' => 'Menunggu Pembayaran',
            'PROCESSING' => 'Sedang Diproses',
            'SUCCESS' => 'Berhasil',
            'EXPIRED' => 'Kedaluwarsa',
            'CANCELLED' => 'Dibatalkan',
            default => 'Gagal',
        };

        $saved = app(CustomerPresentationService::class)->saved();
        $custom = $saved['text']['order.status.'.$code.'.label'] ?? null;

        return is_string($custom) && trim($custom) !== '' ? $custom : $default;
    }
}
