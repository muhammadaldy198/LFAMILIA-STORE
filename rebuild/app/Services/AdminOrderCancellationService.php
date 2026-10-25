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
            // Tutup percobaan yang masih menggantung supaya pesanan batal tidak
            // lagi tampil sebagai "perlu tindakan" atau masuk antrean pemenuhan.
            // Percobaan SUCCESS tidak pernah disentuh (pesanan sukses tidak bisa dibatalkan).
            $closed = [];
            foreach ($attempts as $attempt) {
                if (in_array($attempt->status, ['SUCCESS', 'CANCELLED'], true)) {
                    continue;
                }
                DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                    'status' => 'CANCELLED',
                    'last_error' => substr('Pesanan dibatalkan: '.$reason, 0, 4000),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
                $closed[] = (int) $attempt->id;
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
            if ($closed !== []) {
                DB::table('order_events')->insert([
                    'order_id' => $orderId,
                    'event_type' => 'FULFILLMENT_CLOSED_BY_CANCELLATION',
                    'from_status' => $order->status,
                    'to_status' => 'CANCELLED',
                    'correlation_id' => (string) Str::uuid(),
                    'metadata' => json_encode([
                        'attempt_ids' => $closed, 'reason' => $reason,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
            // Kuota voucher kembali ke pelanggan: pesanan batal tidak boleh
            // menghabiskan jatah voucher (termasuk yang sudah REDEEMED).
            $voucherReleased = DB::table('voucher_redemptions')->where('order_id', $orderId)
                ->where('status', '!=', 'RELEASED')
                ->update([
                    'status' => 'RELEASED',
                    'reserved_until' => now(),
                    'updated_at' => now(),
                ]);
            if ($voucherReleased > 0) {
                DB::table('order_events')->insert([
                    'order_id' => $orderId,
                    'event_type' => 'VOUCHER_QUOTA_RELEASED',
                    'from_status' => 'CANCELLED',
                    'to_status' => 'CANCELLED',
                    'correlation_id' => (string) Str::uuid(),
                    'metadata' => json_encode(['redemptions' => $voucherReleased], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            // Never initiate gateway refunds or wallet balance changes.
            // Kode stok yang belum terkirim kembali ke stok.
            $released = app(VoucherStockService::class)->release($orderId);
            if ($released > 0) {
                DB::table('order_events')->insert([
                    'order_id' => $orderId,
                    'event_type' => 'VOUCHER_STOCK_RELEASED',
                    'from_status' => 'CANCELLED',
                    'to_status' => 'CANCELLED',
                    'correlation_id' => (string) Str::uuid(),
                    'metadata' => json_encode(['codes' => $released], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        }, 3);
    }
}
