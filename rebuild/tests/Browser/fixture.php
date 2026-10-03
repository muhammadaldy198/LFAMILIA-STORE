<?php

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::transaction(function (): void {
    AdminUser::updateOrCreate(['email' => 'browser-admin@example.test'], [
        'name' => 'Browser Admin',
        'password' => Hash::make('Browser-admin-password-123'),
        'role' => 'SUPER_ADMIN',
        'is_active' => true,
    ]);

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
