<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Pintu masuk keadaan pembayaran: menerapkan status pembayaran
 * yang masuk dengan memilah pesanan ke OrderPaymentStateService
 * dan topup ke TopupPaymentStateService. Pemanggil lama tetap
 * memakai class ini.
 * Class ini sendiri tidak menerapkan perubahan keadaan; ia
 * hanya memilah dan meneruskan ke dua service di baliknya.
 */
class PaymentStateService
{
    public function __construct(
        private readonly AdminNotificationService $notifications,
        private readonly OrderPaymentStateService $orderState,
        private readonly TopupPaymentStateService $topupState,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function apply(int $paymentTransactionId, string $incomingStatus, array $metadata = []): array
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu pintu penerapan status — transaksi dibuka, pembayaran
        // dikunci dan diperiksa, lalu diteruskan ke penangan pesanan
        // atau topup; membaginya akan menyebar satu keputusan
        // penerapan ke banyak tempat.
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
                return $this->orderState->applyOrder($payment, $incomingStatus, $metadata);
            }

            return $this->topupState->applyTopup($payment, $incomingStatus);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function payOrderWithWallet(int $paymentTransactionId, int $userId): array
    {
        return $this->orderState->payOrderWithWallet($paymentTransactionId, $userId);
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
        return $this->topupState->closeTopupRefundReview($topupId, $reason);
    }

    public function expireOrder(object $order): void
    {
        $this->orderState->expireOrder($order);
    }

    public function expireTopup(object $topup): void
    {
        $this->topupState->expireTopup($topup);
    }
}
