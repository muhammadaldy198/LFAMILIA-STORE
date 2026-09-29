<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PaymentPresentationService
{
    /**
     * @return array<string, mixed>|null
     */
    public function forOrder(int $orderId): ?array
    {
        $payment = DB::table('payment_transactions as payments')
            ->leftJoin('payment_channels as channels', 'channels.code', '=', 'payments.channel_code')
            ->where('payments.order_id', $orderId)
            ->orderByDesc('payments.id')
            ->select(
                'payments.id',
                'payments.status',
                'payments.channel_code',
                'payments.amount_idr',
                'payments.public_payload',
                'payments.expires_at',
                'channels.name as channel_name'
            )->first();

        if (! $payment) {
            return null;
        }

        $instructions = is_string($payment->public_payload)
            ? (json_decode($payment->public_payload, true) ?: [])
            : ((array) ($payment->public_payload ?? []));

        return [
            'id' => (int) $payment->id,
            'status' => (string) $payment->status,
            'channel_code' => (string) $payment->channel_code,
            'channel_name' => $payment->channel_name ?: (string) $payment->channel_code,
            'amount_idr' => (int) $payment->amount_idr,
            'expires_at' => $payment->expires_at,
            'instructions' => $instructions,
        ];
    }
}
