<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProviderMapping;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Keputusan D17.3 (grand opening): guard harga jual admin hanya
 * menghitung mapping AKTIF. Mapping nonaktif tidak boleh memblokir
 * penurunan harga jual. Repo memakai PHPUnit (bukan Pest).
 */
class AdminPackagePriceGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function packageWithMappings(): array
    {
        $admin = $this->createAdmin([
            'name' => 'Price Guard Admin',
            'email' => 'price-guard-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Price Guard Game '.bin2hex(random_bytes(3)),
            'slug' => 'price-guard-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'PG'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'sort_order' => 0,
            'is_active' => true,
            'pricing_mode' => 'SELL_PRICE',
            'sell_price_idr' => 20000,
        ]);
        $providerId = DB::table('providers')->where('code', 'VOUCHER_STOCK')->value('id');
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'PG-ACTIVE-'.bin2hex(random_bytes(3)),
            'cost_idr' => 15000,
            'priority' => 1,
            'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'PG-INACTIVE-'.bin2hex(random_bytes(3)),
            'cost_idr' => 25000,
            'priority' => 2,
            'is_active' => false,
        ]);

        return [$admin, $package];
    }

    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'PG-UPDATED',
            'name' => '100 Diamonds',
            'sort_order' => 0,
            'is_active' => true,
            'pricing_mode' => 'SELL_PRICE',
            'sell_price_idr' => 20000,
        ], $overrides);
    }

    public function test_inactive_mapping_cost_does_not_block_price_reduction(): void
    {
        [$admin, $package] = $this->packageWithMappings();
        $this->actingAs($admin, 'admin');

        // Di bawah modal mapping NONAKTIF (25000) tapi di atas modal
        // mapping AKTIF (15000) — harus lolos.
        $this->put('/admin/catalog/packages/'.$package->id, $this->updatePayload([
            'sell_price_idr' => 18000,
        ]))->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(18000, (int) $package->fresh()->sell_price_idr);
    }

    public function test_active_store_cost_is_used_to_guard_fixed_sell_price(): void
    {
        [$admin, $package] = $this->packageWithMappings();
        $this->actingAs($admin, 'admin');
        $package->mappings()->where('is_active', true)->update(['store_cost_idr' => 19000]);

        // Non-Digiflazz fixed prices must not fall below active store cost.
        $this->put('/admin/catalog/packages/'.$package->id, $this->updatePayload([
            'sell_price_idr' => 18000,
        ]))->assertSessionHasErrors('sell_price_idr');
        $this->assertSame(20000, (int) $package->fresh()->sell_price_idr);
    }

    public function test_active_mapping_cost_still_blocks_price_below_cost(): void
    {
        [$admin, $package] = $this->packageWithMappings();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/catalog/packages/'.$package->id, $this->updatePayload([
            'sell_price_idr' => 14000,
        ]))->assertSessionHasErrors('sell_price_idr');

        $this->assertSame(20000, (int) $package->fresh()->sell_price_idr);
    }
}
