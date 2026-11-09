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
        // Milestone events carry their own customer wording; the status
        // bucket alone would call a verified payment "Diproses".
        $eventLabel = match (strtoupper($eventType)) {
            'PAYMENT_VERIFIED' => 'Pembayaran Berhasil',
            'FULFILLMENT_PENDING' => 'Menunggu hasil dari penyedia',
            default => null,
        };
        if ($eventLabel !== null) {
            return $eventLabel;
        }

        if (is_string($toStatus) && $toStatus !== '') {
            return $this->label($toStatus);
        }

        return match (strtoupper($eventType)) {
            'ORDER_CREATED' => 'Pesanan dibuat',
            default => 'Status pesanan diperbarui',
        };
    }

    /**
     * Drop adjacent timeline rows that repeat the same public state.
     * Provider heartbeats can fire many times without a status change;
     * customers should see each distinct public state once, in order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function collapseAdjacent(array $rows, string $statusKey): array
    {
        $collapsed = [];
        $previousKey = null;
        foreach ($rows as $row) {
            $key = (string) ($row['source'] ?? '').'|'.(string) ($row['label'] ?? '').'|'.(string) ($row[$statusKey] ?? '');
            if ($key === $previousKey) {
                continue;
            }
            $collapsed[] = $row;
            $previousKey = $key;
        }

        return $collapsed;
    }

    public function note(string $status, bool $wasPaid): ?string
    {
        return ! $wasPaid && in_array(strtoupper($status), ['EXPIRED', 'CANCELLED'], true)
            ? 'Kedaluwarsa'
            : null;
    }
}
