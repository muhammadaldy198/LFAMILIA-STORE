<?php

namespace App\Services;

use App\Services\Payment\DokuCheckoutGateway;
use App\Services\Payment\GatewayTransactionNotFoundException;
use App\Services\Payment\MidtransGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Memulihkan pembayaran yang macet di status pembuatan (CREATING):
 * menanyakan gateway lebih dulu, mengadopsi hasilnya bila ada,
 * menggerakkan pembuatan ulang hanya bila gateway memastikan belum
 * punya transaksinya, atau menghentikan pembayaran itu.
 */
class PaymentRecoveryService
{
    public function __construct(
        private readonly PaymentRoutingService $routing,
        private readonly PaymentStateService $states,
        private readonly MidtransGateway $midtrans,
        private readonly DokuCheckoutGateway $doku,
        private readonly PaymentCreationService $creation,
    ) {}

    /**
     * Recover one payment orphaned in CREATING: its local creation
     * transaction committed but the gateway creation call never ran, so
     * no other flow will ever move the row again. The gateway is always
     * asked first; a new creation is driven only when the gateway
     * definitively has no transaction for the merchant reference.
     *
     * @return string adopted|redriven|terminated|inconclusive|skipped
     */
    public function recoverStuckCreating(int $paymentId): string
    {
        // Alasan belum dipecah: satu alur keputusan pemulihan — tanya
        // gateway, lalu adopsi, gerakkan ulang, atau hentikan
        // berdasarkan jawaban yang sama; cabangnya berbagi konteks
        // pembayaran yang sedang dipulihkan.
        $payment = DB::table('payment_transactions')->where('id', $paymentId)->first();
        if (! $payment || $payment->status !== 'CREATING'
            || ! in_array($payment->gateway_code, ['MIDTRANS', 'DOKU'], true)
            || ! $payment->merchant_reference) {
            return 'skipped';
        }

        // Optimistic claim, the same pattern the scheduled reconciler
        // uses: only the caller that moves updated_at owns this attempt.
        $claimed = DB::table('payment_transactions')
            ->where('id', $payment->id)
            ->where('status', 'CREATING')
            ->where('updated_at', $payment->updated_at)
            ->update(['updated_at' => now()]);
        if ($claimed !== 1) {
            return 'skipped';
        }
        $payment = DB::table('payment_transactions')->where('id', $paymentId)->firstOrFail();

        try {
            $verified = $payment->gateway_code === 'MIDTRANS'
                ? $this->midtrans->status((string) $payment->merchant_reference)
                : $this->doku->status((string) $payment->merchant_reference);
        } catch (GatewayTransactionNotFoundException) {
            return $this->redriveOrTerminateCreating($payment);
        } catch (RuntimeException) {
            return 'inconclusive';
        }

        $verification = app(MidtransStatusVerification::class);
        try {
            if ($payment->gateway_code === 'MIDTRANS') {
                if (! hash_equals((string) $payment->merchant_reference, (string) ($verified['order_id'] ?? ''))
                    || $verification->amount($verified['gross_amount']) !== (int) $payment->amount_idr) {
                    Log::warning('CREATING recovery verification rejected', [
                        'payment_transaction_id' => $payment->id,
                        'reason' => 'IDENTITY_OR_AMOUNT_MISMATCH',
                    ]);

                    return 'inconclusive';
                }
                $status = $verification->status($verified);
            } else {
                if (! hash_equals((string) $payment->merchant_reference, (string) data_get($verified, 'order.invoice_number'))
                    || $verification->amount(data_get($verified, 'order.amount')) !== (int) $payment->amount_idr) {
                    Log::warning('CREATING recovery verification rejected', [
                        'payment_transaction_id' => $payment->id,
                        'reason' => 'IDENTITY_OR_AMOUNT_MISMATCH',
                    ]);

                    return 'inconclusive';
                }
                $status = $this->dokuVerifiedStatus($verified);
            }
        } catch (ValidationException|RuntimeException) {
            return 'inconclusive';
        }

        $this->states->apply((int) $payment->id, $status, ['source' => 'creating_recovery']);

        return 'adopted';
    }

    private function redriveOrTerminateCreating(object $payment): string
    {
        if ($payment->order_id !== null) {
            $order = DB::table('orders')->where('id', $payment->order_id)->first();
            $payable = $order !== null
                && $order->status === 'PENDING_PAYMENT'
                && ! ($order->expires_at && now()->greaterThanOrEqualTo($order->expires_at));
            if (! $payable) {
                return $this->terminateCreating($payment, 'ORDER_NOT_PAYABLE');
            }
            $target = $order;
            $route = $this->routing->byRouteId((int) $payment->payment_route_id, 'order', false, true);
        } else {
            $topup = DB::table('wallet_topups')->where('id', $payment->wallet_topup_id)->first();
            // Top-up payability follows the top-up row's own state, exactly
            // like startTopup's locked validation — including its deadline,
            // which startTopup now enforces: a top-up past its deadline is
            // not payable anywhere, so recovery terminates instead of
            // re-driving creation for it.
            $payable = $topup !== null
                && $topup->status === 'PENDING_PAYMENT'
                && ! ($topup->expires_at && now()->greaterThanOrEqualTo($topup->expires_at));
            if (! $payable) {
                return $this->terminateCreating($payment, 'TOPUP_NOT_PAYABLE');
            }
            $target = (object) [
                'user_id' => $topup->user_id,
                'guest_email' => null,
                'guest_phone' => null,
                'total_idr' => $topup->total_idr,
                'expires_at' => $topup->expires_at,
                'customer_name' => null,
            ];
            $route = $this->routing->byRouteId((int) $payment->payment_route_id, 'topup', true, true);
        }

        $this->creation->createExternal($payment, $target, $route);

        return 'redriven';
    }

    private function terminateCreating(object $payment, string $reason): string
    {
        $terminated = DB::table('payment_transactions')
            ->where('id', $payment->id)
            ->where('status', 'CREATING')
            ->update([
                'status' => 'FAILED',
                'updated_at' => now(),
            ]);
        if ($terminated !== 1) {
            return 'skipped';
        }

        Log::warning('Stuck CREATING payment terminated by recovery', [
            'payment_transaction_id' => $payment->id,
            'order_id' => $payment->order_id,
            'wallet_topup_id' => $payment->wallet_topup_id,
            'gateway_code' => $payment->gateway_code,
            'reason' => $reason,
        ]);

        return 'terminated';
    }

    /**
     * Map a verified DOKU inquiry payload to an internal payment status,
     * mirroring the mapping the DOKU notification flow applies.
     */
    private function dokuVerifiedStatus(array $verified): string
    {
        return match (strtoupper((string) data_get($verified, 'transaction.status'))) {
            'SUCCESS' => 'PAID',
            'EXPIRED' => 'EXPIRED',
            'CANCELLED', 'CANCELED' => 'CANCELLED',
            'FAILED' => 'FAILED',
            'REFUNDED', 'REFUND' => 'REFUNDED',
            'PENDING', 'REDIRECT', 'TIMEOUT' => 'PENDING',
            default => throw new RuntimeException('DOKU_STATUS_UNRECOGNIZED'),
        };
    }
}
