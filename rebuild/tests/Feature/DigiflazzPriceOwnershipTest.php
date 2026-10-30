<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProviderMapping;
use App\Services\DigiflazzPriceOwnership;
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
}
