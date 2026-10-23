<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\AdminDigiflazzMonitorService;
use App\Services\AdminPermissionService;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzAutoSources;
use App\Services\DigiflazzCatalogImport;
use App\Services\DigiflazzCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDigiflazzRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN', array $permissions = []): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Digiflazz regression',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('digiflazz-regression-only'),
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function mappedItem(string $sku, array $item = []): array
    {
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Local '.$sku,
            'slug' => 'local-'.strtolower($sku).'-'.bin2hex(random_bytes(3)),
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $package = ProductPackage::create([
            'product_id' => $product->id,
            'code' => strtoupper(substr(hash('sha256', $sku), 0, 12)),
            'name' => 'Nominal '.$sku,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $provider->id,
            'external_sku' => $sku,
            'cost_idr' => (int) ($item['price_idr'] ?? 10000),
            'max_price_idr' => (int) ($item['price_idr'] ?? 10000),
            'priority' => 0,
            'is_active' => true,
        ]);

        DB::table('digiflazz_catalog_items')->insert([
            'buyer_sku_code' => $sku,
            'product_name' => $item['product_name'] ?? 'Provider '.$sku,
            'category' => $item['category'] ?? 'Games',
            'brand' => $item['brand'] ?? 'Brand '.$sku,
            'type' => $item['type'] ?? 'Umum',
            'seller_name' => $item['seller_name'] ?? 'Seller A',
            'price_idr' => $item['price_idr'] ?? 10000,
            'baseline_price_idr' => $item['baseline_price_idr'] ?? 10000,
            'buyer_active' => $item['buyer_active'] ?? true,
            'seller_active' => $item['seller_active'] ?? true,
            'unlimited_stock' => $item['unlimited_stock'] ?? true,
            'stock' => $item['stock'] ?? 0,
            'multi' => $item['multi'] ?? false,
            'start_cut_off' => $item['start_cut_off'] ?? '00:00',
            'end_cut_off' => $item['end_cut_off'] ?? '00:00',
            'description' => $item['description'] ?? null,
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('provider', 'product', 'package');
    }

    private function createHistoricalOrder(array $catalog, ProviderMapping $mapping): int
    {
        return (int) DB::table('orders')->insertGetId([
            'order_number' => 'HIST-'.bin2hex(random_bytes(5)),
            'user_id' => null,
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package']->id,
            'provider_mapping_id' => $mapping->id,
            'status' => 'PENDING_PAYMENT',
            'currency' => 'IDR',
            'customer_input' => json_encode(['player_id' => '12345'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 0,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 10000,
            'idempotency_key' => 'history-'.bin2hex(random_bytes(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeCatalog(array $rows): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'is_active' => true,
            'config_ciphertext' => [
                'username' => 'monitor-test',
                'api_key' => 'monitor-secret',
                'base_url' => 'https://digiflazz-monitor.test',
            ],
        ]);
        Http::swap(new Factory);
        Http::fake([
            'https://digiflazz-monitor.test/v1/price-list' => Http::response(['data' => $rows]),
            'https://digiflazz-monitor.test/v1/cek-saldo' => Http::response(['data' => ['deposit' => 345000]]),
        ]);
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'buyer_sku_code' => 'monitor-sku',
            'product_name' => 'Monitor 10',
            'price' => 10000,
            'category' => 'Games',
            'brand' => 'Monitor',
            'type' => 'Umum',
            'seller_name' => 'Seller A',
            'buyer_product_status' => true,
            'seller_product_status' => true,
            'unlimited_stock' => true,
            'stock' => 0,
            'multi' => false,
            'start_cut_off' => '00:00',
            'end_cut_off' => '00:00',
            'desc' => 'Monitor description',
        ], $overrides);
    }

    public function test_single_sku_sync_does_not_report_stale_full_sync_cleanup_counts(): void
    {
        $this->login();
        $this->mappedItem('single-sync-current');
        $itemId = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'single-sync-current')->value('id');
        DB::table('system_settings')->insert([
            'key' => 'digiflazz.last_catalog_sync',
            'value' => json_encode(['missing_marked_unavailable' => 7, 'mappings_deleted_without_history' => 4], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'single-sync-current'])]);

        $this->post('/admin/digiflazz/sync', ['item_id' => $itemId])
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('status', function (string $status): bool {
                return ! str_contains($status, '7 mapping') && ! str_contains($status, '4 mapping');
            });
    }

    public function test_single_mapping_sync_deletion_is_audited_without_server_error(): void
    {
        $this->login();
        $catalog = $this->mappedItem('sync-delete-no-history');
        $mapping = ProviderMapping::where('external_sku', 'sync-delete-no-history')->firstOrFail();
        $this->fakeCatalog([]);

        $this->post('/admin/catalog/mappings/'.$mapping->id.'/sync')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('provider_mappings', ['id' => $mapping->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'digiflazz.mapping.synced',
            'target_id' => (string) $mapping->id,
        ]);
    }

    public function test_admin_manual_price_lock_survives_sync_and_source_import_until_unlocked(): void
    {
        $this->login();
        $catalog = $this->mappedItem('manual-lock-sku');
        $mapping = ProviderMapping::where('external_sku', 'manual-lock-sku')->firstOrFail();

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true, 'customer_no_template' => '',
            'is_manual_price' => true,
        ])->assertSessionHasErrors('manual_price_idr');

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true, 'customer_no_template' => '',
            'is_manual_price' => true, 'manual_price_idr' => 11000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id, 'is_manual_price' => true, 'cost_idr' => 11000, 'store_cost_idr' => 11000, 'max_price_idr' => 11000,
        ]);

        $this->fakeCatalog([$this->row([
            'buyer_sku_code' => 'manual-lock-sku', 'price' => 10500,
            'unlimited_stock' => false, 'stock' => 13,
        ])]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseHas('digiflazz_catalog_items', [
            'buyer_sku_code' => 'manual-lock-sku', 'price_idr' => 10500, 'stock' => 13,
        ]);
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id, 'cost_idr' => 11000, 'max_price_idr' => 11000,
        ]);

        app(DigiflazzCatalogImport::class)->upsert($catalog['package'], 'manual-lock-sku', 10800, 10800);
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id, 'cost_idr' => 11000, 'max_price_idr' => 11000,
        ]);

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true, 'customer_no_template' => '',
            'is_manual_price' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        app(DigiflazzCatalogService::class)->sync('manual-lock-sku');
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id, 'is_manual_price' => false,
            'cost_idr' => 10500, 'max_price_idr' => 10500,
        ]);
    }

    public function test_manual_price_lock_sets_authoritative_store_cost_used_by_checkout(): void
    {
        $this->login();
        $catalog = $this->mappedItem('manual-lock-checkout', ['price_idr' => 10000]);
        $mapping = ProviderMapping::where('external_sku', 'manual-lock-checkout')->firstOrFail();

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true,
            'is_manual_price' => true, 'manual_price_idr' => 11000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(11000, (int) $mapping->fresh()->store_cost_idr);
        $this->assertSame(12100, app(CheckoutPricing::class)->forPackage($catalog['package']->id)['subtotal_idr']);
    }

    public function test_admin_store_cost_edit_updates_checkout_and_price_ceiling(): void
    {
        $this->login();
        $catalog = $this->mappedItem('store-cost-edit', ['price_idr' => 10000]);
        $mapping = ProviderMapping::where('external_sku', 'store-cost-edit')->firstOrFail();
        $mapping->update(['store_cost_idr' => 11000]);

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true, 'store_cost_idr' => 12000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(12000, (int) $mapping->fresh()->store_cost_idr);
        $this->assertSame(12000, (int) $mapping->fresh()->max_price_idr);
        $this->assertSame(13200, app(CheckoutPricing::class)->forPackage($catalog['package']->id)['subtotal_idr']);
    }

    public function test_import_initializes_store_cost_with_one_time_uplift_and_never_reapplies_it(): void
    {
        $catalog = $this->mappedItem('initial-uplift-once', ['price_idr' => 12000]);
        $mapping = ProviderMapping::where('external_sku', 'initial-uplift-once')->firstOrFail();
        DB::table('products')->where('id', $catalog['product']->id)->update(['cost_uplift_percent' => 2]);
        DB::table('provider_mappings')->where('id', $mapping->id)->update([
            'cost_idr' => 12000,
            'max_price_idr' => 12000,
            'store_cost_idr' => null,
            'seller_reference_price_idr' => null,
        ]);

        app(DigiflazzCatalogImport::class)->upsert($catalog['package'], 'initial-uplift-once', 12000, 12000);
        app(DigiflazzCatalogImport::class)->upsert($catalog['package'], 'initial-uplift-once', 12000, 12000);

        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id,
            'seller_reference_price_idr' => 12000,
            'store_cost_idr' => 12240,
            'max_price_idr' => 12240,
        ]);
    }

    public function test_admin_can_edit_redesign_store_cost_without_changing_seller_reference(): void
    {
        $this->login();
        $catalog = $this->mappedItem('admin-redesign-cost');
        $mapping = ProviderMapping::where('external_sku', 'admin-redesign-cost')->firstOrFail();
        DB::table('provider_mappings')->where('id', $mapping->id)->update([
            'store_cost_idr' => 10200,
            'seller_reference_price_idr' => 10000,
        ]);

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true, 'customer_no_template' => '',
            'is_manual_price' => false, 'store_cost_idr' => 11000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id,
            'store_cost_idr' => 11000,
            'seller_reference_price_idr' => 10000,
            'max_price_idr' => 11000,
        ]);
    }

    public function test_sync_refreshes_seller_reference_without_overwriting_redesign_store_cost(): void
    {
        $catalog = $this->mappedItem('redesign-cost-preservation');
        $mapping = ProviderMapping::where('external_sku', 'redesign-cost-preservation')->firstOrFail();
        DB::table('provider_mappings')->where('id', $mapping->id)->update([
            'store_cost_idr' => 10200,
            'seller_reference_price_idr' => 10000,
        ]);
        $catalog['product']->update(['sell_markup_percent' => 10]);
        $this->fakeCatalog([$this->row([
            'buyer_sku_code' => 'redesign-cost-preservation', 'price' => 10500,
        ])]);

        app(DigiflazzCatalogService::class)->sync();
        app(DigiflazzCatalogService::class)->sync('redesign-cost-preservation');

        $this->assertDatabaseHas('digiflazz_catalog_items', [
            'buyer_sku_code' => 'redesign-cost-preservation', 'price_idr' => 10500,
        ]);
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id,
            'cost_idr' => 10500,
            'store_cost_idr' => 10200,
            'seller_reference_price_idr' => 10500,
        ]);
    }

    public function test_admin_can_sell_at_six_percent_margin_without_hidden_eight_percent_cutoff(): void
    {
        $this->login();
        $catalog = $this->mappedItem('manual-six-percent-sku');
        $catalog['product']->category->update(['is_active' => true]);
        $catalog['package']->update([
            'pricing_mode' => 'SELL_PRICE',
            'sell_price_idr' => 10600,
        ]);
        $mapping = ProviderMapping::where('external_sku', 'manual-six-percent-sku')->firstOrFail();

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0,
            'is_active' => true,
            'customer_no_template' => '',
            'is_manual_price' => true,
            'manual_price_idr' => 10000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id,
            'is_manual_price' => true,
            'cost_idr' => 10000,
            'store_cost_idr' => 10000,
            'max_price_idr' => 10000,
        ]);

        $quote = app(CheckoutPricing::class)->forPackage($catalog['package']->id);
        $this->assertSame(10000, $quote['cost_idr']);
        $this->assertSame(10000, $quote['max_price_idr']);
        $this->assertSame(600, $quote['margin_idr']);
        $this->assertSame(10600, $quote['subtotal_idr']);

        $previews = app(CheckoutPricing::class)->forPackages([$catalog['package']->id]);
        $this->assertSame(10600, $previews[$catalog['package']->id]['subtotal_idr']);
        $this->assertSame(10000, $previews[$catalog['package']->id]['max_price_idr']);
    }

    public function test_locked_cost_cannot_hide_supplier_spike_above_manual_maximum(): void
    {
        $this->login();
        $catalog = $this->mappedItem('manual-cutoff-sku');
        $catalog['product']->category->update(['is_active' => true]);
        $catalog['package']->update(['pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 13000]);
        $mapping = ProviderMapping::where('external_sku', 'manual-cutoff-sku')->firstOrFail();
        $mapping->update(['is_manual_price' => true, 'cost_idr' => 11000, 'max_price_idr' => 11000]);

        $this->assertSame(13000, app(CheckoutPricing::class)->forPackage($catalog['package']->id)['subtotal_idr']);

        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'manual-cutoff-sku', 'price' => 11500])]);
        app(DigiflazzCatalogService::class)->sync('manual-cutoff-sku');
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id, 'is_manual_price' => true,
            'cost_idr' => 11000, 'max_price_idr' => 11000,
        ]);

        try {
            app(CheckoutPricing::class)->forPackage($catalog['package']->id);
            $this->fail('The live supplier price must trigger the locked per-source cutoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('package_id', $exception->errors());
        }
        $this->assertArrayNotHasKey(
            $catalog['package']->id,
            app(CheckoutPricing::class)->forPackages([$catalog['package']->id])
        );
    }

    public function test_admin_can_attach_alternate_digiflazz_source_to_existing_nominal(): void
    {
        $this->login();
        $catalog = $this->mappedItem('primary-sku');
        $primary = ProviderMapping::where('product_package_id', $catalog['package']->id)->firstOrFail();
        $catalog['product']->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $primaryItem = DB::table('digiflazz_catalog_items')
            ->where('buyer_sku_code', 'primary-sku')->first();
        $primary->update([
            'priority' => 0,
            'fulfillment_config' => [
                'customer_no_template' => '{{user_id}}',
                'auto_source_group' => app(DigiflazzAutoSources::class)->key($primaryItem),
            ],
        ]);

        $itemId = DB::table('digiflazz_catalog_items')->insertGetId([
            'buyer_sku_code' => 'alternate-sku',
            'product_name' => 'Nominal alternatif',
            'category' => 'Games',
            'brand' => 'Alternate',
            'type' => 'Umum',
            'seller_name' => 'Seller B',
            'price_idr' => 10500,
            'baseline_price_idr' => 10500,
            'buyer_active' => true,
            'seller_active' => true,
            'unlimited_stock' => true,
            'stock' => 0,
            'multi' => false,
            'start_cut_off' => '00:00',
            'end_cut_off' => '00:00',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/admin/catalog/packages/'.$catalog['package']->id.'/sources/digiflazz', [
            'item_id' => $itemId,
        ])->assertSessionHasErrors('item_id');

        $this->assertSame(1, ProviderMapping::where('product_package_id', $catalog['package']->id)
            ->where('provider_id', $primary->provider_id)->count());
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'alternate-sku']);
        $this->assertSame(
            'primary-sku',
            app(CheckoutPricing::class)->forPackage($catalog['package']->id)['provider_sku']
        );
    }

    public function test_digiflazz_has_a_dedicated_menu_and_health_summary_with_filters(): void
    {
        $admin = $this->login();
        $this->mappedItem('healthy-sku', ['price_idr' => 10200, 'baseline_price_idr' => 10000]);
        $this->mappedItem('warning-sku', [
            'unlimited_stock' => false,
            'stock' => 3,
        ]);
        $this->mappedItem('critical-sku', [
            'seller_active' => false,
        ]);

        $menu = app(AdminPermissionService::class)->menu($admin);
        $digiflazz = collect($menu)->firstWhere('label', 'Digiflazz');
        $this->assertSame('/admin/digiflazz', $digiflazz['href']);

        $this->get('/admin/digiflazz')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Digiflazz')
            ->where('summary.total', 3)
            ->where('summary.healthy', 1)
            ->where('summary.warning', 1)
            ->where('summary.critical', 1)
            ->where('filters.scope', 'all')
            ->where('connection.configured', false)
            ->has('items.data', 3));

        $this->get('/admin/digiflazz?health=warning')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.buyer_sku_code', 'warning-sku')
            ->where('items.data.0.health', 'warning'));

        $this->get('/admin/digiflazz?health=invalid')->assertSessionHasErrors('health');
        $this->get('/admin/digiflazz?per_page=500')->assertSessionHasErrors('per_page');
    }

    public function test_unmapped_supplier_skus_are_visible_by_default_and_mapped_scope_is_optional(): void
    {
        $this->login();
        $this->mappedItem('unmapped-visible-sku');
        ProviderMapping::where('external_sku', 'unmapped-visible-sku')->delete();

        $this->get('/admin/digiflazz')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.scope', 'all')
            ->where('mappingCount', 0)
            ->has('items.data', 1)
            ->where('items.data.0.buyer_sku_code', 'unmapped-visible-sku')
            ->where('items.data.0.mapped', false));

        $this->get('/admin/digiflazz?scope=mapped')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.scope', 'mapped')
            ->has('items.data', 0));
    }

    public function test_health_filters_handle_price_decreases_and_match_monitor_results(): void
    {
        $this->login();
        $this->mappedItem('price-down', ['price_idr' => 9000, 'baseline_price_idr' => 10000]);
        $this->mappedItem('price-up', ['price_idr' => 11000, 'baseline_price_idr' => 10000]);
        $this->mappedItem('low-stock', ['unlimited_stock' => false, 'stock' => 2]);
        $this->mappedItem('inactive', ['seller_active' => false, 'price_idr' => 8000]);
        $this->mappedItem('cutoff', ['start_cut_off' => '10:00', 'end_cut_off' => '14:00']);
        $this->travelTo(now('Asia/Jakarta')->startOfDay()->addHours(12));

        foreach (['all', 'mapped'] as $scope) {
            foreach (['healthy' => ['price-down'], 'warning' => ['cutoff', 'low-stock', 'price-up'], 'critical' => ['inactive']] as $health => $codes) {
                $response = $this->get('/admin/digiflazz?scope='.$scope.'&health='.$health.'&per_page=10');
                $response->assertOk();
                $response->assertInertia(function (Assert $page) use ($codes, $health): void {
                    $page->where('items.total', count($codes));
                    $page->where('items.data', fn ($items): bool => collect($items)->pluck('buyer_sku_code')->sort()->values()->all() === $codes);
                    $page->where('items.data', fn ($items): bool => collect($items)->every(fn ($item): bool => $item['health'] === $health));
                });
            }
        }
        $this->travelBack();
    }

    public function test_full_sync_marks_removed_historical_sku_unavailable_and_cleans_orphans(): void
    {
        $this->login();
        $historical = $this->mappedItem('removed-historical');
        $mapping = ProviderMapping::where('external_sku', 'removed-historical')->firstOrFail();
        $this->createHistoricalOrder($historical, $mapping);
        $this->mappedItem('removed-orphan');
        $this->mappedItem('retained');

        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'retained', 'price' => 9500])]);
        $this->assertSame(1, app(DigiflazzCatalogService::class)->sync());
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'removed-historical', 'is_present' => false]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'removed-historical', 'is_active' => false, 'disabled_by_sync' => true]);
        $this->assertDatabaseMissing('digiflazz_catalog_items', ['buyer_sku_code' => 'removed-orphan']);
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'removed-orphan']);
        $this->assertDatabaseHas('product_packages', ['id' => $historical['package']->id, 'is_active' => true]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'retained', 'is_active' => true, 'cost_idr' => 9500]);
        $this->assertSame(2, DB::table('digiflazz_catalog_items')->count());
        $sync = json_decode((string) DB::table('system_settings')->where('key', 'digiflazz.last_catalog_sync')->value('value'), true);
        $this->assertSame(1, $sync['missing_marked_unavailable']);
        $this->assertSame(1, $sync['mappings_deleted_without_history']);

        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'removed-historical'])]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'removed-historical', 'is_present' => true]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'removed-historical', 'is_active' => true, 'disabled_by_sync' => false]);
    }

    public function test_removed_sku_with_historical_mapping_is_not_sellable_or_listed_to_customers(): void
    {
        $this->login();
        $catalog = $this->mappedItem('removed-hidden-sku');
        $mapping = ProviderMapping::where('external_sku', 'removed-hidden-sku')->firstOrFail();
        $this->createHistoricalOrder($catalog, $mapping);

        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'another-sku'])]);
        app(DigiflazzCatalogService::class)->sync();

        $this->assertSame([], app(CheckoutPricing::class)->forPackages([$catalog['package']->id]));
        $this->get('/')->assertOk()->assertDontSee('Local removed-hidden-sku');
    }

    public function test_single_sku_sync_does_not_delete_other_supplier_rows(): void
    {
        $this->login();
        $this->mappedItem('single-target');
        $this->mappedItem('other-sku');
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'single-target', 'price' => 12000])]);
        app(DigiflazzCatalogService::class)->sync('single-target');
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'other-sku', 'buyer_active' => true]);

        $this->fakeCatalog([]);
        $this->assertSame(0, app(DigiflazzCatalogService::class)->sync('single-target'));
        $this->assertDatabaseMissing('digiflazz_catalog_items', ['buyer_sku_code' => 'single-target']);
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'single-target']);
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'other-sku', 'buyer_active' => true]);
    }

    public function test_admin_disabled_mapping_is_never_reenabled_by_catalog_sync(): void
    {
        $this->login();
        $this->mappedItem('user-disabled-after-sync');
        $this->mappedItem('still-live');
        ProviderMapping::where('external_sku', 'user-disabled-after-sync')->update(['is_active' => false]);

        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'still-live'])]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'user-disabled-after-sync']);

        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'still-live']),
            $this->row(['buyer_sku_code' => 'user-disabled-after-sync']),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'user-disabled-after-sync']);
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'user-disabled-after-sync']);
    }

    public function test_large_unexpected_catalog_drop_is_rejected_without_changes(): void
    {
        $this->login();
        $this->mappedItem('remain');
        $now = now();
        $rows = [];
        for ($n = 0; $n < 100; $n++) {
            $rows[] = [
                'buyer_sku_code' => 'synthetic-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'product_name' => 'Synthetic '.$n, 'category' => 'Games', 'brand' => 'Synthetic',
                'type' => 'Umum', 'seller_name' => 'Fake', 'price_idr' => 10000,
                'baseline_price_idr' => 10000, 'buyer_active' => true, 'seller_active' => true,
                'unlimited_stock' => true, 'stock' => 0, 'multi' => false,
                'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
                'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 25) as $chunk) {
            DB::table('digiflazz_catalog_items')->insert($chunk);
        }
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'remain'])]);

        try {
            app(DigiflazzCatalogService::class)->sync();
            $this->fail('Unexpected large catalog drop must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('sync', $error->errors());
        }
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'remain', 'is_active' => true]);
        $this->assertSame(101, DB::table('digiflazz_catalog_items')->where('is_present', true)->count());
    }

    public function test_failed_empty_and_malformed_full_syncs_preserve_catalog_and_mappings(): void
    {
        $this->login();
        $this->mappedItem('preserved');
        foreach ([[], [$this->row(), $this->row(['buyer_sku_code' => 'bad-sku', 'price' => 0])], null] as $rows) {
            $this->fakeCatalog($rows ?? [$this->row()]);
            if ($rows === null) {
                Http::swap(new Factory);
                Http::fake(['https://digiflazz-monitor.test/v1/price-list' => Http::response(['data' => []], 503)]);
            }
            try {
                app(DigiflazzCatalogService::class)->sync();
                $this->fail('Invalid full catalog response must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'preserved', 'price_idr' => 10000]);
                $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'preserved', 'is_active' => true]);
                $this->assertSame(1, DB::table('digiflazz_catalog_items')->count());
                $this->assertDatabaseMissing('system_settings', ['key' => 'digiflazz.last_catalog_sync']);
            }
        }
    }

    public function test_invalid_catalog_row_logs_safe_reason_without_changing_previous_prices(): void
    {
        $this->login();
        $this->mappedItem('preserved');
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'bad-sku', 'price' => 0])]);
        Log::spy();

        try {
            app(DigiflazzCatalogService::class)->sync();
            $this->fail('Invalid supplier price must be rejected.');
        } catch (ValidationException $error) {
            $this->assertSame(
                ['Format daftar harga tidak valid. Tidak ada perubahan disimpan.'],
                $error->errors()['sync']
            );
        }

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Digiflazz catalog sync rejected.'
                && ($context['reason'] ?? null) === 'invalid_price'
                && ($context['row_index'] ?? null) === 0
                && ! array_key_exists('api_key', $context)
                && ! array_key_exists('response_body', $context)
        )->once();
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'preserved', 'price_idr' => 10000]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'preserved', 'is_active' => true]);
    }

    public function test_supplier_error_code_is_logged_without_supplier_message_or_credentials(): void
    {
        $this->login();
        $this->mappedItem('preserved');
        $this->fakeCatalog([]);
        Http::swap(new Factory);
        Http::fake([
            'https://digiflazz-monitor.test/v1/price-list' => Http::response([
                'data' => ['rc' => '41', 'message' => 'Sensitive supplier detail'],
            ]),
        ]);
        Log::spy();

        try {
            app(DigiflazzCatalogService::class)->sync();
            $this->fail('Supplier error response must be rejected.');
        } catch (ValidationException $error) {
            $this->assertSame(['Daftar harga Digiflazz kosong atau tidak valid.'], $error->errors()['sync']);
        }

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Digiflazz catalog sync rejected.'
                && ($context['reason'] ?? null) === 'invalid_data_shape'
                && ($context['provider_rc'] ?? null) === '41'
                && ! array_key_exists('message', $context)
                && ! array_key_exists('api_key', $context)
        )->once();
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'preserved', 'price_idr' => 10000]);
    }

    public function test_monitor_thresholds_and_auto_sync_interval_are_editable(): void
    {
        $this->login();

        $this->put('/admin/digiflazz/settings', [
            'enabled' => true,
            'sync_interval_minutes' => 35,
            'low_stock_threshold' => 9,
            'price_warning_percent' => 4.5,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = app(AdminDigiflazzMonitorService::class)->settings();
        $this->assertSame(35, $settings['sync_interval_minutes']);
        $this->assertSame(9, $settings['low_stock_threshold']);
        $this->assertSame(4.5, $settings['price_warning_percent']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'digiflazz.monitor.settings_updated']);

        $this->put('/admin/digiflazz/settings', [
            'enabled' => true,
            'sync_interval_minutes' => 7,
            'low_stock_threshold' => 9,
            'price_warning_percent' => 4.5,
        ])->assertSessionHasErrors('sync_interval_minutes');
    }

    public function test_auto_sync_respects_configured_interval_without_calling_provider_early(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'digiflazz.auto_sync_interval_minutes'],
            ['value' => json_encode(30), 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'digiflazz.last_auto_sync'],
            ['value' => json_encode(['at' => now()->toIso8601String(), 'count' => 1]), 'created_at' => now(), 'updated_at' => now()]
        );

        $this->artisan('lfamilia:sync-digiflazz-catalog')->assertExitCode(0);
        Http::assertNothingSent();

        DB::table('system_settings')->where('key', 'digiflazz.last_auto_sync')->update([
            'value' => json_encode(['at' => now()->subMinutes(31)->toIso8601String(), 'count' => 1]),
            'updated_at' => now(),
        ]);
        $this->artisan('lfamilia:sync-digiflazz-catalog')->assertExitCode(0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://digiflazz-monitor.test/v1/price-list');
    }

    public function test_failed_auto_sync_throttles_retries_without_marking_a_success(): void
    {
        $this->login();
        $this->fakeCatalog([]);
        DB::table('system_settings')->whereIn('key', ['digiflazz.last_auto_sync', 'digiflazz.last_auto_sync_attempt'])->delete();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'digiflazz.auto_sync_interval_minutes'],
            ['value' => json_encode(30), 'created_at' => now(), 'updated_at' => now()]
        );

        $this->artisan('lfamilia:sync-digiflazz-catalog')->assertExitCode(1);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('system_settings', ['key' => 'digiflazz.last_auto_sync_attempt']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'digiflazz.last_auto_sync']);

        $this->artisan('lfamilia:sync-digiflazz-catalog')->assertExitCode(0);
        Http::assertSentCount(1);

        DB::table('system_settings')->where('key', 'digiflazz.last_auto_sync_attempt')->update([
            'value' => json_encode(['at' => now()->subMinutes(31)->toIso8601String()]),
            'updated_at' => now(),
        ]);
        $this->artisan('lfamilia:sync-digiflazz-catalog')->assertExitCode(1);
        Http::assertSentCount(2);
        $this->assertDatabaseMissing('system_settings', ['key' => 'digiflazz.last_auto_sync']);
    }

    public function test_seller_change_resets_baseline_and_multi_flag_is_synced(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();

        $this->fakeCatalog([$this->row([
            'seller_name' => 'Seller B',
            'price' => 12000,
            'multi' => true,
        ])]);
        app(DigiflazzCatalogService::class)->sync();

        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'monitor-sku')->firstOrFail();
        $this->assertSame('Seller B', $item->seller_name);
        $this->assertSame(12000, (int) $item->price_idr);
        $this->assertSame(12000, (int) $item->baseline_price_idr);
        $this->assertTrue((bool) $item->multi);

        $this->fakeCatalog([$this->row([
            'seller_name' => 'Seller B',
            'price' => 13000,
            'multi' => true,
        ])]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertSame(
            12000,
            (int) DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'monitor-sku')->value('baseline_price_idr')
        );
    }

    public function test_balance_is_read_only_and_only_returned_to_super_admin(): void
    {
        Cache::flush();
        $this->fakeCatalog([$this->row()]);
        $this->login();

        $this->get('/admin/digiflazz')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canSeeBalance', true)
            ->where('connection.status', 'HEALTHY')
            ->where('connection.balance_idr', 345000)
            ->missing('connection.username')
            ->missing('connection.api_key'));

        Cache::flush();
        Http::swap(new Factory);
        Http::fake();
        $this->login('ADMIN', ['providers.manage']);

        $this->get('/admin/digiflazz')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canSeeBalance', false)
            ->where('connection.balance_idr', null));
        Http::assertNothingSent();
    }

    public function test_digiflazz_page_is_permission_gated(): void
    {
        $this->login('ADMIN', ['dashboard.view']);
        $this->get('/admin/digiflazz')->assertForbidden();
    }

    public function test_orphan_mapping_and_stale_data_are_rejected_by_checkout(): void
    {
        $orphan = $this->mappedItem('orphan-only');
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'orphan-only')->delete();
        $this->assertSame([], app(CheckoutPricing::class)->forPackages([$orphan['package']->id]));
        try {
            app(CheckoutPricing::class)->forPackage($orphan['package']->id);
            $this->fail('Orphan SKU must be ineligible.');
        } catch (ValidationException) {
            // Orphaned mappings must not pass checkout.
        }

        $stale = $this->mappedItem('stale-only');
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'stale-only')
            ->update(['synced_at' => now()->subHours(25)]);
        $this->assertSame([], app(CheckoutPricing::class)->forPackages([$stale['package']->id]));
        try {
            app(CheckoutPricing::class)->forPackage($stale['package']->id);
            $this->fail('Stale supplier data must be ineligible.');
        } catch (ValidationException) {
            // Stale catalog must be blocked.
        }

        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'stale-only')
            ->update(['synced_at' => now()]);
        $this->assertSame(
            $stale['package']->id,
            app(CheckoutPricing::class)->forPackage($stale['package']->id)['package_id']
        );
    }

    public function test_auto_sources_bootstrap_one_mapping_only_for_active_sellable_nominals(): void
    {
        $sample = $this->mappedItem('BOOT1.000', [
            'brand' => 'Bootstrap', 'product_name' => 'Bootstrap 1.000',
        ]);
        $sample['product']->update(['name' => 'Bootstrap']);
        $sample['product']->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $sample['package']->update(['nominal_value' => 1000]);
        ProviderMapping::where('external_sku', 'BOOT1.000')->delete();

        $this->assertSame(1, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseHas('provider_mappings', [
            'external_sku' => 'BOOT1.000', 'priority' => 0, 'is_active' => true,
            'is_manual_price' => false, 'cost_idr' => 10000, 'store_cost_idr' => 10200,
            'seller_reference_price_idr' => 10000, 'max_price_idr' => 10200,
        ]);
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $this->assertSame(1, ProviderMapping::where('product_package_id', $sample['package']->id)->count());

        $blocked = $this->mappedItem('BLOCK2.000', [
            'brand' => 'BlockedGame', 'product_name' => 'BlockedGame 2.000', 'seller_active' => false,
        ]);
        $blocked['product']->update(['name' => 'BlockedGame']);
        $blocked['package']->update(['nominal_value' => 2000]);
        ProviderMapping::where('external_sku', 'BLOCK2.000')->delete();
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $blocked['package']->update(['is_active' => false]);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'BLOCK2.000')
            ->update(['seller_active' => true]);
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'BLOCK2.000']);
        $blocked['package']->update(['is_active' => true]);
        $blocked['product']->update(['is_active' => false]);
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'BLOCK2.000']);
    }

    public function test_auto_bootstrap_requires_one_required_field_and_unambiguous_identity(): void
    {
        $sample = $this->mappedItem('ZERO5.000', [
            'brand' => 'Zero', 'product_name' => 'Zero 5.000',
        ]);
        $sample['product']->update(['name' => 'Zero']);
        $sample['package']->update(['nominal_value' => 5000]);
        ProviderMapping::where('external_sku', 'ZERO5.000')->delete();
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());

        $field = $sample['product']->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => false, 'sort_order' => 0,
        ]);
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $field->update(['is_required' => true]);

        $variant = (array) DB::table('digiflazz_catalog_items')
            ->where('buyer_sku_code', 'ZERO5.000')->first();
        unset($variant['id']);
        $variant['buyer_sku_code'] = 'ZERO5.000B';
        $variant['product_name'] = 'Zero 5.000 Region B';
        $variant['type'] = 'Region B';
        DB::table('digiflazz_catalog_items')->insert($variant);
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'ZERO5.000']);

        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'ZERO5.000B')->delete();
        $sample['package']->update(['group_name' => 'Region B']);
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $sample['package']->update(['group_name' => null]);
        $this->assertSame(1, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseHas('provider_mappings', [
            'external_sku' => 'ZERO5.000', 'is_active' => true, 'priority' => 0,
        ]);
    }

    public function test_auto_bootstrap_detects_ambiguous_region_already_mapped_elsewhere(): void
    {
        $sample = $this->mappedItem('OWNED6.000', [
            'brand' => 'Owned', 'product_name' => 'Owned 6.000',
        ]);
        $sample['product']->update(['name' => 'Owned']);
        $sample['product']->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $sample['package']->update(['nominal_value' => 6000]);
        ProviderMapping::where('external_sku', 'OWNED6.000')->delete();

        $variant = (array) DB::table('digiflazz_catalog_items')
            ->where('buyer_sku_code', 'OWNED6.000')->first();
        unset($variant['id']);
        $variant['buyer_sku_code'] = 'OWNED6.000B';
        $variant['product_name'] = 'Owned 6.000 Region B';
        $variant['type'] = 'Region B';
        DB::table('digiflazz_catalog_items')->insert($variant);

        $other = $this->mappedItem('OTHER7.000');
        ProviderMapping::create([
            'product_package_id' => $other['package']->id,
            'provider_id' => $sample['provider']->id,
            'external_sku' => 'OWNED6.000B',
            'cost_idr' => 10000,
            'max_price_idr' => 10000,
            'priority' => 1,
            'is_active' => true,
        ]);

        // An already-owned variant still makes the source identity ambiguous.
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseMissing('provider_mappings', [
            'product_package_id' => $sample['package']->id,
            'external_sku' => 'OWNED6.000',
        ]);

        DB::table('provider_mappings')->where('external_sku', 'OWNED6.000B')->delete();
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'OWNED6.000B')->delete();
        $this->assertSame(1, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseHas('provider_mappings', [
            'product_package_id' => $sample['package']->id,
            'external_sku' => 'OWNED6.000',
            'is_active' => true,
        ]);
    }

    public function test_auto_bootstrap_pins_identity_and_checkout_rejects_reused_sku(): void
    {
        $sample = $this->mappedItem('PINNED8.000', [
            'brand' => 'Pinned', 'product_name' => 'Pinned 8.000',
        ]);
        $sample['product']->update(['name' => 'Pinned']);
        $sample['product']->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $sample['package']->update(['nominal_value' => 8000]);
        ProviderMapping::where('external_sku', 'PINNED8.000')->delete();
        $this->assertSame(1, app(DigiflazzAutoSources::class)->sync());

        $mapping = ProviderMapping::where('external_sku', 'PINNED8.000')->firstOrFail();
        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'PINNED8.000')->first();
        $this->assertSame(
            app(DigiflazzAutoSources::class)->key($item),
            data_get($mapping->fulfillment_config, 'auto_source_group')
        );
        $this->assertSame(
            $sample['package']->id,
            app(CheckoutPricing::class)->forPackage($sample['package']->id)['package_id']
        );

        // Supplier retags the same SKU code; the mapping must stay active but unsellable.
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'PINNED8.000')
            ->update(['brand' => 'Wrong Product', 'product_name' => 'Wrong 8.000']);
        $this->assertSame([], app(CheckoutPricing::class)->forPackages([$sample['package']->id]));
        try {
            app(CheckoutPricing::class)->forPackage($sample['package']->id);
            $this->fail('Retagged SKU must never be eligible for checkout.');
        } catch (ValidationException) {
            // Mismatched verified identity is blocked without changing the mapping.
        }
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id, 'is_active' => true,
        ]);
        $this->fakeCatalog([$this->row([
            'buyer_sku_code' => 'PINNED8.000', 'brand' => 'Wrong Product',
            'product_name' => 'Wrong 8.000', 'price' => 18000,
        ])]);
        app(DigiflazzCatalogService::class)->sync('PINNED8.000');
        // A reused supplier code may update live catalog information but may
        // never reprice an active mapping for a different verified product.
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $mapping->id, 'cost_idr' => 10000,
            'store_cost_idr' => 10200, 'max_price_idr' => 10200, 'is_active' => true,
        ]);

        // Redesign: seller availability returns only when the verified identity
        // matches again AND the live seller price is back within the store cost cap.
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'PINNED8.000')
            ->update(['brand' => 'Pinned', 'product_name' => 'Pinned 8.000', 'price_idr' => 10000]);
        $this->assertSame(
            $sample['package']->id,
            app(CheckoutPricing::class)->forPackage($sample['package']->id)['package_id']
        );
    }

    public function test_auto_source_never_publishes_mapping_for_unverified_multi_field_destination(): void
    {
        $sample = $this->mappedItem('TWOFIELDS3.000', [
            'brand' => 'Twofields', 'product_name' => 'Twofields 3.000',
        ]);
        $sample['product']->update(['name' => 'Twofields']);
        $sample['product']->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $sample['product']->fields()->create([
            'field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 1,
        ]);
        $sample['package']->update(['nominal_value' => 3000]);
        ProviderMapping::where('external_sku', 'TWOFIELDS3.000')->delete();

        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $this->assertDatabaseMissing('provider_mappings', [
            'product_package_id' => $sample['package']->id,
            'external_sku' => 'TWOFIELDS3.000',
        ]);
    }

    public function test_admin_auto_source_route_is_disabled_for_single_sku_catalog(): void
    {
        $this->login();
        $sample = $this->mappedItem('ENABLE4.000', [
            'brand' => 'Enable', 'product_name' => 'Enable 4.000',
        ]);

        $this->post('/admin/catalog/products/'.$sample['product']->id.'/auto-sources')->assertNotFound();
        $this->assertSame(1, ProviderMapping::where('product_package_id', $sample['package']->id)->count());
    }

    public function test_duplicate_active_mappings_are_reported_but_never_changed_by_auto_sources(): void
    {
        $sample = $this->mappedItem('DUPE-A');
        ProviderMapping::create([
            'product_package_id' => $sample['package']->id,
            'provider_id' => $sample['provider']->id,
            'external_sku' => 'DUPE-B',
            'cost_idr' => 10000,
            'max_price_idr' => 10000,
            'priority' => 2,
            'is_active' => true,
        ]);
        Cache::forget('digiflazz.mapping.duplicates.'.$sample['package']->id);
        $before = ProviderMapping::where('product_package_id', $sample['package']->id)
            ->orderBy('id')->get(['id', 'external_sku', 'is_active', 'priority'])->toArray();
        $this->assertSame(0, app(DigiflazzAutoSources::class)->sync());
        $after = ProviderMapping::where('product_package_id', $sample['package']->id)
            ->orderBy('id')->get(['id', 'external_sku', 'is_active', 'priority'])->toArray();
        $this->assertEquals($before, $after);
        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'digiflazz.mapping.duplicate_active',
            'target_id' => (string) $sample['package']->id,
        ]);
    }

    public function test_sync_never_deletes_manual_mappings(): void
    {
        // A-06 diverifikasi: pembersihan sinkron selalu dibatasi
        // where('provider_id', $providerId) dengan $providerId = DIGIFLAZZ,
        // jadi mapping MANUAL tidak pernah masuk kandidat hapus.
        $this->login();
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'manual-safe-sku'])]);
        $this->post('/admin/digiflazz/sync')->assertRedirect()->assertSessionHasNoErrors();

        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Produk Manual A06', 'slug' => 'produk-manual-a06',
            'fulfillment_mode' => 'MANUAL', 'margin_percent' => 10, 'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'MANUAL-10', 'name' => 'Manual 10', 'sort_order' => 1, 'is_active' => true,
        ]);
        $manualId = DB::table('providers')->where('code', 'MANUAL')->value('id');
        $mapping = ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $manualId,
            'external_sku' => null, 'cost_idr' => 9000, 'store_cost_idr' => 9000,
            'max_price_idr' => null, 'priority' => 0, 'is_active' => true,
        ]);

        // Sinkron ulang dengan katalog kosong: SKU Digiflazz hilang, mapping
        // Digiflazz boleh dibersihkan — mapping MANUAL harus tetap utuh.
        $this->fakeCatalog([]);
        $this->post('/admin/digiflazz/sync')->assertRedirect();

        $this->assertDatabaseHas('provider_mappings', ['id' => $mapping->id, 'provider_id' => $manualId]);
        $this->assertSame(9000, (int) $mapping->fresh()->store_cost_idr);
    }

    public function test_import_uses_active_sell_markup_setting_not_legacy_default_margin(): void
    {
        $this->login();
        // Setting warisan yang dulu dibaca form impor, sengaja dibedakan.
        DB::table('system_settings')->updateOrInsert(['key' => 'catalog.default_margin_percent'], [
            'value' => json_encode(35, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_settings')->updateOrInsert(['key' => 'pricing.sell_markup_percent'], [
            'value' => json_encode('12', JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Produk tanpa override margin: form impor harus memakai setting aktif.
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Impor Tanpa Override', 'slug' => 'impor-tanpa-override',
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10, 'is_active' => true,
            'sell_markup_percent' => null,
        ]);

        $this->get('/admin/catalog')->assertInertia(fn (Assert $page) => $page
            ->where('pricingSettings.sell_markup_percent', '12')
            ->missing('defaultMargin'));

        // Impor sungguhan (bukan grep sumber): SKU tersedia, produk tanpa
        // override, margin yang dikirim form = setting aktif (12), bukan 35.
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'cr02-sku', 'product_name' => 'CR02 10', 'price' => 10000])]);
        $this->post('/admin/digiflazz/sync')->assertRedirect()->assertSessionHasNoErrors();
        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'cr02-sku')->first();
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$item->id], 'margin_percent' => 12,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $package = $product->packages()->firstOrFail();
        $mapping = $package->mappings()->firstOrFail();
        // Modal toko = seller + uplift 2%; batas harga = modal, bukan markup 35%.
        $this->assertSame(10200, (int) $mapping->store_cost_idr);
        $this->assertSame(10200, (int) $mapping->max_price_idr);
        $this->assertSame(12, (int) round((float) $product->fresh()->sell_markup_percent));
        $this->assertNotSame(35, (int) round((float) $product->fresh()->sell_markup_percent));
    }

    public function test_sync_failure_creates_only_one_admin_alert_per_hour(): void
    {
        $this->fakeCatalog([]);
        $reason = 'Daftar harga Digiflazz kosong atau tidak valid.';
        Cache::forget('digiflazz.sync.failed.'.hash('sha256', $reason));
        $before = DB::table('admin_notifications')->where('event_type', 'digiflazz.sync.failed')->count();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(DigiflazzCatalogService::class)->sync();
                $this->fail('Empty full sync cannot succeed.');
            } catch (ValidationException) {
                // Fails safely.
            }
        }
        $this->assertSame(
            $before + 1,
            DB::table('admin_notifications')->where('event_type', 'digiflazz.sync.failed')->count()
        );
    }
}
