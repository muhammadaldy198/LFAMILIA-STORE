<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentStateService
{
    /**
     * @return array<string, mixed>
     */
    public function apply(int $paymentTransactionId, string $incomingStatus, array $metadata = []): array
    {
        return DB::transaction(function () use ($paymentTransactionId, $incomingStatus, $metadata): array {
            $payment = DB::table('payment_transactions')->where('id', $paymentTransactionId)
                ->lockForUpdate()->first();
            if (! $payment) {
                throw ValidationException::withMessages(['payment' => 'Transaksi pembayaran tidak ditemukan.']);
            }

            $incomingStatus = strtoupper($incomingStatus);
            if (! in_array($incomingStatus, ['PENDING', 'PAID', 'FAILED', 'EXPIRED', 'REFUNDED'], true)) {
                throw ValidationException::withMessages(['payment' => 'Status pembayaran tidak valid.']);
            }

            if ($payment->status === 'REFUNDED') {
                return ['result' => 'IGNORED_FINAL', 'status' => 'REFUNDED'];
            }
            if ($payment->status === 'PAID' && $incomingStatus !== 'REFUNDED') {
                return ['result' => 'IGNORED_FINAL', 'status' => 'PAID'];
            }
            if (in_array($payment->status, ['FAILED', 'EXPIRED'], true) && $incomingStatus === 'PENDING') {
                return ['result' => 'IGNORED_STALE', 'status' => (string) $payment->status];
            }

            if ($payment->order_id !== null) {
                return $this->applyOrder($payment, $incomingStatus, $metadata);
            }

            return $this->applyTopup($payment, $incomingStatus);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function payOrderWithWallet(int $paymentTransactionId, int $userId): array
    {
        return DB::transaction(function () use ($paymentTransactionId, $userId): array {
            $payment = DB::table('payment_transactions')->where('id', $paymentTransactionId)
                ->lockForUpdate()->first();
            if (! $payment || $payment->order_id === null || $payment->gateway_code !== 'WALLET') {
                throw ValidationException::withMessages(['payment' => 'Pembayaran saldo tidak valid.']);
            }
            if ($payment->status === 'PAID') {
                return ['result' => 'ALREADY_PAID', 'status' => 'PAID'];
            }

            $order = DB::table('orders')->where('id', $payment->order_id)->lockForUpdate()->first();
            if (! $order || (int) $order->user_id !== $userId || $order->status !== 'PENDING_PAYMENT') {
                throw ValidationException::withMessages(['payment' => 'Pesanan tidak dapat dibayar dengan saldo.']);
            }
            if ($order->expires_at && now()->greaterThanOrEqualTo($order->expires_at)) {
                $this->expireOrder($order);

                throw ValidationException::withMessages(['payment' => 'Pesanan sudah kedaluwarsa.']);
            }
            if ((int) $order->total_idr !== (int) $payment->amount_idr) {
                throw ValidationException::withMessages(['payment' => 'Nominal pembayaran tidak cocok.']);
            }

            $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $wallet || (int) $wallet->balance_idr < (int) $payment->amount_idr) {
                throw ValidationException::withMessages(['payment' => 'Saldo LFAMILIA tidak mencukupi.']);
            }

            $before = (int) $wallet->balance_idr;
            $after = $before - (int) $payment->amount_idr;
            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance_idr' => $after,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            DB::table('wallet_ledger')->insertOrIgnore([
                'wallet_id' => $wallet->id,
                'amount_idr' => -((int) $payment->amount_idr),
                'balance_before_idr' => $before,
                'balance_after_idr' => $after,
                'source' => 'CHECKOUT',
                'reference_type' => 'ORDER',
                'reference_id' => (string) $order->id,
                'actor_type' => 'user',
                'actor_id' => (string) $userId,
                'idempotency_key' => 'wallet-order-payment:'.$payment->id,
                'created_at' => now(),
            ]);

            return $this->markOrderPaid($payment, $order, ['source' => 'wallet']);
        }, 3);
    }

    private function applyOrder(object $payment, string $incomingStatus, array $metadata): array
    {
        $order = DB::table('orders')->where('id', $payment->order_id)->lockForUpdate()->first();
        if (! $order) {
            throw ValidationException::withMessages(['payment' => 'Pesanan pembayaran tidak ditemukan.']);
        }
        if ((int) $order->total_idr !== (int) $payment->amount_idr) {
            throw ValidationException::withMessages(['payment' => 'Nominal callback tidak cocok.']);
        }

        if ($incomingStatus === 'PAID') {
            if (in_array($order->status, ['EXPIRED', 'FAILED'], true)) {
                DB::table('payment_transactions')->where('id', $payment->id)->update([
                    'status' => 'PAID',
                    'verified_at' => now(),
                    'last_callback_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->orderEvent($order->id, 'PAYMENT_LATE_VERIFIED', $order->status, $order->status, $metadata);

                return ['result' => 'LATE_PAID_REVIEW', 'status' => $order->status];
            }

            return $this->markOrderPaid($payment, $order, $metadata);
        }

        if ($incomingStatus === 'REFUNDED') {
            DB::table('payment_transactions')->where('id', $payment->id)->update([
                'status' => 'REFUNDED',
                'last_callback_at' => now(),
                'updated_at' => now(),
            ]);

            if (in_array($order->status, ['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'SUCCESS'], true)) {
                $before = $order->status;
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'REFUND',
                    'updated_at' => now(),
                ]);
                if ($before === 'PENDING_PAYMENT') {
                    $this->releaseVoucher((int) $order->id);
                }
                $this->orderEvent($order->id, 'PAYMENT_REFUNDED', $before, 'REFUND', $metadata);

                return ['result' => 'REFUNDED', 'status' => 'REFUND'];
            }

            return ['result' => 'REFUNDED', 'status' => $order->status];
        }

        DB::table('payment_transactions')->where('id', $payment->id)->update([
            'status' => $incomingStatus,
            'last_callback_at' => now(),
            'updated_at' => now(),
        ]);

        if ($incomingStatus === 'EXPIRED' && $order->status === 'PENDING_PAYMENT') {
            $this->expireOrder($order);

            return ['result' => 'EXPIRED', 'status' => 'EXPIRED'];
        }

        if ($incomingStatus === 'FAILED' && $order->status === 'PENDING_PAYMENT') {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'FAILED',
                'updated_at' => now(),
            ]);
            $this->releaseVoucher((int) $order->id);
            $this->orderEvent($order->id, 'PAYMENT_FAILED', 'PENDING_PAYMENT', 'FAILED', $metadata);

            return ['result' => 'FAILED', 'status' => 'FAILED'];
        }

        return ['result' => 'PAYMENT_UPDATED', 'status' => $incomingStatus];
    }

    private function applyTopup(object $payment, string $incomingStatus): array
    {
        $topup = DB::table('wallet_topups')->where('id', $payment->wallet_topup_id)
            ->lockForUpdate()->first();
        if (! $topup) {
            throw ValidationException::withMessages(['payment' => 'Top up tidak ditemukan.']);
        }
        if ((int) $topup->total_idr !== (int) $payment->amount_idr) {
            throw ValidationException::withMessages(['payment' => 'Nominal callback tidak cocok.']);
        }

        if ($incomingStatus === 'PAID') {
            if ($topup->status === 'PAID') {
                return ['result' => 'ALREADY_PAID', 'status' => 'PAID'];
            }

            $wallet = DB::table('wallets')->where('id', $topup->wallet_id)->lockForUpdate()->first();
            if (! $wallet) {
                throw ValidationException::withMessages(['payment' => 'Wallet tidak ditemukan.']);
            }
            $before = (int) $wallet->balance_idr;
            $after = $before + (int) $topup->amount_idr;

            $inserted = DB::table('wallet_ledger')->insertOrIgnore([
                'wallet_id' => $wallet->id,
                'amount_idr' => (int) $topup->amount_idr,
                'balance_before_idr' => $before,
                'balance_after_idr' => $after,
                'source' => 'TOPUP',
                'reference_type' => 'WALLET_TOPUP',
                'reference_id' => (string) $topup->id,
                'actor_type' => 'payment_gateway',
                'actor_id' => null,
                'idempotency_key' => 'wallet-topup-credit:'.$payment->id,
                'created_at' => now(),
            ]);

            if ($inserted === 1) {
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance_idr' => $after,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            }

            DB::table('wallet_topups')->where('id', $topup->id)->update([
                'status' => 'PAID',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('payment_transactions')->where('id', $payment->id)->update([
                'status' => 'PAID',
                'verified_at' => now(),
                'last_callback_at' => now(),
                'updated_at' => now(),
            ]);

            return ['result' => 'PAID', 'status' => 'PAID'];
        }

        if ($incomingStatus === 'REFUNDED') {
            DB::table('payment_transactions')->where('id', $payment->id)->update([
                'status' => 'REFUNDED',
                'last_callback_at' => now(),
                'updated_at' => now(),
            ]);

            if ($topup->status !== 'PAID') {
                DB::table('wallet_topups')->where('id', $topup->id)->update([
                    'status' => 'REFUNDED',
                    'updated_at' => now(),
                ]);

                return ['result' => 'REFUNDED', 'status' => 'REFUNDED'];
            }

            $wallet = DB::table('wallets')->where('id', $topup->wallet_id)->lockForUpdate()->first();
            if (! $wallet) {
                throw ValidationException::withMessages(['payment' => 'Wallet tidak ditemukan.']);
            }

            $refundKey = 'wallet-topup-refund:'.$payment->id;
            if (DB::table('wallet_ledger')->where('idempotency_key', $refundKey)->exists()) {
                return ['result' => 'REFUNDED', 'status' => 'REFUNDED'];
            }

            if ((int) $wallet->balance_idr < (int) $topup->amount_idr) {
                DB::table('wallet_topups')->where('id', $topup->id)->update([
                    'status' => 'REFUND_REVIEW',
                    'updated_at' => now(),
                ]);

                return ['result' => 'REFUND_REVIEW', 'status' => 'REFUND_REVIEW'];
            }

            $before = (int) $wallet->balance_idr;
            $after = $before - (int) $topup->amount_idr;
            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance_idr' => $after,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            DB::table('wallet_ledger')->insert([
                'wallet_id' => $wallet->id,
                'amount_idr' => -((int) $topup->amount_idr),
                'balance_before_idr' => $before,
                'balance_after_idr' => $after,
                'source' => 'REFUND',
                'reference_type' => 'WALLET_TOPUP',
                'reference_id' => (string) $topup->id,
                'actor_type' => 'payment_gateway',
                'actor_id' => null,
                'idempotency_key' => $refundKey,
                'created_at' => now(),
            ]);
            DB::table('wallet_topups')->where('id', $topup->id)->update([
                'status' => 'REFUNDED',
                'updated_at' => now(),
            ]);

            return ['result' => 'REFUNDED', 'status' => 'REFUNDED'];
        }

        if (in_array($incomingStatus, ['FAILED', 'EXPIRED'], true)) {
            DB::table('wallet_topups')->where('id', $topup->id)
                ->where('status', 'PENDING_PAYMENT')->update([
                    'status' => $incomingStatus,
                    'updated_at' => now(),
                ]);
        }
        DB::table('payment_transactions')->where('id', $payment->id)->update([
            'status' => $incomingStatus,
            'last_callback_at' => now(),
            'updated_at' => now(),
        ]);

        return ['result' => 'PAYMENT_UPDATED', 'status' => $incomingStatus];
    }

    private function markOrderPaid(object $payment, object $order, array $metadata): array
    {
        if ($order->status === 'REFUND') {
            return ['result' => 'IGNORED_ORDER_STATE', 'status' => 'REFUND'];
        }
        if (in_array($order->status, ['PAID', 'PROCESSING', 'SUCCESS'], true)) {
            DB::table('payment_transactions')->where('id', $payment->id)->update([
                'status' => 'PAID',
                'verified_at' => $payment->verified_at ?: now(),
                'last_callback_at' => now(),
                'updated_at' => now(),
            ]);

            return ['result' => 'ALREADY_PAID', 'status' => $order->status];
        }
        if ($order->status !== 'PENDING_PAYMENT') {
            return ['result' => 'IGNORED_ORDER_STATE', 'status' => $order->status];
        }

        DB::table('payment_transactions')->where('id', $payment->id)->update([
            'status' => 'PAID',
            'verified_at' => now(),
            'last_callback_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'PAID',
            'paid_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('voucher_redemptions')->where('order_id', $order->id)
            ->where('status', 'RESERVED')->update([
                'status' => 'REDEEMED',
                'redeemed_at' => now(),
                'updated_at' => now(),
            ]);
        $this->orderEvent($order->id, 'PAYMENT_VERIFIED', 'PENDING_PAYMENT', 'PAID', $metadata);

        return ['result' => 'PAID', 'status' => 'PAID'];
    }

    public function expireOrder(object $order): void
    {
        if ($order->status !== 'PENDING_PAYMENT') {
            return;
        }

        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'EXPIRED',
            'updated_at' => now(),
        ]);
        $this->releaseVoucher((int) $order->id);
        $this->orderEvent($order->id, 'ORDER_EXPIRED', 'PENDING_PAYMENT', 'EXPIRED', []);
    }

    private function releaseVoucher(int $orderId): void
    {
        DB::table('voucher_redemptions')->where('order_id', $orderId)
            ->where('status', 'RESERVED')->update([
                'status' => 'RELEASED',
                'reserved_until' => now(),
                'updated_at' => now(),
            ]);
    }

    private function orderEvent(int $orderId, string $type, ?string $from, ?string $to, array $metadata): void
    {
        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'correlation_id' => (string) Str::uuid(),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
