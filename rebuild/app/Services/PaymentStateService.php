<?php

namespace App\Services;

use App\Jobs\StartFulfillmentJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentStateService
{
    public function __construct(
        private readonly AdminNotificationService $notifications,
        private readonly TransactionalEmailService $emails,
        private readonly MembershipService $membership,
    ) {}

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
            if (! in_array($incomingStatus, ['PENDING', 'PAID', 'FAILED', 'CANCELLED', 'EXPIRED', 'REFUNDED', 'PARTIAL_REFUND_REVIEW'], true)) {
                throw ValidationException::withMessages(['payment' => 'Status pembayaran tidak valid.']);
            }

            // A partial refund is NOT proof that the entire order or top-up was refunded.
            // Keep money/order state unchanged until an operator verifies the amount.
            if ($incomingStatus === 'PARTIAL_REFUND_REVIEW') {
                $targetId = $payment->order_id ?: $payment->wallet_topup_id;
                $targetType = $payment->order_id ? 'order' : 'wallet_topup';
                $this->notifications->record(
                    'payment.partial_refund_review',
                    'Refund sebagian memerlukan pemeriksaan',
                    'Midtrans melaporkan partial refund untuk pembayaran #'.$payment->id.'. Verifikasi nominal secara manual; tidak ada saldo atau status pesanan yang diubah.',
                    'WARNING',
                    $targetType,
                    $targetId
                );

                return ['result' => 'PARTIAL_REFUND_REVIEW', 'status' => $payment->status];
            }

            if ($payment->status === 'REFUNDED') {
                if ($incomingStatus === 'PAID') {
                    Log::warning('Payment webhook PAID arrived after REFUNDED', [
                        'payment_transaction_id' => $paymentTransactionId,
                        'order_id' => $payment->order_id,
                        'gateway_code' => $payment->gateway_code,
                    ]);
                }

                return ['result' => 'IGNORED_FINAL', 'status' => 'REFUNDED'];
            }
            if ($payment->status === 'PAID' && $incomingStatus !== 'REFUNDED') {
                return ['result' => 'IGNORED_FINAL', 'status' => 'PAID'];
            }
            if (in_array($payment->status, ['FAILED', 'CANCELLED', 'EXPIRED'], true) && $incomingStatus === 'PENDING') {
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

            if (DB::table('wallet_topups')
                ->where('user_id', $userId)
                ->where('status', 'REFUND_REVIEW')
                ->exists()) {
                throw ValidationException::withMessages([
                    'payment' => 'Saldo sedang dibatasi sementara karena ada pengembalian dana yang perlu diselesaikan.',
                ]);
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

    /**
     * Tutup top up berstatus REFUND_REVIEW TANPA menggerakkan uang.
     *
     * Gateway sudah membalik dananya di luar sistem. Saldo pelanggan dibiarkan
     * apa adanya; penyelesaian uang yang sebenarnya dilakukan lewat penyesuaian
     * saldo manual yang sudah ada. Method ini hanya membuka kunci pembayaran
     * saldo dengan menandai tinjauan selesai.
     *
     * @return array<string, mixed>
     */
    public function closeTopupRefundReview(int $topupId, string $reason): array
    {
        return DB::transaction(function () use ($topupId, $reason): array {
            $topup = DB::table('wallet_topups')->where('id', $topupId)
                ->lockForUpdate()->first();
            if (! $topup) {
                throw ValidationException::withMessages(['topup' => 'Top up tidak ditemukan.']);
            }
            if ($topup->status !== 'REFUND_REVIEW') {
                throw ValidationException::withMessages([
                    'topup' => 'Top up ini tidak sedang menunggu tinjauan.',
                ]);
            }

            $closed = DB::table('wallet_topups')
                ->where('id', $topup->id)
                ->where('status', 'REFUND_REVIEW')
                ->update([
                    'status' => 'REFUNDED',
                    'updated_at' => now(),
                ]);
            if ($closed !== 1) {
                throw ValidationException::withMessages([
                    'topup' => 'Top up ini sudah ditutup sebelumnya.',
                ]);
            }

            $this->notifications->record(
                'wallet.topup_refund_review_closed',
                'Tinjauan refund top up ditutup',
                'Tinjauan top up #'.$topup->id.' ditutup dengan alasan: '.$reason.'. Saldo pelanggan tidak diubah.',
                'INFO',
                'wallet_topup',
                $topup->id
            );

            return [
                'result' => 'CLOSED',
                'status' => 'REFUNDED',
                'balance_idr' => (int) DB::table('wallets')->where('id', $topup->wallet_id)->value('balance_idr'),
                'reason' => $reason,
            ];
        }, 3);
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
