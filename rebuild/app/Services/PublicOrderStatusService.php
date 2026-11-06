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
            default => 'FAILED',
        };
    }

    public function slug(string $status): string
    {
        return match ($this->value($status)) {
            'PENDING_PAYMENT' => 'pending',
            'PROCESSING' => 'processing',
            'SUCCESS' => 'success',
            default => 'failed',
        };
    }

    public function label(string $status): string
    {
        // Four canonical customer labels; legacy presentation overrides
        // cannot silently resurrect old status buckets.
        return match ($this->value($status)) {
            'PENDING_PAYMENT' => 'Menunggu Pembayaran',
            'PROCESSING' => 'Diproses',
            'SUCCESS' => 'Terkirim',
            default => 'Gagal',
        };
    }

    public function eventLabel(string $eventType, mixed $toStatus): string
    {
        if (is_string($toStatus) && $toStatus !== '') {
            return $this->label($toStatus);
        }

        return match (strtoupper($eventType)) {
            'ORDER_CREATED' => 'Pesanan dibuat',
            default => 'Status pesanan diperbarui',
        };
    }

    public function note(string $status, bool $wasPaid): ?string
    {
        return ! $wasPaid && in_array(strtoupper($status), ['EXPIRED', 'CANCELLED'], true)
            ? 'Kedaluwarsa'
            : null;
    }
}
