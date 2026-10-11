<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminOrderCancellationService
{
    public function cancel(int $orderId, int $adminId, string $reason): void
    {
        DB::transaction(function () use ($orderId, $adminId, $reason): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || ! in_array($order->status, ['PAID', 'FAILED', 'BLOCKED'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Pesanan tidak dapat dibatalkan pada status sekarang.',
                ]);
            }
            $attempts = DB::table('fulfillment_attempts')->where('order_id', $orderId)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($attempts as $attempt) {
                if (! in_array($attempt->status, ['FAILED_CONFIRMED', 'MANUAL_FAILED', 'BLOCKED'], true)) {
                    throw ValidationException::withMessages([
                        'order' => 'Pengiriman masih berjalan atau belum pasti. Pastikan hasil provider sebelum membatalkan.',
                    ]);
                }
            }
            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'CANCELLED',
                'updated_at' => now(),
            ]);
            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'event_type' => 'ADMIN_ORDER_CANCELLED',
                'from_status' => $order->status,
                'to_status' => 'CANCELLED',
                'correlation_id' => (string) Str::uuid(),
                'metadata' => json_encode([
                    'admin_id' => $adminId, 'reason' => $reason, 'refund' => 'MANUAL_ONLY',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            // Never initiate gateway refunds or wallet balance changes.
        }, 3);
    }
}
