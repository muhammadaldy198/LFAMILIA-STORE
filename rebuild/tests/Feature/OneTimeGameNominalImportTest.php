<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\OneTimeGameNominalImport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OneTimeGameNominalImportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_importer_refreshes_selected_sku_with_inactive_historical_sibling(): void
    {
        $gameId = DB::table('categories')->where('slug', 'game')->value('id');
        $this->assertNotNull($gameId);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $this->assertNotNull($providerId);
        $product = Product::create([
            'category_id' => $gameId,
            'name' => 'Historical refresh '.bin2hex(random_bytes(4)),
            'slug' => 'historical-refresh-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'HISTORICAL_ONE', 'name' => '5 Diamond',
            'group_name' => 'Umum', 'nominal_value' => 5,
            'is_active' => true, 'sort_order' => 0,
        ]);
        DB::table('provider_mappings')->insert([
            ['product_package_id' => $package->id, 'provider_id' => $providerId,
                'external_sku' => 'historical-refresh-A', 'cost_idr' => 900,
                'store_cost_idr' => 918, 'max_price_idr' => 918,
                'is_active' => true, 'priority' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_package_id' => $package->id, 'provider_id' => $providerId,
                'external_sku' => 'historical-refresh-B', 'cost_idr' => 950,
                'store_cost_idr' => 969, 'max_price_idr' => 969,
                'is_active' => false, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $item = (object) [
            'buyer_sku_code' => 'historical-refresh-A',
            'product_name' => '5 Diamond',
            'brand' => $product->name,
            'type' => 'Umum', 'price_idr' => 900,
            'buyer_active' => true, 'seller_active' => true,
            'unlimited_stock' => true, 'stock' => 0,
            'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
            'synced_at' => now(),
        ];
        // The importer should be idempotent even if old inactive rows remain.
        app(\App\Services\DigiflazzAutoSources::class)->importGroup(
            $product, collect([$item]), null, true, '10', false,
            \Illuminate\Http\Request::create('/internal/legacy-refresh', 'POST')
        );
        $this->assertSame(1, $package->mappings()->where('is_active', true)->count());
        $this->assertFalse((bool) $package->mappings()->where('external_sku', 'historical-refresh-B')->value('is_active'));
    }

    public function test_imports_one_selected_sku_per_nominal_and_never_touches_non_games(): void
    {
        $now = now();
        $gameId = DB::table('categories')->where('slug', 'game')->value('id');
        if (! $gameId) {
            $gameId = DB::table('categories')->insertGetId([
                'name' => 'Top Up Game', 'slug' => 'game', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $voucherId = DB::table('categories')->where('slug', 'voucher')->value('id');
        if (! $voucherId) {
            $voucherId = DB::table('categories')->insertGetId([
                'name' => 'Voucher', 'slug' => 'voucher', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $this->assertNotNull($providerId);
        DB::table('providers')->where('id', $providerId)->update(['is_active' => true]);

        $game = Product::create([
            'category_id' => $gameId, 'name' => 'Free Fire',
            'slug' => 'manual-once-game-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => '10.0000',
            'is_active' => false,
        ]);
        $game->fields()->create([
            'field_key' => 'destination', 'label' => 'Player ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $voucher = Product::create([
            'category_id' => $voucherId, 'name' => 'Free Fire',
            'slug' => 'manual-once-voucher-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => '10.0000',
            'is_active' => false,
        ]);

        foreach (['ONCE_TEST_FF5_A' => 900, 'ONCE_TEST_FF5_B' => 950] as $sku => $price) {
            DB::table('digiflazz_catalog_items')->insert([
                'buyer_sku_code' => $sku,
                'product_name' => 'Free Fire 5 Diamond',
                'category' => 'Games', 'brand' => 'FREE FIRE', 'type' => 'Umum',
                'seller_name' => 'Supplier test',
                'price_idr' => $price, 'baseline_price_idr' => $price,
                'buyer_active' => true, 'seller_active' => true,
                'unlimited_stock' => true, 'stock' => 0, 'multi' => false,
                // The second seller SKU is not imported as a backup.
                'start_cut_off' => $sku === 'ONCE_TEST_FF5_B' ? '23:45' : '00:00',
                'end_cut_off' => '00:00',
                'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->travelTo(Carbon::parse('2026-10-09 23:50:00', 'Asia/Jakarta'));

        $first = app(OneTimeGameNominalImport::class)->run();
        $this->assertSame(1, $first['games_activated']);
        $this->assertSame(1, $first['packages_created']);
        $this->assertSame(1, $first['mappings_created']);

        $package = $game->packages()->firstOrFail();
        $this->assertTrue($game->fresh()->is_active);
        $this->assertTrue($package->is_active);
        $this->assertSame(5, (int) $package->nominal_value);
        $mappings = $package->mappings()->orderBy('priority')->get();
        $this->assertCount(1, $mappings);
        $this->assertSame(['ONCE_TEST_FF5_A'], $mappings->pluck('external_sku')->all());
        $this->assertSame([0], $mappings->pluck('priority')->all());
        foreach ($mappings as $mapping) {
            $this->assertTrue($mapping->is_active);
            $this->assertSame('{{destination}}', $mapping->fulfillment_config['customer_no_template']);
            $this->assertArrayNotHasKey('auto_source_group', $mapping->fulfillment_config);
        }

        $second = app(OneTimeGameNominalImport::class)->run();
        $this->assertSame(0, $second['packages_created']);
        $this->assertSame(0, $second['mappings_created']);
        $this->assertSame(1, $game->packages()->count());
        $this->assertFalse($voucher->fresh()->is_active);
        $this->assertSame(0, $voucher->packages()->count());
    }
}
