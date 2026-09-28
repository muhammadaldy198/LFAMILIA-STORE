<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletCheckoutSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_wallet_checkout_uses_server_price_and_is_idempotent(): void
    {
        [$customerId, $token] = $this->customerWithBalance(50000);
        $this->manualProduct(12000);

        $payload = [
            'productSlug' => 'manual-game',
            'packageSku' => 'MG12',
            'destination' => '123456',
            'customerInputs' => [],
            'buyerName' => 'Buyer',
            'buyerEmail' => 'buyer@example.com',
            'buyerPhone' => '+6281234567890',
            'quantity' => 1,
            'idempotencyKey' => '11111111-1111-4111-8111-111111111111',
            'total' => 1,
            'price' => 1,
        ];

        $first = $this->withCredentials()->withUnencryptedCookie('lfamilia_session', $token)
            ->postJson('/api/payments/wallet/create', $payload);
        $first->assertCreated()->assertJsonPath('total', 12000);

        $second = $this->withCredentials()->withUnencryptedCookie('lfamilia_session', $token)
            ->postJson('/api/payments/wallet/create', $payload);
        $second->assertOk()->assertJsonPath('total', 12000);

        $this->assertSame(1, DB::table('orders')->where('customer_id', $customerId)->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('direction', 'debit')->count());
        $this->assertSame(38000, (int) DB::table('customer_users')->where('id', $customerId)->value('balance'));
    }

    public function test_insufficient_wallet_balance_never_marks_order_paid(): void
    {
        [$customerId, $token] = $this->customerWithBalance(5000);
        $this->manualProduct(12000);

        $this->withCredentials()->withUnencryptedCookie('lfamilia_session', $token)
            ->postJson('/api/payments/wallet/create', [
                'productSlug' => 'manual-game',
                'packageSku' => 'MG12',
                'destination' => '123456',
                'customerInputs' => [],
                'buyerName' => 'Buyer',
                'buyerEmail' => 'buyer@example.com',
                'buyerPhone' => '+6281234567890',
                'quantity' => 1,
                'idempotencyKey' => '22222222-2222-4222-8222-222222222222',
            ])
            ->assertStatus(409);

        $this->assertSame(0, DB::table('wallet_transactions')->where('direction', 'debit')->count());
        $this->assertSame(0, DB::table('orders')
            ->where('customer_id', $customerId)
            ->where('payment_status', 'paid')
            ->count());
    }

    /** @return array{0:string,1:string} */
    private function customerWithBalance(int $balance): array
    {
        $customerId = (string) Str::uuid();
        DB::table('customer_users')->insert([
            'id' => $customerId,
            'email' => $customerId.'@example.com',
            'name' => 'Customer',
            'phone' => '+6281234567890',
            'password_hash' => str_repeat('a', 64),
            'password_salt' => str_repeat('b', 32),
            'balance' => $balance,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($balance > 0) {
            DB::table('wallet_transactions')->insert([
                'id' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'direction' => 'credit',
                'amount' => $balance,
                'balance_before' => 0,
                'balance_after' => $balance,
                'reference' => 'seed:'.$customerId,
                'description' => 'Seed test balance',
                'created_at' => now(),
            ]);
        }

        $token = 'session-'.$customerId;
        DB::table('customer_sessions')->insert([
            'id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        return [$customerId, $token];
    }

    private function manualProduct(int $price): void
    {
        $productId = DB::table('products')->insertGetId([
            'slug' => 'manual-game',
            'name' => 'Manual Game',
            'publisher' => '',
            'category' => 'game',
            'initials' => 'MG',
            'accent' => 'lime',
            'input_label' => 'ID',
            'input_placeholder' => '123456',
            'needs_server' => 0,
            'popular' => 0,
            'instant' => 0,
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'manual_timezone' => 'Asia/Jakarta',
            'package_tabs_enabled' => 0,
            'package_tabs_json' => '[]',
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_packages')->insert([
            'product_id' => $productId,
            'sku' => 'MG12',
            'label' => '12',
            'price' => $price,
            'pricing_mode' => 'manual',
            'margin_type' => 'fixed',
            'margin_value' => 0,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
