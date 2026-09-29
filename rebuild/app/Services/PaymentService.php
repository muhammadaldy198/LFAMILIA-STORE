<?php

namespace App\Services;

use App\Services\Payment\DokuDirectGateway;
use App\Services\Payment\ManualQrisGateway;
use App\Services\Payment\MidtransGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    public function __construct(
        private readonly PaymentRoutingService $routing,
        private readonly PaymentStateService $states,
        private readonly MidtransGateway $midtrans,
        private readonly DokuDirectGateway $doku,
        private readonly ManualQrisGateway $manualQris,
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

        $route = $this->routing->byRouteId((int) $order->payment_route_id);

        $payment = DB::transaction(function () use ($order, $route, $idempotencyKey, $fingerprint): object {
            $existing = DB::table('payment_transactions')->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing) {
                return $existing;
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

        if ($payment->request_fingerprint !== $fingerprint) {
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

        if ($topup->status !== 'PENDING_PAYMENT') {
            throw ValidationException::withMessages(['payment' => 'Top up tidak dapat dibayar.']);
        }

        $route = $this->routing->byRouteId((int) $topup->payment_route_id);
        if (in_array($route['gateway_code'], ['WALLET', 'MANUAL_QRIS'], true)) {
            throw ValidationException::withMessages(['payment' => 'Metode ini tidak dapat dipakai untuk top up saldo.']);
        }

        $payment = DB::transaction(function () use ($topup, $route, $idempotencyKey, $fingerprint): object {
            $id = DB::table('payment_transactions')->insertGetId([
                'order_id' => null,
                'wallet_topup_id' => $topup->id,
                'payment_route_id' => $route['route_id'],
                'gateway_code' => $route['gateway_code'],
                'channel_code' => $route['channel_code'],
                'amount_idr' => $topup->total_idr,
                'status' => 'CREATING',
                'request_fingerprint' => $fingerprint,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $topup->expires_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('payment_transactions')->where('id', $id)->update([
                'merchant_reference' => $this->merchantReference('TOP', (string) $topup->id, $id),
                'updated_at' => now(),
            ]);

            return DB::table('payment_transactions')->where('id', $id)->first();
        }, 3);

        $target = (object) [
            'user_id' => $topup->user_id,
            'guest_email' => $customerEmail,
            'guest_phone' => $customerPhone,
            'total_idr' => $topup->total_idr,
            'expires_at' => $topup->expires_at,
            'customer_name' => $customerName,
        ];

        return $this->createExternal($payment, $target, $route);
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

    private function createExternal(object $payment, object $target, array $route): array
    {
        if ($payment->status !== 'CREATING') {
            return $this->publicResult($payment);
        }

        $user = $target->user_id ? DB::table('users')->where('id', $target->user_id)->first() : null;
        $context = [
            'merchant_reference' => $payment->merchant_reference,
            'amount_idr' => (int) $payment->amount_idr,
            'customer_name' => $target->customer_name ?? $user?->name ?? 'Customer LFAMILIA',
            'customer_email' => $target->guest_email ?? $user?->email,
            'customer_phone' => $target->guest_phone ?? $user?->phone,
            'provider_channel' => $route['provider_channel'],
            'route_configuration' => $route['configuration'],
            'expires_minutes' => $this->expiresMinutes($target->expires_at ?? null),
        ];

        try {
            $result = match ($route['gateway_code']) {
                'MIDTRANS' => $this->midtrans->create($context),
                'DOKU' => $this->doku->create($context),
                'MANUAL_QRIS' => $this->manualQris->create(),
                default => throw ValidationException::withMessages(['payment' => 'Gateway pembayaran tidak didukung.']),
            };
        } catch (ValidationException $exception) {
            DB::table('payment_transactions')->where('id', $payment->id)->update([
                'status' => 'REJECTED',
                'updated_at' => now(),
            ]);
            throw $exception;
        } catch (RuntimeException $exception) {
            DB::table('payment_transactions')->where('id', $payment->id)->update([
                'status' => 'UNKNOWN',
                'updated_at' => now(),
            ]);
            throw ValidationException::withMessages([
                'payment' => 'Status pembuatan pembayaran belum dapat dipastikan. Jangan ulangi pembayaran; periksa status transaksi.',
            ]);
        }

        DB::table('payment_transactions')->where('id', $payment->id)->update([
            'external_reference' => $result['external_reference'],
            'status' => $result['status'],
            'public_payload' => $result['public_payload'] === null
                ? null : json_encode($result['public_payload'], JSON_THROW_ON_ERROR),
            'gateway_payload' => $result['gateway_payload'] === null
                ? null : json_encode($result['gateway_payload'], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

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

        return [
            'payment_id' => (int) $payment->id,
            'status' => (string) $payment->status,
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
}
