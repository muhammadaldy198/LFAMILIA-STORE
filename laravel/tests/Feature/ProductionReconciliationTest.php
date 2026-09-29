<?php

namespace Tests\Feature;

use App\Services\ProductionReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_scheduler_reconciles_midtrans_paid_order_without_trusting_local_expiry(): void
    {
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'server-key',
            'clientKey' => 'client-key',
        ]);
        config()->set('lfamilia.integrations.midtrans.api_base_url', 'https://api.midtrans.scheduler.test');

        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id,
            'reference_id' => 'LF260928SCHEDPAID1234',
            'product_slug' => 'manual',
            'product_name' => 'Manual',
            'package_sku' => 'M1',
            'package_label' => '1',
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'destination' => '123',
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
            'gateway_expired_at' => now()->subMinutes(5),
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        Http::fake([
            'https://api.midtrans.scheduler.test/v2/LF260928SCHEDPAID1234/status' => Http::response([
                'order_id' => 'LF260928SCHEDPAID1234',
                'gross_amount' => '10000.00',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'transaction_id' => 'sched-tx-1',
            ], 200),
        ]);

        app(ProductionReconciliationService::class)->run();

        $this->assertDatabaseHas('orders', [
            'id' => $id,
            'payment_status' => 'paid',
            'fulfillment_status' => 'manual_pending',
        ]);
    }

    public function test_midtrans_not_found_is_not_expired_before_safe_window(): void
    {
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'server-key',
            'clientKey' => 'client-key',
        ]);
        config()->set('lfamilia.integrations.midtrans.api_base_url', 'https://api.midtrans.scheduler.test');

        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id,
            'reference_id' => 'LF260928MISSING123456',
            'product_slug' => 'manual',
            'product_name' => 'Manual',
            'package_sku' => 'M1',
            'package_label' => '1',
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'destination' => '123',
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
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        Http::fake([
            'https://api.midtrans.scheduler.test/v2/LF260928MISSING123456/status' => Http::response([
                'status_message' => 'Transaction does not exist.',
            ], 404),
        ]);

        app(ProductionReconciliationService::class)->run();

        $this->assertDatabaseHas('orders', [
            'id' => $id,
            'payment_status' => 'pending',
        ]);
    }
}
