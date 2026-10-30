<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProviderMapping;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzPriceOwnership;
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

        // Explicit admin edits replace the retained voucher cap while leaving
        // Digiflazz's independent 20% nominal markup unchanged.
        $admin = $this->createAdmin([
            'name' => 'Voucher price owner',
            'email' => 'voucher-price-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => bcrypt('a-test-password'),
            'role' => 'SUPER_ADMIN', 'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');
        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => $package->code,
            'name' => $package->name,
            'sort_order' => 0,
            'is_active' => true,
            'pricing_mode' => 'SELL_PRICE',
            'sell_price_idr' => 18000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(18000, (int) $package->fresh()->legacy_voucher_sell_price_idr);
        $this->assertSame('PERCENT', $package->fresh()->pricing_mode);
        $this->assertSame(18000, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);

        $voucher->update(['is_active' => false]);
        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('DIGIFLAZZ', $quote['provider_code']);
        $this->assertSame(12000, $quote['subtotal_idr']);
    }
    public function test_voucher_stock_exhaustion_never_changes_digiflazz_nominal_markup(): void
    {
        DB::table('providers')->whereIn('code', ['DIGIFLAZZ', 'VOUCHER_STOCK'])->update(['is_active' => true]);
        $category = Category::where('slug', 'game')->firstOrFail();
        $category->update(['is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mixed markup source',
            'slug' => 'mixed-markup-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 20,
            'price_markup_type' => 'PERCENT',
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'MIXED-MARKUP-'.bin2hex(random_bytes(4)),
            'name' => 'Mixed 10 diamonds',
            'pricing_mode' => 'PERCENT',
            'margin_percent' => 6,
            'is_active' => true,
        ]);
        $stockKey = 'mixed-markup-'.bin2hex(random_bytes(4));
        app(VoucherStockService::class)->import($stockKey, 'MIXED-MARKUP-VOUCHER');
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'VOUCHER_STOCK')->value('id'),
            'cost_idr' => 10000,
            'store_cost_idr' => 10000,
            'priority' => 0,
            'fulfillment_config' => ['stock_key' => $stockKey],
            'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'MIXED-MARKUP-DG-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'store_cost_idr' => 10000,
            'priority' => 1,
            'is_active' => true,
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);

        (require database_path('migrations/2026_10_31_000002_move_digiflazz_markup_to_nominals.php'))->up();
        $this->assertSame('PERCENT', $package->fresh()->pricing_mode);
        $this->assertEquals(6, (float) $package->fresh()->margin_percent);
        $this->assertSame('PERCENT', $package->fresh()->digiflazz_markup_mode);
        $this->assertEquals(20, (float) $package->fresh()->digiflazz_markup_percent);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('VOUCHER_STOCK', $quote['provider_code']);
        $this->assertSame(10600, $quote['subtotal_idr']);

        DB::table('voucher_stock_codes')->where('stock_key', $stockKey)
            ->update(['status' => 'RESERVED']);
        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('DIGIFLAZZ', $quote['provider_code']);
        $this->assertSame(12000, $quote['subtotal_idr']);

        $admin = $this->createAdmin([
            'name' => 'Mixed markup admin',
            'email' => 'mixed-markup-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => bcrypt('test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');
        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => $package->code,
            'name' => $package->name,
            'sort_order' => 0,
            'is_active' => true,
            'pricing_mode' => 'FIXED',
            'margin_fixed_idr' => 3000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('PERCENT', $package->fresh()->pricing_mode);
        $this->assertSame('FIXED', $package->fresh()->digiflazz_markup_mode);
        $this->assertSame(13000, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);

        DB::table('voucher_stock_codes')->where('stock_key', $stockKey)
            ->update(['status' => 'AVAILABLE']);
        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('VOUCHER_STOCK', $quote['provider_code']);
        $this->assertSame(10600, $quote['subtotal_idr']);
    }

}
