<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WalletTopupService
{
    public function __construct(
        private readonly PaymentRoutingService $routing,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function quote(int $amountIdr, string $channelCode): array
    {
        $this->assertEnabled();
        $minimum = $this->minimum();
        if ($amountIdr < $minimum) {
            throw ValidationException::withMessages([
                'amount_idr' => 'Minimum top up saldo Rp'.number_format($minimum, 0, ',', '.').'.',
            ]);
        }

        $route = $this->routing->resolve($channelCode, false, 'topup');
        $fee = $this->routing->fee($amountIdr, $route);

        return [
            'amount_idr' => $amountIdr,
            'fee_idr' => $fee,
            'total_idr' => $amountIdr + $fee,
            'payment_channel_code' => $route['channel_code'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(User $user, int $amountIdr, string $channelCode, string $idempotencyKey): array
    {
        $fingerprint = hash('sha256', implode('|', [
            $user->id, $amountIdr, $channelCode,
        ]));

        $existing = DB::table('wallet_topups')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                throw new HttpException(409, 'Idempotency key top up sudah digunakan untuk permintaan berbeda.');
            }

            return [
                'topup_id' => (int) $existing->id,
                'payment' => $this->payments->startTopup(
                    $existing,
                    'payment-'.$idempotencyKey,
                    $user->name,
                    $user->email,
                    (string) $user->phone
                ),
            ];
        }

        $quote = $this->quote($amountIdr, $channelCode);
        $route = $this->routing->resolve($channelCode, true, 'topup');
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
        $topup = DB::transaction(function () use ($user, $wallet, $amountIdr, $quote, $route, $idempotencyKey, $fingerprint): object {
            $id = DB::table('wallet_topups')->insertGetId([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'payment_channel_id' => $route['channel_id'],
                'payment_route_id' => $route['route_id'],
                'amount_idr' => $amountIdr,
                'fee_idr' => $quote['fee_idr'],
                'total_idr' => $quote['total_idr'],
                'status' => 'PENDING_PAYMENT',
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'expires_at' => now()->addMinutes(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('wallet_topups')->where('id', $id)->first();
        }, 3);

        return [
            'topup_id' => (int) $topup->id,
            'payment' => $this->payments->startTopup(
                $topup,
                'payment-'.$idempotencyKey,
                $user->name,
                $user->email,
                (string) $user->phone
            ),
            'fingerprint' => $fingerprint,
        ];
    }

    private function assertEnabled(): void
    {
        $raw = DB::table('system_settings')->where('key', 'wallet.topup_enabled')->value('value');
        $enabled = $raw === null ? true : (bool) json_decode((string) $raw, true);
        if (! $enabled) {
            throw ValidationException::withMessages([
                'amount_idr' => 'Top up saldo sedang dinonaktifkan.',
            ]);
        }
    }

    private function minimum(): int
    {
        $raw = DB::table('system_settings')->where('key', 'wallet.minimum_topup_idr')->value('value');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return max(1, (int) $decoded);
        }

        return 10000;
    }
}
