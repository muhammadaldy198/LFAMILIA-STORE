<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GuestOrderAccess
{
    public function issue(int $orderId): string
    {
        return $this->store($orderId, Str::random(64));
    }

    public function issueStable(int $orderId, string $idempotencyKey): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new InvalidArgumentException('APP_KEY wajib tersedia.');
        }

        return $this->store($orderId, hash_hmac(
            'sha256',
            'guest-order:'.$orderId.':'.$idempotencyKey,
            $key
        ));
    }

    public function matches(int $orderId, string $token): bool
    {
        $hash = DB::table('guest_order_tokens')->where('order_id', $orderId)->value('token_hash');

        return is_string($hash) && strlen($token) === 64
            && hash_equals($hash, hash('sha256', $token));
    }

    private function store(int $orderId, string $token): string
    {
        $order = DB::table('orders')->where('id', $orderId)->first();

        if (! $order || $order->user_id !== null) {
            throw new InvalidArgumentException('Token hanya untuk pesanan guest.');
        }

        DB::table('guest_order_tokens')->updateOrInsert(
            ['order_id' => $orderId],
            ['token_hash' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now()]
        );

        return $token;
    }
}
