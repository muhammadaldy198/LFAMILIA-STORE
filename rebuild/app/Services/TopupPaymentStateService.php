<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menerapkan keadaan pembayaran TOPUP: status masuk diterapkan ke
 * saldo dompet tujuan, tinjauan pengembalian dana topup ditutup
 * (tanpa memicu pengembalian dana apa pun), dan topup kedaluwarsa
 * ditutup.
 * Class ini TIDAK menangani pesanan; keadaan pesanan ada di
 * OrderPaymentStateService.
 */
class TopupPaymentStateService
{
    public function __construct(
        private readonly AdminNotificationService $notifications,
    ) {}

    public function applyTopup(object $payment, string $incomingStatus): array
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan penerapan status topup — status dibandingkan,
        // lalu saldo dompet dan catatan topup diperbarui beserta
        // notifikasinya dalam konteks pembayaran yang sama.
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

            if ($topup->status === 'PENDING_PAYMENT'
                && $topup->expires_at && now()->greaterThanOrEqualTo($topup->expires_at)) {
                $this->expireTopup($topup);
                $topup->status = 'EXPIRED';
            }

            if ($topup->status !== 'PENDING_PAYMENT') {
                // Mirror of the late-PAID path for orders in applyOrder: the
                // payment row is verified, but money that arrives for a
                // top-up that is no longer payable is never credited
                // automatically; an admin reviews it instead.
                DB::table('payment_transactions')->where('id', $payment->id)->update([
                    'status' => 'PAID',
                    'verified_at' => now(),
                    'last_callback_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->notifications->record(
                    'payment.late_verified',
                    'Pembayaran terlambat terverifikasi',
                    'Pembayaran top up #'.$topup->id.' masuk setelah top up berstatus '.$topup->status.'. Perlu diperiksa.',
                    'WARNING',
                    'wallet_topup',
                    $topup->id
                );

                return ['result' => 'LATE_PAID_REVIEW', 'status' => $topup->status];
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
            // Serialize against wallet spending on the wallet row lock — the
            // same lock the PAID branch takes and payOrderWithWallet holds
            // while it re-checks the freeze — so a REFUND_REVIEW commit can
            // never land in the middle of an in-flight wallet checkout.
            DB::table('wallets')->where('id', $topup->wallet_id)->lockForUpdate()->first();
            // No automatic reversal of a customer's existing wallet balance.
            $review = $topup->status === 'PAID';
            DB::table('wallet_topups')->where('id', $topup->id)->update([
                'status' => $review ? 'REFUND_REVIEW' : 'REFUNDED',
                'updated_at' => now(),
            ]);
            if ($review) {
                $this->notifications->record(
                    'wallet.topup_refund_review',
                    'Refund top up memerlukan tindakan admin',
                    'Gateway melaporkan refund top up #'.$topup->id.'. Saldo tidak diubah otomatis; periksa dan selesaikan melalui panel admin.',
                    'WARNING',
                    'wallet_topup',
                    $topup->id
                );
            }

            return ['result' => $review ? 'REFUND_REVIEW' : 'REFUNDED',
                'status' => $review ? 'REFUND_REVIEW' : 'REFUNDED'];
        }

        if (in_array($incomingStatus, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
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
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi.']);
        }

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

    public function expireTopup(object $topup): void
    {
        if ($topup->status !== 'PENDING_PAYMENT') {
            return;
        }

        DB::table('wallet_topups')->where('id', $topup->id)->update([
            'status' => 'EXPIRED',
            'updated_at' => now(),
        ]);
    }
}
