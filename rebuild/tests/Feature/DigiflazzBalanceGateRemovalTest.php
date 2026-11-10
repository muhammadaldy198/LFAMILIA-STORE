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
use Tests\TestCase;

/**
 * Keputusan pemilik 10 Nov 2026 (mencabut PR #386): saldo Digiflazz hanya
 * angka pantau panel. Nominal tidak lagi disembunyikan dari customer atau
 * ditolak saat checkout hanya karena harga seller melebihi saldo toko.
 */
class DigiflazzBalanceGateRemovalTest extends TestCase
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
            'name' => 'Produk Tanpa Gerbang Saldo '.$this->suffix,
            'slug' => 'produk-tanpa-gerbang-saldo-'.$this->suffix.'-'.$priority,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 12,
            'price_markup_type' => 'PERCENT',
            'price_markup_fixed_idr' => 0,
            'is_active' => true,
        ]);
        $sku = 'NOGATE-'.$this->suffix.'-'.$priority;
        $package = $product->packages()->create([
            'code' => 'NOGATE'.$this->suffix.$priority,
            'name' => 'Nominal Tanpa Gerbang '.$priority,
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

    /**
     * Sisa jebakan mutasi: bila suatu hari CheckoutPricing kembali membaca
     * saldo, mock ini membuat gerbang lama menutup (saldo 28.000 < harga
     * seller) dan tes di bawah gagal. Pada kode tanpa gerbang, mock ini
     * tidak pernah terpakai.
     */
    private function balanceWouldCloseTheOldGate(): void
    {
        $this->mock(AdminDigiflazzMonitorService::class, function ($mock): void {
            $mock->shouldReceive('balanceIdr')->andReturn(28000);
        });
    }

    public function test_nominal_priced_above_store_balance_is_offered_to_customers(): void
    {
        // Harga seller 126.877 — jauh di atas saldo toko pada kasus order 111
        // (Rp28.803). Tanpa gerbang, nominal tetap ditawarkan ke customer.
        $this->balanceWouldCloseTheOldGate();
        [$package] = $this->digiflazzPackage(126877);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(126877, $quote['cost_idr']);

        $this->assertArrayHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));

        // Pratinjau admin tetap tersedia seperti sebelumnya.
        $preview = app(CheckoutPricing::class)->previewForPackage($package->id);
        $this->assertSame(126877, $preview['cost_idr']);
    }

    public function test_seller_choice_follows_priority_not_balance(): void
    {
        $this->balanceWouldCloseTheOldGate();
        [$package, $first] = $this->digiflazzPackage(126877, 1);

        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $cheapSku = 'NOGATE-'.$this->suffix.'-CHEAP';
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

        // Seller prioritas pertama menang walau harganya jauh di atas saldo
        // yang dulu akan menyaringnya keluar.
        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame($first->id, $quote['provider_mapping_id']);
        $this->assertSame(126877, $quote['cost_idr']);
        $this->assertNotSame($cheap->id, $quote['provider_mapping_id']);
    }

    public function test_nominal_priced_within_balance_is_still_offered(): void
    {
        [$package] = $this->digiflazzPackage(20000);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(20000, $quote['cost_idr']);
        $this->assertArrayHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));
    }
}
