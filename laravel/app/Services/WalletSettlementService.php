<?php

namespace App\Services;

use App\Exceptions\WalletSettlementException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletSettlementService
{
    public function settle(string $customerId, string $orderId): int
    {
        return DB::transaction(function () use ($customerId, $orderId): int {
            $order = DB::table('orders')
                ->where('id', $orderId)
                ->where('customer_id', $customerId)
                ->where('payment_method', 'wallet')
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new WalletSettlementException('Pesanan wallet tidak ditemukan.');
            }

            $reference = 'order:'.$orderId;
            $existingBalance = DB::table('wallet_transactions')
                ->where('reference', $reference)
                ->value('balance_after');
            if ($existingBalance !== null) {
                return (int) $existingBalance;
            }

            if ($order->payment_status !== 'pending') {
                throw new WalletSettlementException('Pesanan wallet sudah tidak dapat diproses.');
            }

            $customer = DB::table('customer_users')
                ->where('id', $customerId)
                ->lockForUpdate()
                ->first(['id', 'is_active']);
            if (!$customer || !(bool) $customer->is_active) {
                throw new WalletSettlementException('Akun pelanggan tidak aktif.');
            }

            $this->consumePromotionCapacity($order);

            $balanceBefore = (int) DB::table('wallet_transactions')
                ->where('customer_id', $customerId)
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance")
                ->value('balance');
            $amount = (int) $order->total;

            if ($amount <= 0 || $balanceBefore < $amount) {
                throw new WalletSettlementException('Saldo tidak cukup. Silakan top up saldo terlebih dahulu.');
            }

            $balanceAfter = $balanceBefore - $amount;
            DB::table('wallet_transactions')->insert([
                'id' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
                'description' => $order->product_name.' • '.$order->package_label.' × '.max(1, (int) $order->quantity),
                'created_at' => now(),
            ]);

            DB::table('customer_users')->where('id', $customerId)->update([
                'balance' => $balanceAfter,
                'updated_at' => now(),
            ]);

            DB::table('orders')->where('id', $orderId)->update([
                'payment_status' => 'paid',
                'fulfillment_status' => $order->fulfillment_type === 'manual' ? 'manual_pending' : 'processing',
                'updated_at' => now(),
            ]);

            DB::table('order_events')->insertOrIgnore([
                'order_id' => $orderId,
                'source' => 'wallet',
                'event_id' => 'wallet-'.$orderId,
                'status' => 'paid',
                'payload_json' => json_encode(['amount' => $amount], JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);

            return $balanceAfter;
        }, 3);
    }

    private function consumePromotionCapacity(object $order): void
    {
        $now = CarbonImmutable::now();

        if ($order->voucher_code) {
            $voucher = DB::table('discount_vouchers')
                ->where('code', $order->voucher_code)
                ->lockForUpdate()
                ->first();
            if (!$voucher
                || !(bool) $voucher->is_active
                || CarbonImmutable::parse((string) $voucher->starts_at)->greaterThan($now)
                || CarbonImmutable::parse((string) $voucher->ends_at)->lessThan($now)
                || ($voucher->usage_limit !== null
                    && (int) $voucher->used_count + (int) $voucher->reserved_count >= (int) $voucher->usage_limit)) {
                throw new WalletSettlementException('Voucher baru saja habis atau tidak lagi tersedia.');
            }

            DB::table('discount_vouchers')->where('id', $voucher->id)->update([
                'used_count' => DB::raw('used_count + 1'),
                'updated_at' => now(),
            ]);
        }

        if ($order->flash_sale_id) {
            $flash = DB::table('flash_sales')
                ->where('id', $order->flash_sale_id)
                ->lockForUpdate()
                ->first();
            if (!$flash
                || !(bool) $flash->is_active
                || CarbonImmutable::parse((string) $flash->starts_at)->greaterThan($now)
                || CarbonImmutable::parse((string) $flash->ends_at)->lessThan($now)
                || ($flash->stock_limit !== null
                    && (int) $flash->sold_count + (int) $flash->reserved_count >= (int) $flash->stock_limit)) {
                throw new WalletSettlementException('Flash sale baru saja habis atau tidak lagi tersedia.');
            }

            DB::table('flash_sales')->where('id', $flash->id)->update([
                'sold_count' => DB::raw('sold_count + 1'),
                'updated_at' => now(),
            ]);
        }
    }
}
