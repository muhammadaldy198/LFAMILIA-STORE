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

    public function test_imports_two_suppliers_as_one_manual_game_nominal_and_never_touches_non_games(): void
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
                // One backup source currently in cutoff must still be attached.
                'start_cut_off' => $sku === 'ONCE_TEST_FF5_B' ? '23:45' : '00:00',
                'end_cut_off' => '00:00',
                'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->travelTo(Carbon::parse('2026-10-09 23:50:00', 'Asia/Jakarta'));

        $first = app(OneTimeGameNominalImport::class)->run();
        $this->assertSame(1, $first['games_activated']);
        $this->assertSame(1, $first['packages_created']);
        $this->assertSame(2, $first['mappings_created']);

        $package = $game->packages()->firstOrFail();
        $this->assertTrue($game->fresh()->is_active);
        $this->assertTrue($package->is_active);
        $this->assertSame(5, (int) $package->nominal_value);
        $mappings = $package->mappings()->orderBy('priority')->get();
        $this->assertCount(2, $mappings);
        $this->assertSame(['ONCE_TEST_FF5_A', 'ONCE_TEST_FF5_B'], $mappings->pluck('external_sku')->all());
        $this->assertSame([0, 1], $mappings->pluck('priority')->all());
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
