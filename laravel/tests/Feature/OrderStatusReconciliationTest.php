<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderStatusReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_midtrans_status_refresh_can_authoritatively_settle_pending_order(): void
    {
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'server-key',
            'clientKey' => 'client-key',
        ]);
        config()->set('lfamilia.integrations.midtrans.api_base_url', 'https://api.midtrans.test');

        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id,
            'reference_id' => 'LF260928STATUSABC123',
            'product_slug' => 'manual',
            'product_name' => 'Manual',
            'package_sku' => 'M1',
            'package_label' => '1',
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'destination' => '123456',
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
            'payment_gateway' => 'midtrans',
            'payment_gateway_mode' => 'snap',
            'payment_gateway_environment' => 'sandbox',
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://api.midtrans.test/v2/LF260928STATUSABC123/status' => Http::response([
                'order_id' => 'LF260928STATUSABC123',
                'gross_amount' => '10000.00',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'transaction_id' => 'status-tx-1',
            ], 200),
        ]);

        $this->postJson('/api/orders/status', [
            'referenceId' => 'LF260928STATUSABC123',
        ])->assertOk()->assertJsonPath('order.paymentStatus', 'paid');

        $this->assertDatabaseHas('orders', [
            'id' => $id,
            'payment_status' => 'paid',
            'fulfillment_status' => 'manual_pending',
        ]);
    }

    public function test_provider_amount_mismatch_does_not_mark_order_paid(): void
    {
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'server-key',
            'clientKey' => 'client-key',
        ]);
        config()->set('lfamilia.integrations.midtrans.api_base_url', 'https://api.midtrans.test');

        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id,
            'reference_id' => 'LF260928STATUSBAD123',
            'product_slug' => 'manual',
            'product_name' => 'Manual',
            'package_sku' => 'M1',
            'package_label' => '1',
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'destination' => '123456',
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
            'payment_gateway' => 'midtrans',
            'payment_gateway_mode' => 'snap',
            'payment_gateway_environment' => 'sandbox',
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://api.midtrans.test/v2/LF260928STATUSBAD123/status' => Http::response([
                'order_id' => 'LF260928STATUSBAD123',
                'gross_amount' => '9999.00',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'transaction_id' => 'status-tx-bad',
            ], 200),
        ]);

        $this->postJson('/api/orders/status', [
            'referenceId' => 'LF260928STATUSBAD123',
        ])->assertOk()->assertJsonPath('order.paymentStatus', 'pending');

        $this->assertDatabaseHas('orders', ['id' => $id, 'payment_status' => 'pending']);
    }
}