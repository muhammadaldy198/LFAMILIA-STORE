<?php

namespace App\Services;

use App\Jobs\StartFulfillmentJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menerapkan keadaan pembayaran PESANAN: status masuk diterapkan ke
 * pesanan, pembayaran lunas ditandai beserta imbasnya (poin
 * membership, email, notifikasi), pembayaran dompet diterapkan, dan
 * pesanan kedaluwarsa ditutup beserta pelepasan vouchernya.
 * Class ini TIDAK menangani topup; keadaan topup ada di
 * TopupPaymentStateService.
 */
class OrderPaymentStateService
{
    public function __construct(
        private readonly AdminNotificationService $notifications,
        private readonly TransactionalEmailService $emails,
        private readonly MembershipService $membership,
    ) {}

    public function applyOrder(object $payment, string $incomingStatus, array $metadata): array
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan penerapan status pesanan — status lama dan baru
        // dibandingkan, lalu imbasnya (lunas, gagal, kedaluwarsa)
        // diterapkan dalam konteks pembayaran yang sama.
        $order = DB::table('orders')->where('id', $payment->order_id)->lockForUpdate()->first();
        if (! $order) {
            throw ValidationException::withMessages(['payment' => 'Pesanan pembayaran tidak ditemukan.']);
        }
        if ((int) $order->total_idr !== (int) $payment->amount_idr) {
            throw ValidationException::withMessages(['payment' => 'Nominal callback tidak cocok.']);
        }

        if ($incomingStatus === 'PAID') {
            if (in_array($order->status, ['EXPIRED', 'FAILED', 'CANCELLED'], true)) {
                DB::table('payment_transactions')->where('id', $payment->id)->update([
                    'status' => 'PAID',
                    'verified_at' => now(),
                    'last_callback_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->orderEvent($order->id, 'PAYMENT_LATE_VERIFIED', $order->status, $order->status, $metadata);
                $this->notifications->record(
                    'payment.late_verified',
                    'Pembayaran terlambat terverifikasi',
                    'Pembayaran pesanan '.$order->order_number.' masuk setelah pesanan berstatus '.(AdminOrderPresentation::STATUSES[$order->status] ?? $order->status).'. Perlu diperiksa.',
                    'WARNING',
                    'order',
                    $order->id
                );

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
            // This is only a gateway status report, not authorization to refund locally.
            $this->orderEvent((int) $order->id, 'PAYMENT_REFUND_REVIEW', $order->status, $order->status, $metadata);
            $this->notifications->record(
                'payment.refund_review',
                'Gerbang pembayaran melaporkan pengembalian dana - perlu pemeriksaan',
                'Pembayaran pesanan '.$order->order_number.' dikembalikan oleh gerbang pembayaran. Hubungi pelanggan dan periksa manual; saldo tidak berubah otomatis.',
                'WARNING',
                'order',
                $order->id
            );

            return ['result' => 'REFUND_REVIEW', 'status' => $order->status];
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

        if (in_array($incomingStatus, ['FAILED', 'CANCELLED'], true) && $order->status === 'PENDING_PAYMENT') {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => $incomingStatus,
                'updated_at' => now(),
            ]);
            $this->releaseVoucher((int) $order->id);
            $eventType = $incomingStatus === 'CANCELLED' ? 'PAYMENT_CANCELLED' : 'PAYMENT_FAILED';
            $this->orderEvent($order->id, $eventType, 'PENDING_PAYMENT', $incomingStatus, $metadata);

            return ['result' => $incomingStatus, 'status' => $incomingStatus];
        }

        return ['result' => 'PAYMENT_UPDATED', 'status' => $incomingStatus];
    }

    private function markOrderPaid(object $payment, object $order, array $metadata): array
    {
        // Alasan belum dipecah: satu rangkaian imbas lunas — status,
        // poin membership, email, dan notifikasi harus terjadi
        // sebagai satu kesatuan hasil; memisahkannya membuat imbas
        // lunas terbaca setengah-setengah.
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
        if ($order->user_id !== null) {
            $user = User::find((int) $order->user_id);
            if ($user) {
                $this->membership->sync($user);
            }
        }
        DB::table('voucher_redemptions')->where('order_id', $order->id)
            ->where('status', 'RESERVED')->update([
                'status' => 'REDEEMED',
                'redeemed_at' => now(),
                'updated_at' => now(),
            ]);
        $this->orderEvent($order->id, 'PAYMENT_VERIFIED', 'PENDING_PAYMENT', 'PAID', $metadata);
        $this->notifications->record(
            'payment.paid',
            'Pembayaran berhasil',
            'Pembayaran pesanan '.$order->order_number.' sudah terverifikasi.',
            'INFO',
            'order',
            $order->id
        );
        $this->emails->queuePaymentConfirmed((int) $order->id);
        StartFulfillmentJob::dispatch((int) $order->id)->afterCommit();

        return ['result' => 'PAID', 'status' => 'PAID'];
    }

    /**
     * @return array<string, mixed>
     */
    public function payOrderWithWallet(int $paymentTransactionId, int $userId): array
    {
        // Alasan belum dipecah: satu urutan bayar dompet yang
        // transaksional — saldo diperiksa dan dipotong, lalu pesanan
        // ditandai lunas beserta imbasnya, dalam konteks pembayaran
        // yang sama dari awal sampai akhir.
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

            // The refund-review freeze is checked only here, under the wallet
            // lock: the refund path in applyTopup takes the same lock before
            // it may commit a REFUND_REVIEW, so a freeze committed before this
            // point is always seen by this read (the transaction's first
            // consistent read) and no freeze can land between it and the
            // debit below. Checking before the lock left exactly that window
            // open.
            if (DB::table('wallet_topups')
                ->where('user_id', $userId)
                ->where('status', 'REFUND_REVIEW')
                ->exists()) {
                throw ValidationException::withMessages([
                    'payment' => 'Saldo sedang dibatasi sementara karena ada pengembalian dana yang perlu diselesaikan.',
                ]);
            }

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
                'amount_idr' => -(int) $payment->amount_idr,
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

        // Kode stok digital yang belum terkirim kembali ke stok.
        $released = app(VoucherStockService::class)->release($orderId);
        if ($released > 0) {
            $this->orderEvent($orderId, 'VOUCHER_STOCK_RELEASED', null, null, ['codes' => $released]);
        }
    }

    private function correlationId(int $orderId): string
    {
        $root = DB::table('order_events')->where('order_id', $orderId)
            ->whereNotNull('correlation_id')->orderBy('id')->value('correlation_id');

        return is_string($root) && $root !== '' ? $root : (string) Str::uuid();
    }

    private function orderEvent(int $orderId, string $type, ?string $from, ?string $to, array $metadata): void
    {
        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'correlation_id' => $this->correlationId($orderId),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
