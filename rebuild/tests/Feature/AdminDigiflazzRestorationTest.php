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
use App\Services\DigiflazzCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_admin_can_attach_alternate_digiflazz_source_to_existing_nominal(): void
    {
        $this->login();
        $catalog = $this->mappedItem('primary-sku');
        $primary = ProviderMapping::where('product_package_id', $catalog['package']->id)->firstOrFail();
        $primary->update([
            'priority' => 0,
            'fulfillment_config' => ['customer_no_template' => '{{user_id}}'],
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
        ])->assertRedirect()->assertSessionHasNoErrors();

        $alternate = ProviderMapping::where('external_sku', 'alternate-sku')->firstOrFail();
        $this->assertSame($catalog['package']->id, $alternate->product_package_id);
        $this->assertSame(1, $alternate->priority);
        $this->assertFalse($alternate->is_active);
        $this->assertSame('{{user_id}}', data_get($alternate->fulfillment_config, 'customer_no_template'));
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
}
