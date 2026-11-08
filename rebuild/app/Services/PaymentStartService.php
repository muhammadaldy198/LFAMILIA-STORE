<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Memulai pembayaran pesanan dan topup: memeriksa percobaan yang
 * sudah ada (idempotency), menyusun rute dan sidik pembayaran, lalu
 * menyerahkan pembuatan transaksi gateway ke PaymentCreationService.
 */
class PaymentStartService
{
    public function __construct(
        private readonly PaymentRoutingService $routing,
        private readonly PaymentStateService $states,
        private readonly PaymentCreationService $creation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startOrder(object $order, string $idempotencyKey, ?int $userId): array
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan mulai yang transaksional — kunci idempotency dan
        // sidik pembayaran diperiksa, rute dipilih, lalu baris pembayaran
        // dibuat di dalam transaksi; semua langkah berbagi konteks
        // pesanan yang sama dan harus berhenti utuh bila satu gagal.
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
            return $this->creation->publicResult($payment);
        }

        $existingOrderPayment = DB::table('payment_transactions')
            ->where('order_id', $order->id)
            ->where('status', '<>', 'REJECTED')
            ->orderByDesc('id')->first();
        if ($existingOrderPayment) {
            return $this->creation->publicResult($existingOrderPayment);
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
                return $this->creation->publicResult($payment);
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

            return $this->creation->publicResult(DB::table('payment_transactions')->where('id', $payment->id)->first());
        }

        return $this->creation->createExternal($payment, $order, $route);
    }

    /**
     * @return array<string, mixed>
     */
    public function startTopup(object $topup, string $idempotencyKey, string $customerName, string $customerEmail, string $customerPhone): array
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan mulai yang transaksional, cermin startOrder untuk
        // topup — gerbang kelayakan, kunci idempotency, dan pembuatan
        // baris pembayaran berbagi konteks topup yang sama dan harus
        // berhenti utuh bila satu langkah gagal.
        if (! $topup->payment_route_id || ! $topup->payment_channel_id) {
            throw ValidationException::withMessages(['payment' => 'Top up tidak dapat dibayar.']);
        }

        // The deadline gate runs before the findExisting fast path below:
        // a retry with the original idempotency key must not hand back live
        // payment instructions for a top-up whose deadline has passed. The
        // decision is taken on the locked row (the snapshot may be stale in
        // either direction); a top-up already PAID keeps its normal result.
        // An already-EXPIRED top-up is rejected too: the scheduled expiry
        // command (and this gate itself) expire the top-up without touching
        // its payment rows, so a still-PENDING payment would otherwise keep
        // leaking live instructions through the fast path.
        if ($topup->expires_at && now()->greaterThanOrEqualTo($topup->expires_at)) {
            $gateRejection = DB::transaction(function () use ($topup): ?string {
                $locked = DB::table('wallet_topups')->where('id', $topup->id)
                    ->lockForUpdate()->first();
                if (! $locked) {
                    return null;
                }
                if ($locked->status === 'PENDING_PAYMENT'
                    && $locked->expires_at && now()->greaterThanOrEqualTo($locked->expires_at)) {
                    $this->states->expireTopup($locked);

                    return 'Top up sudah kedaluwarsa.';
                }
                if ($locked->status === 'EXPIRED') {
                    return 'Top up sudah kedaluwarsa.';
                }

                return null;
            });
            if ($gateRejection !== null) {
                throw ValidationException::withMessages(['payment' => $gateRejection]);
            }
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
            return $this->creation->publicResult($payment);
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
                return $this->creation->publicResult($payment);
            }

            throw new HttpException(409, 'Idempotency key pembayaran sudah dipakai untuk transaksi berbeda.');
        }

        if ($result['route'] === null || $payment->status !== 'CREATING') {
            return $this->creation->publicResult($payment);
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

        return $this->creation->createExternal($payment, $target, $result['route']);
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

    private function merchantReference(string $prefix, string $target, int $id): string
    {
        return substr($prefix.'-'.$target.'-'.$id, 0, 50);
    }
}
