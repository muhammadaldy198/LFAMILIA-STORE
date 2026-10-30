<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProviderMapping;
use App\Services\DigiflazzPriceOwnership;
use App\Services\CheckoutPricing;
use App\Services\VoucherStockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DigiflazzPriceOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_source_priority_controls_price_ownership_even_with_both_mappings(): void
    {
        DB::table('providers')->whereIn('code', ['DIGIFLAZZ', 'VOUCHER_STOCK'])->update(['is_active' => true]);

        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Mixed price ownership',
            'slug' => 'mixed-price-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 20,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'MIXED-'.bin2hex(random_bytes(4)),
            'name' => 'Mixed nominal',
            'pricing_mode' => 'SELL_PRICE',
            'sell_price_idr' => 15000,
            'is_active' => true,
        ]);
        $digiflazz = ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'MIXED-DG-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'store_cost_idr' => 10000,
            'is_active' => true,
            'priority' => 0,
        ]);
        $voucher = ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'VOUCHER_STOCK')->value('id'),
            'cost_idr' => 10000,
            'store_cost_idr' => 10000,
            'is_active' => true,
            'priority' => 1,
        ]);

        $owner = app(DigiflazzPriceOwnership::class);
        $this->assertTrue($owner->usesDigiflazz($package), 'Digiflazz first: migration must backfill');
        $this->assertFalse($owner->usesDigiflazz($package, ['provider_code' => 'VOUCHER_STOCK']),
            'A voucher selected by checkout overrides historical mapping priority');

        $digiflazz->update(['priority' => 2]);
        $voucher->update(['priority' => 0]);
        $this->assertFalse($owner->usesDigiflazz($package), 'Voucher first: migration must preserve fixed voucher price');

        $digiflazz->update(['is_active' => false]);
        $this->assertFalse($owner->usesDigiflazz($package), 'Inactive Digiflazz mapping never owns active voucher pricing');

        $voucher->update(['is_active' => false]);
        $this->assertTrue($owner->usesDigiflazz($package), 'Unmapped/draft Digiflazz nominal can retain its markup');
    }

    public function test_migrated_sell_price_voucher_cap_prevents_checkout_source_switch(): void
    {
        DB::table('providers')->whereIn('code', ['DIGIFLAZZ', 'VOUCHER_STOCK'])->update(['is_active' => true]);
        $category = Category::where('slug', 'game')->firstOrFail();
        $category->update(['is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Mixed provider price',
            'slug' => 'mixed-provider-price-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 20,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'MIXED-QUOTE-'.bin2hex(random_bytes(3)),
            'name' => 'Mixed price nominal', 'pricing_mode' => 'PERCENT',
            'margin_percent' => 20,
            'is_active' => true,
        ]);
        $package->forceFill(['legacy_voucher_sell_price_idr' => 15000])->save();
        $stockKey = 'mixed-source-'.bin2hex(random_bytes(4));
        app(VoucherStockService::class)->import($stockKey, 'TEST-VOUCHER-CODE');
        $voucher = ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'VOUCHER_STOCK')->value('id'),
            'external_sku' => null,
            'cost_idr' => 16000, 'store_cost_idr' => 16000, 'priority' => 0,
            'fulfillment_config' => ['stock_key' => $stockKey],
            'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'MIXED-QUOTE-DG-'.bin2hex(random_bytes(3)),
            'cost_idr' => 10000, 'store_cost_idr' => 10000, 'priority' => 1,
            'is_active' => true,
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);

        // Voucher at Rp16,000 was excluded by the old SELL_PRICE Rp15,000.
        // Migrating the Digiflazz markup must not let it take priority.
        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('DIGIFLAZZ', $quote['provider_code']);
        $this->assertSame(12000, $quote['subtotal_idr']);

        // When voucher becomes affordable, retain its original Rp15,000
        // selling price, not the Digiflazz nominal percentage.
        $voucher->update(['cost_idr' => 10000, 'store_cost_idr' => 10000]);
        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('VOUCHER_STOCK', $quote['provider_code']);
        $this->assertSame(15000, $quote['subtotal_idr']);
    }
}
