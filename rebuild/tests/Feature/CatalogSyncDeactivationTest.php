<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\DigiflazzCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogSyncDeactivationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'sync deactivation',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('sync-deactivation-only'),
            'role' => 'SUPER_ADMIN',
            'permissions' => [],
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function product(string $mode): Product
    {
        return Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Sync '.$mode.' '.bin2hex(random_bytes(3)),
            'slug' => 'sync-'.strtolower($mode).'-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => $mode,
            'margin_percent' => 10,
            'is_active' => true,
        ]);
    }

    private function package(Product $product, string $code): ProductPackage
    {
        return ProductPackage::create([
            'product_id' => $product->id,
            'code' => $code.'_'.bin2hex(random_bytes(3)),
            'name' => 'Nominal '.$code,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function mapping(ProductPackage $package, string $providerCode, ?string $sku, bool $active = true, int $priority = 0): ProviderMapping
    {
        return ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => Provider::where('code', $providerCode)->value('id'),
            'external_sku' => $sku,
            'cost_idr' => 10000,
            'max_price_idr' => 10000,
            'priority' => $priority,
            'is_active' => $active,
        ]);
    }

    private function fakeCatalog(array $rows): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'is_active' => true,
            'config_ciphertext' => [
                'username' => 'sync-review',
                'api_key' => 'test-fixture-value',
                'base_url' => 'https://digiflazz-sync.test',
            ],
        ]);
        Http::swap(new Factory);
        Http::fake([
            'https://digiflazz-sync.test/v1/price-list' => Http::response(['data' => $rows]),
            'https://digiflazz-sync.test/v1/cek-saldo' => Http::response(['data' => ['deposit' => 1000]]),
        ]);
    }

    private function row(string $sku): array
    {
        return [
            'buyer_sku_code' => $sku,
            'product_name' => 'Sync '.$sku,
            'price' => 10000,
            'category' => 'Games',
            'brand' => 'Sync',
            'type' => 'Umum',
            'seller_name' => 'Seller A',
            'buyer_product_status' => true,
            'seller_product_status' => true,
            'unlimited_stock' => true,
            'stock' => 0,
            'multi' => false,
            'start_cut_off' => '00:00',
            'end_cut_off' => '00:00',
            'desc' => 'sync deactivation',
        ];
    }

    private function state(ProductPackage $package): array
    {
        $fresh = DB::table('product_packages')->where('id', $package->id)->first();

        return ['active' => (bool) $fresh->is_active, 'marker' => (bool) $fresh->disabled_by_sync];
    }

    public function test_sync_restores_only_one_sku_when_historic_nominal_has_two_mappings(): void
    {
        $this->login();
        $package = $this->package($this->product('AUTO_PROVIDER'), 'RESTORE_ONE');
        $selected = $this->mapping($package, 'DIGIFLAZZ', 'restore-one-selected', false, 0);
        $oldBackup = $this->mapping($package, 'DIGIFLAZZ', 'restore-one-backup', false, 1);
        DB::table('provider_mappings')->whereIn('id', [$selected->id, $oldBackup->id])
            ->update(['disabled_by_sync' => true]);
        DB::table('product_packages')->where('id', $package->id)
            ->update(['is_active' => false, 'disabled_by_sync' => true]);

        // Send the historical secondary row FIRST. Supplier response order
        // must not change the selected SKU or reactivate both mappings.
        $this->fakeCatalog([$this->row('restore-one-backup'), $this->row('restore-one-selected')]);
        app(DigiflazzCatalogService::class)->sync();

        $this->assertDatabaseHas('provider_mappings', [
            'id' => $selected->id, 'is_active' => true, 'disabled_by_sync' => false,
        ]);
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $oldBackup->id, 'is_active' => false, 'disabled_by_sync' => true,
        ]);
        $this->assertSame(['active' => true, 'marker' => false], $this->state($package));
        $this->assertSame(1, DB::table('provider_mappings')
            ->where('product_package_id', $package->id)->where('is_active', true)->count());

        // Subsequent scheduled sync cannot re-enable the historic backup.
        app(DigiflazzCatalogService::class)->sync();
        $this->assertFalse((bool) $oldBackup->fresh()->is_active);
    }

    public function test_sync_restores_admin_selected_sku_even_if_disabled_old_sku_has_higher_priority(): void
    {
        $this->login();
        $package = $this->package($this->product('AUTO_PROVIDER'), 'CHOSEN');
        $old = $this->mapping($package, 'DIGIFLAZZ', 'chosen-old-priority-zero', false, 0);
        $chosen = $this->mapping($package, 'DIGIFLAZZ', 'chosen-new-priority-one', true, 1);
        // The admin deliberately replaced the old SKU without reordering
        // historical priorities. Keep the new SKU in DB during absence.
        DB::table('orders')->insert([
            'order_number' => 'CHOICE-'.bin2hex(random_bytes(5)), 'user_id' => null,
            'product_id' => $package->product_id, 'product_package_id' => $package->id,
            'provider_mapping_id' => $chosen->id, 'status' => 'PENDING_PAYMENT',
            'currency' => 'IDR',
            'customer_input' => json_encode(['player_id' => '1'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000, 'margin_idr' => 0, 'discount_idr' => 0,
            'fee_idr' => 0, 'total_idr' => 10000,
            'idempotency_key' => 'chosen-'.bin2hex(random_bytes(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // B temporarily disappears; A remains known but disabled by admin.
        $this->fakeCatalog([$this->row('chosen-old-priority-zero')]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $chosen->id, 'is_active' => false, 'disabled_by_sync' => true,
        ]);
        $this->assertSame(['active' => false, 'marker' => true], $this->state($package));

        // Return B first; only B is allowed to recover, never old A.
        $this->fakeCatalog([$this->row('chosen-new-priority-one'), $this->row('chosen-old-priority-zero')]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $chosen->id, 'is_active' => true, 'disabled_by_sync' => false,
        ]);
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $old->id, 'is_active' => false, 'disabled_by_sync' => false,
        ]);
        $this->assertSame(['active' => true, 'marker' => false], $this->state($package));
    }

    public function test_store_cost_backfill_is_idempotent_and_skips_rows_without_a_reference(): void
    {
        $this->login();
        $package = $this->package($this->product('AUTO_PROVIDER'), 'BACKFILL');
        $filledId = $this->mapping($package, 'DIGIFLAZZ', 'backfill-with-price')->id;
        $skippedId = $this->mapping($package, 'DIGIFLAZZ', 'backfill-no-price')->id;
        $manualPackage = $this->package($this->product('MANUAL'), 'MANUAL_BACKFILL');
        $manualId = $this->mapping($manualPackage, 'MANUAL', null)->id;
        $voucherId = $this->mapping($package, 'VOUCHER_STOCK', 'voucher-backfill')->id;
        DB::table('provider_mappings')->whereIn('id', [$manualId, $voucherId])->update([
            'cost_idr' => 10000, 'seller_reference_price_idr' => 10000,
            'store_cost_idr' => null, 'max_price_idr' => 12000,
        ]);
        DB::table('provider_mappings')->where('id', $filledId)->update([
            'cost_idr' => 10001, 'seller_reference_price_idr' => 10001,
            'store_cost_idr' => null, 'max_price_idr' => 12000,
        ]);
        DB::table('provider_mappings')->where('id', $skippedId)->update([
            'cost_idr' => null, 'seller_reference_price_idr' => null,
            'store_cost_idr' => null, 'max_price_idr' => null,
        ]);

        $migration = require database_path('migrations/2026_10_25_000000_backfill_store_cost_for_legacy_mappings.php');
        $migration->up();
        $this->assertSame(10202, (int) DB::table('provider_mappings')->where('id', $filledId)->value('store_cost_idr'));
        $this->assertNull(DB::table('provider_mappings')->where('id', $skippedId)->value('store_cost_idr'));
        foreach ([$manualId, $voucherId] as $untouchedId) {
            $this->assertDatabaseHas('provider_mappings', [
                'id' => $untouchedId, 'store_cost_idr' => null,
                'cost_idr' => 10000, 'max_price_idr' => 12000,
            ]);
        }
        $migration->up();
        $this->assertSame(10202, (int) DB::table('provider_mappings')->where('id', $filledId)->value('store_cost_idr'));
    }

    public function test_legacy_mapping_sync_sets_store_cost_once_and_preserves_max_price(): void
    {
        $this->login();
        $package = $this->package($this->product('AUTO_PROVIDER'), 'LEGACY_LOCK');
        $mapping = $this->mapping($package, 'DIGIFLAZZ', 'legacy-lock-sku');
        DB::table('provider_mappings')->where('id', $mapping->id)->update([
            'store_cost_idr' => null, 'seller_reference_price_idr' => null, 'cost_idr' => 10000,
            'max_price_idr' => 11000,
        ]);
        $this->fakeCatalog([$this->row('legacy-lock-sku')]);

        app(DigiflazzCatalogService::class)->sync();

        $fresh = DB::table('provider_mappings')->where('id', $mapping->id)->first();
        $this->assertSame(10000, (int) $fresh->store_cost_idr);
        $this->assertSame(10000, (int) $fresh->max_price_idr);
    }

    /** (a) + (e): MANUAL dan VOUCHER_STOCK tidak pernah jadi kandidat sinkron. */
    public function test_manual_and_voucher_nominal_survive_a_sync_that_removes_a_digiflazz_sku(): void
    {
        $this->login();

        $manual = $this->package($this->product('MANUAL'), 'MANUAL_SRC');
        $this->mapping($manual, 'MANUAL', null);
        $manualNoSource = $this->package($this->product('MANUAL'), 'MANUAL_NOSRC');
        $voucher = $this->package($this->product('AUTO_PROVIDER'), 'VOUCHER_SRC');
        $this->mapping($voucher, 'VOUCHER_STOCK', null);

        $auto = $this->package($this->product('AUTO_PROVIDER'), 'DIGI_GONE');
        $this->mapping($auto, 'DIGIFLAZZ', 'sync-gone');
        DB::table('digiflazz_catalog_items')->insert([
            'buyer_sku_code' => 'sync-gone', 'product_name' => 'Tmp', 'category' => 'Games',
            'brand' => 'Sync', 'type' => 'Umum', 'seller_name' => 'Seller A', 'price_idr' => 10000,
            'baseline_price_idr' => 10000, 'buyer_active' => true, 'seller_active' => true,
            'unlimited_stock' => true, 'stock' => 0, 'multi' => false,
            'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->fakeCatalog([$this->row('sync-other')]);
        app(DigiflazzCatalogService::class)->sync();

        $this->assertSame(['active' => true, 'marker' => false], $this->state($manual), 'manual with source');
        $this->assertSame(['active' => true, 'marker' => false], $this->state($manualNoSource), 'manual without source');
        $this->assertSame(['active' => true, 'marker' => false], $this->state($voucher), 'voucher stock');
        $this->assertSame(['active' => false, 'marker' => true], $this->state($auto), 'digiflazz lost sku');
    }

    /** (a): satu sumber aktif lain (voucher) melindungi nominal dari penonaktifan. */
    public function test_nominal_with_an_active_voucher_source_is_not_disabled_when_its_digiflazz_sku_disappears(): void
    {
        $this->login();

        $package = $this->package($this->product('AUTO_PROVIDER'), 'MIXED');
        $this->mapping($package, 'DIGIFLAZZ', 'sync-mixed-gone');
        $this->mapping($package, 'VOUCHER_STOCK', null, true, 1);
        DB::table('digiflazz_catalog_items')->insert([
            'buyer_sku_code' => 'sync-mixed-gone', 'product_name' => 'Tmp', 'category' => 'Games',
            'brand' => 'Sync', 'type' => 'Umum', 'seller_name' => 'Seller A', 'price_idr' => 10000,
            'baseline_price_idr' => 10000, 'buyer_active' => true, 'seller_active' => true,
            'unlimited_stock' => true, 'stock' => 0, 'multi' => false,
            'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->fakeCatalog([$this->row('sync-other')]);
        app(DigiflazzCatalogService::class)->sync();

        $this->assertSame(['active' => true, 'marker' => false], $this->state($package));
    }

    /** (b): nominal yang dimatikan admin (dengan riwayat mapping) tetap mati saat SKU kembali. */
    public function test_nominal_disabled_by_admin_with_historical_mapping_stays_off_when_the_sku_returns(): void
    {
        $this->login();

        $package = $this->package($this->product('AUTO_PROVIDER'), 'ADMIN_OFF');
        $mapping = $this->mapping($package, 'DIGIFLAZZ', 'sync-admin-off');
        DB::table('orders')->insert([
            'order_number' => 'SYNC-'.bin2hex(random_bytes(5)), 'user_id' => null,
            'product_id' => $package->product_id, 'product_package_id' => $package->id,
            'provider_mapping_id' => $mapping->id, 'status' => 'PENDING_PAYMENT', 'currency' => 'IDR',
            'customer_input' => json_encode(['player_id' => '1'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000, 'margin_idr' => 0, 'discount_idr' => 0, 'fee_idr' => 0, 'total_idr' => 10000,
            'idempotency_key' => 'sync-'.bin2hex(random_bytes(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Admin mematikan nominalnya sendiri.
        DB::table('product_packages')->where('id', $package->id)->update(['is_active' => false]);

        $this->fakeCatalog([$this->row('sync-other')]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertSame(['active' => false, 'marker' => false], $this->state($package), 'after sku removal');

        $this->fakeCatalog([$this->row('sync-admin-off')]);
        app(DigiflazzCatalogService::class)->sync();

        $this->assertDatabaseHas('provider_mappings', ['id' => $mapping->id, 'is_active' => true, 'disabled_by_sync' => false]);
        $this->assertSame(['active' => false, 'marker' => false], $this->state($package), 'after sku returns');
    }

    /** (d): reaktivasi nominal wajib butuh sumber yang benar-benar aktif kembali. */
    public function test_nominal_stays_off_when_the_returning_sku_source_is_still_disabled_by_admin(): void
    {
        $this->login();

        $package = $this->package($this->product('AUTO_PROVIDER'), 'HOLE');
        $primary = $this->mapping($package, 'DIGIFLAZZ', 'sync-hole-primary');
        $backup = $this->mapping($package, 'DIGIFLAZZ', 'sync-hole-backup', true, 1);
        DB::table('digiflazz_catalog_items')->insert([
            ['buyer_sku_code' => 'sync-hole-primary', 'product_name' => 'Tmp', 'category' => 'Games',
                'brand' => 'Sync', 'type' => 'Umum', 'seller_name' => 'Seller A', 'price_idr' => 10000,
                'baseline_price_idr' => 10000, 'buyer_active' => true, 'seller_active' => true,
                'unlimited_stock' => true, 'stock' => 0, 'multi' => false,
                'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
                'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['buyer_sku_code' => 'sync-hole-backup', 'product_name' => 'Tmp', 'category' => 'Games',
                'brand' => 'Sync', 'type' => 'Umum', 'seller_name' => 'Seller A', 'price_idr' => 10000,
                'baseline_price_idr' => 10000, 'buyer_active' => true, 'seller_active' => true,
                'unlimited_stock' => true, 'stock' => 0, 'multi' => false,
                'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
                'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Admin mematikan sumber backup-nya sendiri (bukan nominalnya).
        DB::table('provider_mappings')->where('id', $backup->id)->update(['is_active' => false]);

        // SKU primer hilang: nominal mati karena tidak ada sumber aktif.
        $this->fakeCatalog([$this->row('sync-hole-backup')]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertSame(['active' => false, 'marker' => true], $this->state($package), 'disabled by sync');
        $this->assertDatabaseMissing('provider_mappings', ['id' => $primary->id]);

        // SKU yang tersisa hanya milik sumber yang dimatikan admin: nominal tidak boleh hidup.
        app(DigiflazzCatalogService::class)->sync();
        $this->assertSame(['active' => false, 'marker' => true], $this->state($package), 'no active source yet');
    }
}
