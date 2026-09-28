<?php

namespace Tests\Feature;

use App\Services\DigiflazzFulfillmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigiflazzFulfillmentSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');

        config()->set('lfamilia.public_base_url', 'https://lfamilia.example');
        config()->set('lfamilia.integrations.digiflazz.environment', 'production');
        config()->set('lfamilia.integrations.digiflazz.username', 'buyer-user');
        config()->set('lfamilia.integrations.digiflazz.production_api_key', 'provider-secret');
        config()->set('lfamilia.integrations.digiflazz.production_transaction_url', 'https://digiflazz.test.invalid/v1/transaction');
        config()->set('lfamilia.integrations.digiflazz.webhook_secret', 'webhook-secret');
    }

    public function test_paid_order_dispatches_with_server_owned_sku_price_guard_and_signature(): void
    {
        [$orderId, $reference] = $this->paidOrder();

        Http::fake([
            'https://digiflazz.test.invalid/v1/transaction' => Http::response([
                'data' => [
                    'ref_id' => $reference,
                    'buyer_sku_code' => 'DF10',
                    'customer_no' => '123456',
                    'price' => 9000,
                    'message' => 'Transaksi Sukses',
                    'status' => 'Sukses',
                    'rc' => '00',
                    'sn' => 'SN-123',
                ],
            ], 200),
        ]);

        app(DigiflazzFulfillmentService::class)->fulfillOrder($orderId);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'paid',
            'fulfillment_status' => 'success',
            'provider_status' => 'success',
            'provider_serial_number' => 'SN-123',
        ]);

        Http::assertSent(function ($request) use ($reference) {
            return $request->url() === 'https://digiflazz.test.invalid/v1/transaction'
                && $request['username'] === 'buyer-user'
                && $request['buyer_sku_code'] === 'DF10'
                && $request['customer_no'] === '123456'
                && $request['ref_id'] === $reference
                && $request['sign'] === md5('buyer-user'.'provider-secret'.$reference)
                && $request['testing'] === false;
        });
    }

    public function test_signed_webhook_is_idempotent_and_cannot_touch_unpaid_order(): void
    {
        [$paidId, $paidRef] = $this->paidOrder();

        $unpaidId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $unpaidId,
            'reference_id' => 'LF260928UNPAID000001',
            'product_slug' => 'digiflazz-game',
            'product_name' => 'Digi Game',
            'package_sku' => 'DF10',
            'package_label' => '10',
            'provider_code' => 'digiflazz',
            'provider_sku' => 'DF10',
            'fulfillment_type' => 'automatic',
            'target_template' => '{{destination}}',
            'destination' => '654321',
            'customer_no' => '654321',
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '+6281234567890',
            'customer_inputs_json' => '[]',
            'quantity' => 1,
            'base_subtotal' => 10000,
            'subtotal' => 10000,
            'discount_amount' => 0,
            'admin_fee' => 0,
            'total' => 10000,
            'payment_method' => 'qris',
            'payment_channel' => 'mpm',
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
            'provider_ref_id' => 'LF260928UNPAID000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'data' => [
                'ref_id' => $paidRef,
                'status' => 'Sukses',
                'message' => 'Sukses',
                'sn' => 'CALLBACK-SN',
            ],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha1='.hash_hmac('sha1', $raw, 'webhook-secret');

        $headers = [
            'HTTP_X_HUB_SIGNATURE' => $signature,
            'HTTP_X_DIGIFLAZZ_EVENT' => 'create',
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', '/api/fulfillment/digiflazz/callback', [], [], [], $headers, $raw)->assertOk();
        $this->call('POST', '/api/fulfillment/digiflazz/callback', [], [], [], $headers, $raw)->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $paidId,
            'fulfillment_status' => 'success',
            'provider_serial_number' => 'CALLBACK-SN',
        ]);
        $this->assertSame(1, DB::table('order_events')
            ->where('source', 'digiflazz')
            ->where('status', 'success')
            ->count());

        $unpaidPayload = [
            'data' => [
                'ref_id' => 'LF260928UNPAID000001',
                'status' => 'Sukses',
                'message' => 'Sukses',
                'sn' => 'SHOULD-NOT-APPLY',
            ],
        ];
        $unpaidRaw = json_encode($unpaidPayload, JSON_UNESCAPED_SLASHES);
        $unpaidSignature = 'sha1='.hash_hmac('sha1', $unpaidRaw, 'webhook-secret');
        $headers['HTTP_X_HUB_SIGNATURE'] = $unpaidSignature;

        $this->call('POST', '/api/fulfillment/digiflazz/callback', [], [], [], $headers, $unpaidRaw)->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $unpaidId,
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
        ]);
    }

    /** @return array{0:string,1:string} */
    private function paidOrder(): array
    {
        $productId = DB::table('products')->where('slug', 'digiflazz-game')->value('id');
        if (!$productId) {
            $productId = DB::table('products')->insertGetId([
                'slug' => 'digiflazz-game',
                'name' => 'Digi Game',
                'publisher' => '',
                'category' => 'game',
                'initials' => 'DG',
                'accent' => 'lime',
                'input_label' => 'ID',
                'input_placeholder' => '123456',
                'needs_server' => 0,
                'popular' => 1,
                'instant' => 1,
                'fulfillment_type' => 'automatic',
                'target_template' => '{{destination}}',
                'manual_timezone' => 'Asia/Jakarta',
                'package_tabs_enabled' => 0,
                'package_tabs_json' => '[]',
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $packageId = DB::table('product_packages')->insertGetId([
                'product_id' => $productId,
                'sku' => 'DF10',
                'label' => '10',
                'price' => 10000,
                'provider_code' => 'digiflazz',
                'provider_sku' => 'DF10',
                'supplier_price' => 9000,
                'provider_max_price' => 9500,
                'pricing_mode' => 'auto',
                'margin_type' => 'fixed',
                'margin_value' => 500,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('digiflazz_seller_monitor')->insert([
                'package_id' => $packageId,
                'current_price' => 9000,
                'baseline_price' => 9000,
                'buyer_product_status' => 1,
                'seller_product_status' => 1,
                'unlimited_stock' => 1,
                'stock' => 0,
                'multi' => 1,
                'start_cut_off' => '00:00',
                'end_cut_off' => '00:00',
                'health' => 'healthy',
                'last_checked_at' => now(),
            ]);
        }

        $orderId = (string) Str::uuid();
        $reference = 'LF260928'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 14));

        DB::table('orders')->insert([
            'id' => $orderId,
            'reference_id' => $reference,
            'product_slug' => 'digiflazz-game',
            'product_name' => 'Digi Game',
            'package_sku' => 'DF10',
            'package_label' => '10',
            'provider_code' => 'digiflazz',
            'provider_sku' => 'DF10',
            'fulfillment_type' => 'automatic',
            'supplier_cost_snapshot' => 9000,
            'provider_max_price_snapshot' => 9500,
            'target_template' => '{{destination}}',
            'destination' => '123456',
            'customer_no' => '123456',
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '+6281234567890',
            'customer_inputs_json' => '[]',
            'quantity' => 1,
            'base_subtotal' => 10000,
            'subtotal' => 10000,
            'discount_amount' => 0,
            'admin_fee' => 0,
            'total' => 10000,
            'payment_method' => 'qris',
            'payment_channel' => 'mpm',
            'payment_status' => 'paid',
            'fulfillment_status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $reference];
    }
}
