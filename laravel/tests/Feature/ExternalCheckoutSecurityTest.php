<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalCheckoutSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');

        config()->set('lfamilia.public_base_url', 'https://lfamilia.example');
        config()->set('lfamilia.integrations.midtrans.snap_base_url', 'https://snap.test.invalid');
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'midtrans-server-key',
            'clientKey' => 'midtrans-client-key',
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
            'gateway' => 'midtrans',
            'gateway_config_json' => json_encode([
                'customerFeeEnabled' => 'true',
                'customerFeeBps' => '70',
                'customerFeeFixed' => '0',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'slug' => 'manual-checkout',
            'name' => 'Manual Checkout',
            'publisher' => '',
            'category' => 'game',
            'initials' => 'MC',
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
            'sku' => 'MC100',
            'label' => '100',
            'price' => 10000,
            'pricing_mode' => 'manual',
            'margin_type' => 'fixed',
            'margin_value' => 0,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_external_checkout_ignores_client_price_and_reuses_idempotent_invoice(): void
    {
        Http::fake([
            'https://snap.test.invalid/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-1',
                'redirect_url' => 'https://pay.test.invalid/snap-token-1',
            ], 201),
        ]);

        $payload = [
            'productSlug' => 'manual-checkout',
            'packageSku' => 'MC100',
            'destination' => '123456',
            'customerInputs' => [],
            'buyerName' => 'Buyer',
            'buyerEmail' => 'buyer@example.com',
            'buyerPhone' => '+6281234567890',
            'paymentMethod' => 'qris',
            'paymentChannel' => 'mpm',
            'quantity' => 1,
            'idempotencyKey' => '33333333-3333-4333-8333-333333333333',
            'price' => 1,
            'total' => 1,
        ];

        $first = $this->postJson('/api/payments/auto/create', $payload);
        $first->assertCreated();

        $expectedFee = (int) ceil((10000 * 10000) / (10000 - 70)) - 10000;
        $first->assertJsonPath('sellingPrice', 10000)
            ->assertJsonPath('fee', $expectedFee)
            ->assertJsonPath('total', 10000 + $expectedFee);

        $second = $this->postJson('/api/payments/auto/create', $payload);
        $second->assertOk()->assertJsonPath('total', 10000 + $expectedFee);

        $this->assertSame(1, DB::table('orders')->where('external_checkout_key', $payload['idempotencyKey'])->count());

        Http::assertSentCount(1);
    }
}