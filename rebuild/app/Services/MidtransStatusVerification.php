<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class MidtransStatusVerification
{
    public function status(array $payload): string
    {
        $status = strtolower((string) $payload['transaction_status']);
        $fraud = strtolower((string) ($payload['fraud_status'] ?? ''));
        return match ($status) {
            'settlement' => 'PAID',
            'capture' => in_array($fraud, ['', 'accept'], true) ? 'PAID' : 'PENDING',
            'expire' => 'EXPIRED', 'cancel' => 'CANCELLED',
            'deny', 'failure' => 'FAILED',
            'refund', 'partial_refund' => 'REFUNDED',
            default => 'PENDING',
        };
    }

    public function amount(mixed $value): int
    {
        $string = is_int($value) ? (string) $value : trim((string) $value);
        if (! preg_match('/^(\\d+)(?:\\.0+)?$/', $string, $matches)) {
            throw ValidationException::withMessages(['payment' => 'Nominal pembayaran tidak dapat diverifikasi.']);
        }
        return (int) $matches[1];
    }
}
