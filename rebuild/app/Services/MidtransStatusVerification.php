<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class MidtransStatusVerification
{
    public function status(array $payload): string
    {
        $status = strtolower((string) $payload['transaction_status']);
        $fraud = strtolower((string) ($payload['fraud_status'] ?? ''));

        // Midtrans only authorizes delivery after a 200 success code and
        // an ACCEPT fraud verdict, if the provider supplies a verdict.
        // A contradictory status response must not mark the order paid.
        $confirmed = (string) ($payload['status_code'] ?? '') === '200'
            && in_array($fraud, ['', 'accept'], true);

        return match ($status) {
            'settlement', 'capture' => $confirmed ? 'PAID' : 'PENDING',
            'expire' => 'EXPIRED', 'cancel' => 'CANCELLED',
            'deny', 'failure' => 'FAILED',
            'refund' => 'REFUNDED',
            'partial_refund' => 'PARTIAL_REFUND_REVIEW',
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
