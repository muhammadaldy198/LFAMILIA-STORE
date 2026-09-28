<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletTopupRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');

        config()->set('lfamilia.public_base_url', 'https://lfamilia.example');
        config()->set('lfamilia.integrations.midtrans.environment', 'sandbox');
        config()->set('lfamilia.integrations.midtrans.server_key', 'midtrans-server');
        config()->set('lfamilia.integrations.midtrans.client_key', 'midtrans-client');
        config()->set('lfamilia.integrations.midtrans.snap_base_url', 'https://snap.topup.test');

        DB::table('wallet_settings')->insertOrIgnore([
            'id' => 1,
            'is_enabled' => 0,
            'method_name' => 'Transfer Bank',
            'account_name' => '',
            'account_number' => '',
            'min_topup' => 10000,
            'doku_topup_enabled' => 1,
            'doku_checkout_enabled' => 0,
            'updated_at' => now(),
        ]);

        DB::table('integration_settings')->insert([
            'setting_key' => 'wallet_topup_gateway',
            'value' => 'midtrans',
            'updated_at' => now(),
        ]);

        DB::table('payment_gateway_settings')->insert([
            'gateway' => 'midtrans',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_channels')->insert([
            'method' => 'qris',
            'channel' => 'mpm',
            'name' => 'QRIS',
            'description' => 'QRIS',
            'is_active' => 1,
            'sort_order' => 0,
            'gateway' => 'doku',
            'gateway_config_json' => json_encode([
                'customerFeeEnabled' => 'true',
                'customerFeeBps' => '70',
                'customerFeeFixed' => '0',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_topup_uses_admin_selected_gateway_and_is_idempotent(): void
    {
        [$customerId, $token] = $this->customer();

        Http::fake([
            'https://snap.topup.test/snap/v1/transactions' => Http::response([
                'token' => 'wallet-snap-token',
                'redirect_url' => 'https://pay.test/wallet-snap-token',
            ], 201),
        ]);

        $payload = [
            'amount' => 20000,
            'paymentMethod' => 'qris',
            'paymentChannel' => 'mpm',
            'idempotencyKey' => '44444444-4444-4444-8444-444444444444',
        ];

        $first = $this->withCredentials()->withUnencryptedCookie('lfamilia_session', $token)
            ->postJson('/api/account/topups', $payload);
        $first->assertCreated()->assertJsonPath('paymentMethod', 'qris');

        $second = $this->withCredentials()->withUnencryptedCookie('lfamilia_session', $token)
            ->postJson('/api/account/topups', $payload);
        $second->assertOk()->assertJsonPath('reused', true);

        $this->assertSame(1, DB::table('wallet_topups')
            ->where('customer_id', $customerId)
            ->where('external_checkout_key', $payload['idempotencyKey'])
            ->count());
        $this->assertDatabaseHas('wallet_topups', [
            'customer_id' => $customerId,
            'payment_gateway' => 'midtrans',
            'payment_gateway_mode' => 'snap',
            'gateway_environment' => 'sandbox',
        ]);

        Http::assertSentCount(1);
    }

    /** @return array{0:string,1:string} */
    private function customer(): array
    {
        $id = (string) Str::uuid();
        DB::table('customer_users')->insert([
            'id' => $id,
            'email' => 'topup@example.com',
            'name' => 'Topup User',
            'phone' => '+6281234567890',
            'password_hash' => str_repeat('a', 64),
            'password_salt' => str_repeat('b', 32),
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'topup-session-'.$id;
        DB::table('customer_sessions')->insert([
            'id' => (string) Str::uuid(),
            'customer_id' => $id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        return [$id, $token];
    }
}
