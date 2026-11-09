<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\AdminDigiflazzMonitorService;
use App\Services\CheckoutPricing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DigiflazzBalanceGateTest extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    /** Buat produk AUTO_PROVIDER + nominal Digiflazz; kembalikan [paket, mapping]. */
    private function digiflazzPackage(int $sellerPrice, int $priority = 1, ?Product $product = null): array
    {
        $this->suffix ??= bin2hex(random_bytes(4));
        $category = Category::where('slug', 'game')->firstOrFail();
        $category->update(['is_active' => true]);
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();

        $product ??= Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Gerbang Saldo '.$this->suffix,
            'slug' => 'produk-gerbang-saldo-'.$this->suffix.'-'.$priority,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 12,
            'price_markup_type' => 'PERCENT',
            'price_markup_fixed_idr' => 0,
            'is_active' => true,
        ]);
        $sku = 'GATE-'.$this->suffix.'-'.$priority;
        $package = $product->packages()->create([
            'code' => 'GATE'.$this->suffix.$priority,
            'name' => 'Nominal Gerbang '.$priority,
            'pricing_mode' => 'PERCENT',
            'margin_percent' => 12,
            'is_active' => true,
        ]);
        $mapping = ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $provider->id,
            'external_sku' => $sku,
            'cost_idr' => $sellerPrice,
            'store_cost_idr' => $sellerPrice,
            'max_price_idr' => $sellerPrice,
            'is_manual_price' => false,
            'is_active' => true,
            'priority' => $priority,
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', $sku)
            ->update(['price_idr' => $sellerPrice, 'synced_at' => now()]);

        return [$package, $mapping];
    }

    private function balance(?int $balanceIdr): void
    {
        $this->mock(AdminDigiflazzMonitorService::class, function ($mock) use ($balanceIdr): void {
            $mock->shouldReceive('balanceIdr')->andReturn($balanceIdr);
        });
    }

    public function test_nominal_priced_above_balance_is_not_offered_to_customers(): void
    {
        [$package] = $this->digiflazzPackage(126877);
        $this->balance(28000);

        // Checkout customer ditolak dengan pesan ketersediaan yang sudah ada.
        try {
            app(CheckoutPricing::class)->forPackage($package->id);
            $this->fail('Nominal di atas saldo tidak boleh bisa di-checkout.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('package_id', $exception->errors());
        }

        // Daftar nominal customer tidak memuatnya sama sekali (aturan D11).
        $this->assertSame([], app(CheckoutPricing::class)->forPackages([$package->id]));

        // Pratinjau admin tidak digerbang: nominal tetap bisa diperiksa admin.
        $preview = app(CheckoutPricing::class)->previewForPackage($package->id);
        $this->assertSame(126877, $preview['cost_idr']);
    }

    public function test_nominal_priced_within_balance_is_offered(): void
    {
        [$package] = $this->digiflazzPackage(126877);
        $this->balance(200000);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(126877, $quote['cost_idr']);
        $this->assertArrayHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));
    }

    public function test_unreadable_balance_keeps_the_gate_open(): void
    {
        [$package] = $this->digiflazzPackage(126877);
        $this->balance(null);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(126877, $quote['cost_idr']);
    }

    public function test_balance_read_failure_keeps_the_gate_open(): void
    {
        [$package] = $this->digiflazzPackage(126877);
        $this->mock(AdminDigiflazzMonitorService::class, function ($mock): void {
            $mock->shouldReceive('balanceIdr')->andThrow(new \RuntimeException('cache down'));
        });

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(126877, $quote['cost_idr']);
        $this->assertArrayHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));
    }

    public function test_affordable_seller_mapping_is_used_when_the_first_exceeds_balance(): void
    {
        [$package] = $this->digiflazzPackage(126877, 1);

        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $cheapSku = 'GATE-'.$this->suffix.'-CHEAP';
        $cheap = ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $provider->id,
            'external_sku' => $cheapSku,
            'cost_idr' => 20000,
            'store_cost_idr' => 20000,
            'max_price_idr' => 20000,
            'is_manual_price' => false,
            'is_active' => true,
            'priority' => 2,
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', $cheapSku)
            ->update(['price_idr' => 20000, 'synced_at' => now()]);
        $this->balance(28000);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame($cheap->id, $quote['provider_mapping_id']);
        $this->assertSame(20000, $quote['cost_idr']);
    }
}
