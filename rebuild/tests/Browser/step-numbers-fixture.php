<?php

/**
 * Fixture CI untuk bukti penomoran langkah dan lebar field Data Akun.
 *
 * Menyiapkan dua produk tiruan di database tes: produk voucher tanpa Data Akun
 * (pembanding Google Play Indonesia) dan produk game dengan tepat dua field
 * teks + pilihan Server (pembanding Genshin Impact). Pembanding Mobile Legends
 * memakai `browser-checkout-game` dari fixture.php (dua field teks). File ini
 * dipisah dari fixture.php karena file itu sudah di atas 500 baris (AGENTS §13).
 */

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment(['local', 'testing'])) {
    throw new RuntimeException('Browser fixtures require a local or testing environment.');
}

DB::transaction(function (): void {
    $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

    $attachSellablePackage = function (Product $product, string $sku, string $name) use ($providerId): void {
        $package = $product->packages()->updateOrCreate(['code' => $sku], [
            'name' => $name, 'nominal_value' => 1, 'sort_order' => 0,
            'pricing_mode' => 'PERCENT', 'margin_percent' => 10, 'is_active' => true,
        ]);
        DB::table('provider_mappings')->updateOrInsert(
            ['provider_id' => $providerId, 'external_sku' => $sku],
            ['product_package_id' => $package->id, 'cost_idr' => 10000,
                'max_price_idr' => 10000, 'priority' => 1, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('digiflazz_catalog_items')->updateOrInsert(
            ['buyer_sku_code' => $sku],
            ['product_name' => $product->name.' '.$name,
                'category' => 'Games', 'brand' => 'Visual fixture', 'type' => 'Umum',
                'seller_name' => 'Fixture lokal', 'price_idr' => 10000,
                'baseline_price_idr' => 10000, 'buyer_active' => true,
                'seller_active' => true, 'unlimited_stock' => true,
                'stock' => 0, 'multi' => false,
                'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
                'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]
        );
    };

    // Kategori voucher tidak pernah menampilkan Data Akun dan tidak punya field.
    $voucherProduct = Product::updateOrCreate(['slug' => 'browser-steps-voucher'], [
        'category_id' => Category::where('slug', 'voucher')->value('id'),
        'name' => 'Voucher Tanpa Data Akun',
        'publisher' => 'LFAMILIA Regression',
        'description' => 'Fixture visual penomoran langkah tanpa Data Akun.',
        'fulfillment_mode' => 'AUTO_PROVIDER',
        'nickname_check_enabled' => false,
        'is_active' => true,
    ]);
    $voucherProduct->fields()->delete();
    $attachSellablePackage($voucherProduct, 'BROWSER-STEPS-VOUCHER-10', 'Voucher 10K');

    // Tepat dua field campuran: input teks + select, seperti Genshin Impact.
    $twoFieldProduct = Product::updateOrCreate(['slug' => 'browser-steps-two-fields'], [
        'category_id' => Category::where('slug', 'game')->value('id'),
        'name' => 'Genshin Dua Field',
        'publisher' => 'LFAMILIA Regression',
        'description' => 'Fixture visual lebar field UID dan Server.',
        'fulfillment_mode' => 'AUTO_PROVIDER',
        'customer_no_template' => '{{user_id}}|{{server}}',
        'nickname_check_enabled' => false,
        'is_active' => true,
    ]);
    foreach ([
        ['field_key' => 'user_id', 'label' => 'UID', 'type' => 'text',
            'placeholder' => 'Masukkan UID', 'is_required' => true, 'sort_order' => 0],
        ['field_key' => 'server', 'label' => 'Server', 'type' => 'select',
            'placeholder' => 'Pilih server', 'is_required' => true, 'sort_order' => 1,
            'options' => [
                ['value' => 'os_asia', 'label' => 'Asia'],
                ['value' => 'os_usa', 'label' => 'America'],
                ['value' => 'os_euro', 'label' => 'Europe'],
                ['value' => 'os_cht', 'label' => 'TW, HK, MO'],
            ]],
    ] as $field) {
        $twoFieldProduct->fields()->updateOrCreate(['field_key' => $field['field_key']], $field);
    }
    $attachSellablePackage($twoFieldProduct, 'BROWSER-STEPS-GENSHIN-60', '60 Crystals');
});
