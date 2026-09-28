<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductAndOrderSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_public_catalog_hides_unavailable_automatic_packages(): void
    {
        $productId = DB::table('products')->insertGetId([
            'slug' => 'game-test',
            'name' => 'Game Test',
            'publisher' => '',
            'category' => 'game',
            'initials' => 'GT',
            'accent' => 'lime',
            'input_label' => 'ID',
            'input_placeholder' => '123',
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
            'sku' => 'GT10',
            'label' => '10',
            'price' => 10000,
            'provider_code' => 'digiflazz',
            'provider_sku' => 'SKU10',
            'pricing_mode' => 'auto',
            'margin_type' => 'fixed',
            'margin_value' => 0,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonCount(0, 'products');

        DB::table('digiflazz_seller_monitor')->insert([
            'package_id' => $packageId,
            'buyer_product_status' => 1,
            'seller_product_status' => 1,
            'unlimited_stock' => 1,
            'stock' => 0,
            'start_cut_off' => '00:00',
            'end_cut_off' => '00:00',
            'health' => 'healthy',
            'last_checked_at' => now(),
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('products.0.slug', 'game-test')
            ->assertJsonPath('products.0.packages.0.id', 'GT10');
    }

    public function test_recent_orders_do_not_expose_full_invoice(): void
    {
        DB::table('orders')->insert([
            'id' => 'order-1',
            'reference_id' => 'LF-20260928-ABCDEF123456',
            'product_slug' => 'game-test',
            'product_name' => 'Game Test',
            'package_sku' => 'GT10',
            'package_label' => '10',
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'destination' => '123456',
            'buyer_name' => 'Pembeli',
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
            'payment_channel' => 'qris',
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/orders/search')
            ->assertOk()
            ->assertJsonPath('transactions.0.referenceId', null)
            ->assertJsonMissing(['maskedReferenceId' => 'LF-20260928-ABCDEF123456']);
    }
}
