<?php

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AdminManualOrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment(['local', 'testing'])) {
    throw new RuntimeException('Browser fixtures require a local or testing environment.');
}

Queue::fake();
Http::preventStrayRequests();

DB::transaction(function (): void {
    $browserAdmin = AdminUser::updateOrCreate(['email' => 'browser-admin@example.test'], [
        'name' => 'Browser Admin',
        'password' => Hash::make('Browser-admin-test-password-123'),
    ]);
    $browserAdmin->forceFill([
        'role' => 'SUPER_ADMIN',
        'permissions' => null,
        'is_active' => true,
    ])->save();

    User::updateOrCreate(['email' => 'browser-account@example.test'], [
        'name' => 'Browser Account',
        'phone' => '081234567890',
        'password' => Hash::make('Browser-test-password-123'),
        'membership_tier_code' => 'BASIC',
    ]);

    $category = Category::where('slug', 'game')->firstOrFail();

    DB::table('providers')->where('code', 'DIGIFLAZZ')->update([
        'is_active' => true,
        'updated_at' => now(),
    ]);
    $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

    $product = Product::updateOrCreate(
        ['slug' => 'browser-checkout-game'],
        [
            'category_id' => $category->id,
            'name' => 'Browser Checkout Game',
            'publisher' => 'LFAMILIA Regression',
            'description' => 'Fixture khusus browser regression checkout.',
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'nickname_check_enabled' => false,
            'is_active' => true,
        ]
    );

    $product->fields()->updateOrCreate(
        ['field_key' => 'user_id'],
        [
            'label' => 'User ID',
            'placeholder' => 'Masukkan User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]
    );
    $product->fields()->updateOrCreate(
        ['field_key' => 'zone_id'],
        [
            'label' => 'Zone ID',
            'placeholder' => 'Masukkan Zone ID',
            'type' => 'text',
            'is_required' => false,
            'sort_order' => 1,
        ]
    );

    $package = $product->packages()->updateOrCreate(
        ['code' => 'BROWSER100'],
        [
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'sort_order' => 0,
            'is_active' => true,
        ]
    );

    DB::table('provider_mappings')->updateOrInsert(
        [
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
        ],
        [
            'external_sku' => 'BROWSER-SKU-100',
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    DB::table('digiflazz_catalog_items')->updateOrInsert(
        ['buyer_sku_code' => 'BROWSER-SKU-100'],
        [
            'product_name' => 'Browser Checkout Game — 100 Diamonds dengan nama nominal yang panjang',
            'category' => 'Games', 'brand' => 'LFAMILIA Regression', 'type' => 'Umum',
            'seller_name' => 'Seller fixture lokal', 'price_idr' => 10000, 'baseline_price_idr' => 10000,
            'buyer_active' => true, 'seller_active' => true, 'unlimited_stock' => true,
            'stock' => 0, 'multi' => false, 'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]
    );

    $largePackage = $product->packages()->updateOrCreate(
        ['code' => 'BROWSER-LARGE'],
        ['name' => 'Paket dengan nama sangat panjang untuk pemeriksaan mobile dan angka rupiah besar',
            'nominal_value' => 999999, 'sort_order' => 1, 'is_active' => false]
    );
    DB::table('provider_mappings')->updateOrInsert(
        ['product_package_id' => $largePackage->id, 'provider_id' => $providerId],
        ['external_sku' => 'BROWSER-SKU-LARGE', 'cost_idr' => 2599000000, 'max_price_idr' => 2599000000,
            'priority' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    DB::table('vouchers')->updateOrInsert(
        ['code' => 'BROWSER10'],
        ['name' => 'Voucher fixture dengan nama panjang untuk audit tampilan', 'discount_type' => 'PERCENT',
            'discount_value' => 10, 'minimum_total_idr' => 10000, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now()]
    );

    $admin = AdminUser::where('email', 'browser-admin@example.test')->firstOrFail();
    $customer = User::where('email', 'browser-account@example.test')->firstOrFail();
    $orderId = app(AdminManualOrderService::class)->create([
        'customer_name' => 'Pelanggan fixture dengan nama panjang untuk pemeriksaan tampilan',
        'phone' => '081234567890', 'email' => 'browser-account@example.test',
        'product_name' => 'Produk manual fixture dengan nama yang panjang dan mudah terbaca',
        'package_name' => 'Paket browser regression', 'destination' => 'BROWSER-DESTINATION-123456789',
        'total_idr' => 99999999, 'note' => 'Data sintetis lokal, bukan transaksi nyata.',
        'payment_received' => true, 'idempotency_key' => 'browser-ui-fixture-v1',
    ], (int) $admin->id);
    DB::table('orders')->where('id', $orderId)->update(['user_id' => $customer->id]);
    SupportTicket::updateOrCreate(
        ['user_id' => $customer->id, 'subject' => 'Tiket fixture dengan judul panjang untuk audit Admin mobile'],
        ['order_id' => $orderId, 'message' => 'Pesan sintetis khusus regression browser.', 'kind' => 'GENERAL']
    );

    DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
        'is_active' => true,
        'is_maintenance' => false,
        'updated_at' => now(),
    ]);
    DB::table('payment_channels')->where('code', 'manual_qris')->update([
        'is_active' => true,
        'fee_flat_idr' => 500,
        'fee_percent_bps' => 0,
        'updated_at' => now(),
    ]);

    $channelId = DB::table('payment_channels')->where('code', 'manual_qris')->value('id');
    $gatewayId = DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->value('id');

    DB::table('payment_routes')->updateOrInsert(
        [
            'payment_channel_id' => $channelId,
            'payment_gateway_id' => $gatewayId,
        ],
        [
            'provider_channel' => null,
            'configuration' => null,
            'priority' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
});

echo "browser-checkout-game\n";
