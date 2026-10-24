<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Services\DigiflazzAutoSources;
use App\Support\GameNamePrefix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductPackageNominalNameTest extends TestCase
{
    use DatabaseTransactions;

    public function test_digiflazz_import_removes_the_game_name_from_the_nominal_name(): void
    {
        $product = $this->product('Free Fire');
        Provider::where('code', 'DIGIFLAZZ')->firstOrFail()->forceFill(['is_active' => true])->save();
        DB::table('digiflazz_catalog_items')->insert([
            'buyer_sku_code' => 'FF-5D',
            'product_name' => 'Free Fire 5 Diamond',
            'category' => 'Games',
            'brand' => 'Free Fire',
            'type' => 'Umum',
            'seller_name' => 'Seller A',
            'price_idr' => 1000,
            'baseline_price_idr' => 1000,
            'buyer_active' => true,
            'seller_active' => true,
            'unlimited_stock' => true,
            'stock' => 0,
            'is_present' => true,
            'start_cut_off' => '00:00',
            'end_cut_off' => '00:00',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'FF-5D')->firstOrFail();

        $package = app(DigiflazzAutoSources::class)->importGroup(
            $product,
            collect([$item]),
            null,
            false,
            '10',
            false,
            Request::create('/admin/digiflazz/import', 'POST'),
        );

        $this->assertSame('5 Diamond', $package->name);
    }

    public function test_cleanup_migration_only_removes_leading_game_names_and_is_idempotent(): void
    {
        $product = $this->product('Free Fire');
        $prefixed = $this->package($product, 'FF-PREFIXED', 'Free Fire 5 Diamond');
        $clean = $this->package($product, 'FF-CLEAN', '5 Diamond');
        $middle = $this->package($product, 'FF-MIDDLE', 'Bonus Free Fire 5 Diamond');
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        DB::table('provider_mappings')->insert([
            'product_package_id' => $prefixed->id,
            'provider_id' => $provider->id,
            'external_sku' => 'FF-PREFIXED',
            'cost_idr' => 1100,
            'max_price_idr' => 1200,
            'priority' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('digiflazz_catalog_items')->insert([
            'buyer_sku_code' => 'FF-PREFIXED',
            'product_name' => 'Free Fire 5 Diamond',
            'category' => 'Games',
            'brand' => 'Free Fire',
            'type' => 'Umum',
            'seller_name' => 'Seller A',
            'price_idr' => 1100,
            'baseline_price_idr' => 1100,
            'buyer_active' => true,
            'seller_active' => true,
            'unlimited_stock' => false,
            'stock' => 17,
            'is_present' => true,
            'start_cut_off' => '00:00',
            'end_cut_off' => '00:00',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migrationPath = database_path('migrations/2026_10_24_020000_strip_game_name_from_package_names.php');
        $this->assertFileExists($migrationPath);
        $migration = require $migrationPath;

        $migration->up();
        $firstReport = $migration->report;
        $afterFirstRun = DB::table('product_packages')->whereIn('id', [$prefixed->id, $clean->id, $middle->id])
            ->orderBy('id')->get(['id', 'name', 'margin_percent', 'sell_price_idr', 'is_active'])->all();
        $migration->up();
        $secondReport = $migration->report;
        $afterSecondRun = DB::table('product_packages')->whereIn('id', [$prefixed->id, $clean->id, $middle->id])
            ->orderBy('id')->get(['id', 'name', 'margin_percent', 'sell_price_idr', 'is_active'])->all();

        $this->assertSame(['5 Diamond', '5 Diamond', 'Bonus Free Fire 5 Diamond'], array_column($afterFirstRun, 'name'));
        $this->assertEquals($afterFirstRun, $afterSecondRun);
        $this->assertGreaterThanOrEqual(3, $firstReport['scanned']);
        $this->assertGreaterThanOrEqual(1, $firstReport['renamed']);
        // Second run must find nothing left to rename.
        $this->assertSame(0, $secondReport['renamed']);
        $this->assertSame('10.0000', $afterFirstRun[0]->margin_percent);
        $this->assertSame(1500, $afterFirstRun[0]->sell_price_idr);
        $this->assertSame(1100, (int) DB::table('provider_mappings')->where('external_sku', 'FF-PREFIXED')->value('cost_idr'));
        $this->assertSame(17, (int) DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'FF-PREFIXED')->value('stock'));
    }

    public function test_strip_only_removes_a_standalone_leading_game_name(): void
    {
        // Game name glued to another word is not a prefix.
        $this->assertSame('Free FireMAX 5 Diamond', GameNamePrefix::strip('Free FireMAX 5 Diamond', 'Free Fire'));
        // The longest matching game name wins.
        $this->assertSame('5 Diamond', GameNamePrefix::strip('Free Fire MAX 5 Diamond', 'Free Fire', 'Free Fire MAX'));
        // Already clean labels and game names in the middle are untouched.
        $this->assertSame('5 Diamond', GameNamePrefix::strip('5 Diamond', 'Free Fire'));
        $this->assertSame('Bonus Free Fire 5 Diamond', GameNamePrefix::strip('Bonus Free Fire 5 Diamond', 'Free Fire'));
        // A label that is only the game name is kept, never emptied.
        $this->assertSame('Free Fire', GameNamePrefix::strip('Free Fire', 'Free Fire'));
    }

    private function product(string $name): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'nominal-name-test'],
            ['name' => 'Nominal Name', 'icon' => 'gamepad', 'is_active' => true],
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => 'nominal-name-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
    }

    private function package(Product $product, string $code, string $name): ProductPackage
    {
        return ProductPackage::create([
            'product_id' => $product->id,
            'code' => $code,
            'name' => $name,
            'nominal_value' => 5,
            'sort_order' => 0,
            'is_active' => true,
            'pricing_mode' => 'PERCENT',
            'margin_percent' => 10,
            'sell_price_idr' => 1500,
        ]);
    }
}
