<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\CheckoutPricing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DigiflazzSellerMarkupTest extends TestCase
{
    use DatabaseTransactions;

    public function test_digiflazz_uses_current_seller_price_and_product_markup_not_legacy_cost_or_fixed_price(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $category->update(['is_active' => true]);
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $provider->update(['is_active' => true]);
        $suffix = bin2hex(random_bytes(4));

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Nama Toko Tetap '.$suffix,
            'slug' => 'nama-toko-tetap-'.$suffix,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 12,
            'price_markup_type' => 'PERCENT',
            'price_markup_fixed_idr' => 0,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'SELLER'.$suffix,
            'name' => 'Nama Nominal Toko',
            'pricing_mode' => 'SELL_PRICE',
            'sell_price_idr' => 9000,
            'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $provider->id,
            'external_sku' => 'SELLER-'.$suffix,
            'cost_idr' => 8000,
            'store_cost_idr' => 8000,
            'max_price_idr' => 8000,
            'is_manual_price' => true,
            'is_active' => true,
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'SELLER-'.$suffix)
            ->update(['price_idr' => 12000, 'synced_at' => now()]);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(12000, $quote['cost_idr']);
        $this->assertSame(1440, $quote['margin_idr']);
        $this->assertSame(13440, $quote['subtotal_idr']);
        $this->assertSame(12000, $quote['max_price_idr']);

        $product->update(['price_markup_type' => 'FIXED', 'price_markup_fixed_idr' => 1500]);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'SELLER-'.$suffix)
            ->update(['price_idr' => 13000, 'synced_at' => now()]);
        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(13000, $quote['cost_idr']);
        $this->assertSame(1500, $quote['margin_idr']);
        $this->assertSame(14500, $quote['subtotal_idr']);
        $this->assertSame('Nama Toko Tetap '.$suffix, $product->fresh()->name);
        $this->assertSame('Nama Nominal Toko', $package->fresh()->name);
    }
}
