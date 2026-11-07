<?php

namespace App\Services;

use App\Services\Payment\DokuCheckoutGateway;
use App\Services\Payment\GatewayTransactionNotFoundException;
use App\Services\Payment\ManualQrisGateway;
use App\Services\Payment\MidtransGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    public function __construct(
        private readonly PaymentRoutingService $routing,
        private readonly PaymentStateService $states,
        private readonly MidtransGateway $midtrans,
        private readonly DokuCheckoutGateway $doku,
        private readonly ManualQrisGateway $manualQris,
        private readonly AdminNotificationService $notifications,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startOrder(object $order, string $idempotencyKey, ?int $userId): array
    {
        if (! $order->payment_route_id) {
            throw ValidationException::withMessages(['payment' => 'Metode pembayaran pesanan tidak valid.']);
        }

        $snapshot = is_string($order->snapshot)
            ? (json_decode($order->snapshot, true) ?: [])
            : ((array) $order->snapshot);
        $channelCode = (string) data_get($snapshot, 'payment.channel_code', '');
        if ($channelCode === '') {
            throw ValidationException::withMessages(['payment' => 'Snapshot metode pembayaran tidak valid.']);
        }
        $fingerprint = hash('sha256', implode('|', [
            'order', $order->id, $channelCode, $order->payment_route_id, $order->total_idr,
        ]));

        $payment = $this->findExisting($idempotencyKey, $fingerprint);
        if ($payment) {
            return $this->publicResult($payment);
        }

        $existingOrderPayment = DB::table('payment_transactions')
            ->where('order_id', $order->id)
            ->where('status', '<>', 'REJECTED')
            ->orderByDesc('id')->first();
        if ($existingOrderPayment) {
            return $this->publicResult($existingOrderPayment);
        }

        if ($order->status !== 'PENDING_PAYMENT') {
            throw ValidationException::withMessages(['payment' => 'Pesanan tidak menunggu pembayaran.']);
        }
        if ($order->expires_at && now()->greaterThanOrEqualTo($order->expires_at)) {
            DB::transaction(function () use ($order): void {
                $locked = DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
                if ($locked) {
                    $this->states->expireOrder($locked);
                }
            });
            throw ValidationException::withMessages(['payment' => 'Pesanan sudah kedaluwarsa.']);
        }

        $route = $this->routing->byRouteId((int) $order->payment_route_id, 'order', false, true);

        $payment = DB::transaction(function () use ($order, $route, $idempotencyKey, $fingerprint): object {
            $lockedOrder = DB::table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();

            // The snapshot checks above ran on an unlocked copy of the order.
            // Re-validate both against the locked row before any payment is
            // created: in between, the order may have been cancelled, paid
            // through another path, or reached its expiry.
            if ($lockedOrder->status !== 'PENDING_PAYMENT') {
                throw ValidationException::withMessages(['payment' => 'Pesanan tidak menunggu pembayaran.']);
            }
            if ($lockedOrder->expires_at && now()->greaterThanOrEqualTo($lockedOrder->expires_at)) {
                $this->states->expireOrder($lockedOrder);

                // Do not throw here: throwing would roll back the expiry
                // committed by this transaction. The caller turns this
                // marker into the same expiry rejection the snapshot
                // path raises.
                return (object) ['order_expired_in_lock' => true];
            }

            $existing = DB::table('payment_transactions')->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $existingOrderPayment = DB::table('payment_transactions')
                ->where('order_id', $order->id)
                ->where('status', '<>', 'REJECTED')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($existingOrderPayment) {
                return $existingOrderPayment;
            }

            $id = DB::table('payment_transactions')->insertGetId([
                'order_id' => $order->id,
                'wallet_topup_id' => null,
                'payment_route_id' => $route['route_id'],
                'gateway_code' => $route['gateway_code'],
                'channel_code' => $route['channel_code'],
                'amount_idr' => $order->total_idr,
                'status' => 'CREATING',
                'request_fingerprint' => $fingerprint,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $order->expires_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $merchantReference = $this->merchantReference('PAY', (string) $order->order_number, $id);
            DB::table('payment_transactions')->where('id', $id)->update([
                'merchant_reference' => $merchantReference,
                'updated_at' => now(),
            ]);

            return DB::table('payment_transactions')->where('id', $id)->first();
        }, 3);

        if (($payment->order_expired_in_lock ?? false) === true) {
            throw ValidationException::withMessages(['payment' => 'Pesanan sudah kedaluwarsa.']);
        }

        if (! hash_equals((string) $payment->request_fingerprint, $fingerprint)) {
            if ((int) $payment->order_id === (int) $order->id) {
                return $this->publicResult($payment);
            }

            throw new HttpException(409, 'Idempotency key pembayaran sudah dipakai untuk transaksi berbeda.');
        }

        if ($route['gateway_code'] === 'WALLET') {
            if ($userId === null) {
                DB::table('payment_transactions')->where('id', $payment->id)->update([
                    'status' => 'REJECTED',
                    'updated_at' => now(),
                ]);
                throw ValidationException::withMessages(['payment' => 'Saldo hanya tersedia untuk akun customer.']);
            }
            try {
                $this->states->payOrderWithWallet($payment->id, $userId);
            } catch (ValidationException $exception) {
                DB::table('payment_transactions')->where('id', $payment->id)
                    ->where('status', 'CREATING')->update([
                        'status' => 'REJECTED',
                        'updated_at' => now(),
                    ]);
                throw $exception;
            }

            return $this->publicResult(DB::table('payment_transactions')->where('id', $payment->id)->first());
        }

        return $this->createExternal($payment, $order, $route);
    }

    /**
     * @return array<string, mixed>
     */
    public function startTopup(object $topup, string $idempotencyKey, string $customerName, string $customerEmail, string $customerPhone): array
    {
        if (! $topup->payment_route_id || ! $topup->payment_channel_id) {
            throw ValidationException::withMessages(['payment' => 'Top up tidak dapat dibayar.']);
        }

        $channelCode = DB::table('payment_channels')
            ->where('id', $topup->payment_channel_id)->value('code');
        if (! is_string($channelCode) || $channelCode === '') {
            throw ValidationException::withMessages(['payment' => 'Metode top up tidak valid.']);
        }

        $fingerprint = hash('sha256', implode('|', [
            'topup', $topup->id, $channelCode, $topup->payment_route_id, $topup->total_idr,
        ]));

        $payment = $this->findExisting($idempotencyKey, $fingerprint);
        if ($payment) {
            return $this->publicResult($payment);
        }

        $result = DB::transaction(function () use ($topup, $idempotencyKey, $fingerprint): array {
            $lockedTopup = DB::table('wallet_topups')->where('id', $topup->id)
                ->lockForUpdate()->first();
            if (! $lockedTopup || ! $lockedTopup->payment_route_id || ! $lockedTopup->payment_channel_id) {
                throw ValidationException::withMessages(['payment' => 'Top up tidak dapat dibayar.']);
            }
            if ($lockedTopup->status !== 'PENDING_PAYMENT') {
                throw ValidationException::withMessages(['payment' => 'Top up tidak dapat dibayar.']);
            }
            if ($lockedTopup->expires_at && now()->greaterThanOrEqualTo($lockedTopup->expires_at)) {
                $this->states->expireTopup($lockedTopup);

                // Do not throw here: throwing would roll back the expiry
                // committed by this transaction. The caller turns this
                // marker into the same expiry rejection — the same contract
                // as the locked expiry path in startOrder.
                return ['payment' => null, 'topup' => $lockedTopup, 'route' => null,
                    'topup_expired_in_lock' => true];
            }

            $existing = DB::table('payment_transactions')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing) {
                return ['payment' => $existing, 'topup' => $lockedTopup, 'route' => null];
            }

            $existingTopupPayment = DB::table('payment_transactions')
                ->where('wallet_topup_id', $lockedTopup->id)
                ->where('status', '<>', 'REJECTED')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($existingTopupPayment) {
                return ['payment' => $existingTopupPayment, 'topup' => $lockedTopup, 'route' => null];
            }

            $route = $this->routing->byRouteId((int) $lockedTopup->payment_route_id, 'topup', true, true);
            if (in_array($route['gateway_code'], ['WALLET', 'MANUAL_QRIS'], true)) {
                throw ValidationException::withMessages(['payment' => 'Metode ini tidak dapat dipakai untuk top up saldo.']);
            }

            $id = DB::table('payment_transactions')->insertGetId([
                'order_id' => null,
                'wallet_topup_id' => $lockedTopup->id,
                'payment_route_id' => $route['route_id'],
                'gateway_code' => $route['gateway_code'],
                'channel_code' => $route['channel_code'],
                'amount_idr' => $lockedTopup->total_idr,
                'status' => 'CREATING',
                'request_fingerprint' => $fingerprint,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $lockedTopup->expires_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('payment_transactions')->where('id', $id)->update([
                'merchant_reference' => $this->merchantReference('TOP', (string) $lockedTopup->id, $id),
                'updated_at' => now(),
            ]);

            return [
                'payment' => DB::table('payment_transactions')->where('id', $id)->first(),
                'topup' => $lockedTopup,
                'route' => $route,
            ];
        }, 3);

        if (($result['topup_expired_in_lock'] ?? false) === true) {
            throw ValidationException::withMessages(['payment' => 'Top up sudah kedaluwarsa.']);
        }

        $payment = $result['payment'];
        if (! hash_equals((string) $payment->request_fingerprint, $fingerprint)) {
            if ((int) $payment->wallet_topup_id === (int) $topup->id) {
                return $this->publicResult($payment);
            }

            throw new HttpException(409, 'Idempotency key pembayaran sudah dipakai untuk transaksi berbeda.');
        }

        if ($result['route'] === null || $payment->status !== 'CREATING') {
            return $this->publicResult($payment);
        }

        $lockedTopup = $result['topup'];
        $target = (object) [
            'user_id' => $lockedTopup->user_id,
            'guest_email' => $customerEmail,
            'guest_phone' => $customerPhone,
            'total_idr' => $lockedTopup->total_idr,
            'expires_at' => $lockedTopup->expires_at,
            'customer_name' => $customerName,
        ];

        return $this->createExternal($payment, $target, $result['route']);
    }

    private function findExisting(string $idempotencyKey, string $fingerprint): ?object
    {
        $payment = DB::table('payment_transactions')->where('idempotency_key', $idempotencyKey)->first();
        if (! $payment) {
            return null;
        }
        if (! hash_equals((string) $payment->request_fingerprint, $fingerprint)) {
            throw new HttpException(409, 'Idempotency key pembayaran sudah dipakai untuk transaksi berbeda.');
        }

        return $payment;
    }

    /**
     * Use the same provider checkout duration passed to the gateway,
     * plus a five-minute redirect grace period. Never issue permanent
     * signatures; the maximum TTL is 125 minutes (120 + 5).
     */
    private function signedReturnUrl(string $orderNumber, int $providerExpiresMinutes): string
    {
        return URL::temporarySignedRoute(
            'payment.show',
            now()->addMinutes($providerExpiresMinutes + 5),
            ['invoice' => $orderNumber],
        );
    }

    private function createExternal(object $payment, object $target, array $route): array
    {
        if ($payment->status !== 'CREATING') {
            return $this->publicResult($payment);
        }

        $claimed = DB::table('payment_transactions')
            ->where('id', $payment->id)
            ->where('status', 'CREATING')
            ->update([
                'status' => 'SENDING',
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return $this->publicResult(
                DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail()
            );
        }

        $payment = DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail();
        $user = $target->user_id ? DB::table('users')->where('id', $target->user_id)->first() : null;
        // Gateway mengembalikan pengguna ke return/finish URL ini
        // setelah bayar (Midtrans callbacks.finish, DOKU
        // callback_url_result). URL-nya DITANDATANGANI sementara
        // (lihat signedReturnUrl): tanpa signature, member yang
        // membuka halaman lewat link signed (logout) akan kena 403
        // di PaymentPageController karena sesi/login-nya tidak ada,
        // padahal order sudah dibayar.
        $orderNumber = $payment->order_id
            ? DB::table('orders')->where('id', $payment->order_id)->value('order_number')
            : null;
        // Midtrans Snap requires at least five minutes for its page and
        // payment expiry. DOKU retains the existing minimum of one minute.
        // Use this exact duration for both gateway creation and return URL.
        $providerExpiresMinutes = $this->expiresMinutes($target->expires_at ?? null);
        if ($route['gateway_code'] === 'MIDTRANS') {
            $providerExpiresMinutes = max(5, $providerExpiresMinutes);
        }
        $returnUrl = $orderNumber !== null
            ? $this->signedReturnUrl((string) $orderNumber, $providerExpiresMinutes)
            : null;
        $context = [
            'merchant_reference' => $payment->merchant_reference,
            'return_url' => $returnUrl,
            'finish_url' => $returnUrl,
            'amount_idr' => (int) $payment->amount_idr,
            'customer_name' => $target->customer_name ?? $user?->name ?? 'Customer LFAMILIA',
            'customer_email' => $target->guest_email ?? $user?->email,
            // Gateway (Midtrans/Doku) hanya menerima digit pada field phone,
            // jadi kode negara "+62..." dibersihkan di sini.
            'customer_phone' => $this->phoneDigits($target->guest_phone ?? $user?->phone),
            'provider_channel' => $route['provider_channel'],
            'route_configuration' => $route['configuration'],
            'expires_minutes' => $providerExpiresMinutes,
        ];

        try {
            $result = match ($route['gateway_code']) {
                'MIDTRANS' => $this->midtrans->create($context),
                'DOKU' => $this->doku->create($context),
                'MANUAL_QRIS' => $this->manualQris->create(),
                default => throw ValidationException::withMessages(['payment' => 'Gateway pembayaran tidak didukung.']),
            };
        } catch (ValidationException $exception) {
            $rejected = DB::table('payment_transactions')
                ->where('id', $payment->id)
                ->where('status', 'SENDING')
                ->update([
                    'status' => 'REJECTED',
                    'updated_at' => now(),
                ]);
            if ($rejected !== 1) {
                return $this->publicResult(
                    DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail()
                );
            }

            throw $exception;
        } catch (RuntimeException|ConnectionException $exception) {
            $unknown = DB::table('payment_transactions')
                ->where('id', $payment->id)
                ->where('status', 'SENDING')
                ->update([
                    'status' => 'UNKNOWN',
                    'updated_at' => now(),
                ]);
            if ($unknown !== 1) {
                return $this->publicResult(
                    DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail()
                );
            }

            Log::warning('Payment transaction marked UNKNOWN, needs manual reconciliation', [
                'payment_transaction_id' => $payment->id,
                'order_id' => $payment->order_id,
                'gateway_code' => $payment->gateway_code,
            ]);

            // Gateway timeout tidak bisa dibedakan dari penolakan: transaksi bisa
            // saja sudah terbuat di sisi gateway. Admin harus memeriksa manual.
            $this->notifications->record(
                'payment.unknown',
                'Pembayaran perlu diperiksa',
                'Pembuatan pembayaran #'.$payment->id.' ('.$payment->gateway_code.') tidak dapat dipastikan: '.$exception::class.'. Periksa status di gateway sebelum menyuruh pembeli membayar ulang.',
                'WARNING',
                'payment_transaction',
                (int) $payment->id,
                ['order_id' => $payment->order_id, 'gateway_code' => $payment->gateway_code]
            );

            throw ValidationException::withMessages([
                'payment' => 'Status pembuatan pembayaran belum dapat dipastikan. Jangan ulangi pembayaran; periksa status transaksi.',
            ]);
        }

        DB::table('payment_transactions')->where('id', $payment->id)->update([
            'external_reference' => $result['external_reference'],
            'public_payload' => $result['public_payload'] === null
                ? null : json_encode($result['public_payload'], JSON_THROW_ON_ERROR),
            'gateway_payload' => $result['gateway_payload'] === null
                ? null : json_encode($result['gateway_payload'], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $statusUpdated = DB::table('payment_transactions')
            ->where('id', $payment->id)
            ->where('status', 'SENDING')
            ->update([
                'status' => $result['status'],
                'updated_at' => now(),
            ]);

        if ($statusUpdated === 1 && $route['gateway_code'] === 'MANUAL_QRIS' && $result['status'] === 'PENDING' && $payment->order_id !== null) {
            $orderNumber = DB::table('orders')->where('id', $payment->order_id)->value('order_number');
            $this->notifications->record(
                'payment.manual_qris.pending',
                'QRIS manual menunggu konfirmasi',
                'Order '.($orderNumber ?: '#'.$payment->order_id).' sudah menampilkan QRIS manual dan menunggu verifikasi pembayaran.',
                'WARNING',
                'order',
                $payment->order_id,
                ['payment_id' => (int) $payment->id, 'order_number' => $orderNumber]
            );
        }

        return $this->publicResult(DB::table('payment_transactions')->where('id', $payment->id)->first());
    }

    /**
     * @return array<string, mixed>
     */
    private function publicResult(object $payment): array
    {
        $payload = is_string($payment->public_payload)
            ? (json_decode($payment->public_payload, true) ?: [])
            : ((array) ($payment->public_payload ?? []));

        $status = (string) $payment->status;
        if ($status === 'SENDING') {
            $status = 'CREATING';
        }

        return [
            'payment_id' => (int) $payment->id,
            'status' => $status,
            'channel_code' => (string) $payment->channel_code,
            'amount_idr' => (int) $payment->amount_idr,
            'instructions' => $payload,
        ];
    }

    private function merchantReference(string $prefix, string $target, int $id): string
    {
        return substr($prefix.'-'.$target.'-'.$id, 0, 50);
    }

    private function expiresMinutes(mixed $expiresAt): int
    {
        if (! $expiresAt) {
            return 30;
        }

        return max(1, min(120, (int) now()->diffInMinutes($expiresAt, false)));
    }

    /** Nomor untuk gateway pembayaran: hanya digit, tanpa "+" atau kode negara bersimbol. */
    private function phoneDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }

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

        $this->createExternal($payment, $target, $route);

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
