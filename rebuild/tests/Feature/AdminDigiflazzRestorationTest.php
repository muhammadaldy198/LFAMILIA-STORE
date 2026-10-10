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

    public function test_full_sync_keeps_missing_supplier_rows_and_restores_sync_disabled_mappings(): void
    {
        $this->login();
        $gone = $this->mappedItem('removed-mapped');
        $this->mappedItem('removed-unmapped');
        ProviderMapping::where('external_sku', 'removed-unmapped')->delete();
        $this->mappedItem('retained');
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'retained', 'price' => 9500])]);

        $this->assertSame(1, app(DigiflazzCatalogService::class)->sync());
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'removed-mapped', 'is_present' => false]);
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'removed-unmapped', 'is_present' => false]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'removed-mapped', 'is_active' => false, 'disabled_by_sync' => true, 'cost_idr' => 10000]);
        $this->assertDatabaseHas('product_packages', ['id' => $gone['package']->id, 'is_active' => true]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'retained', 'is_active' => true, 'cost_idr' => 9500]);
        $this->assertSame(3, DB::table('digiflazz_catalog_items')->count());
        $sync = json_decode((string) DB::table('system_settings')->where('key', 'digiflazz.last_catalog_sync')->value('value'), true);
        $this->assertSame(2, $sync['missing']);
        $this->assertSame(1, $sync['disabled_mappings']);

        $response = $this->get('/admin/digiflazz');
        $response->assertOk();
        $response->assertInertia(function (Assert $page): void {
            $page->where('items.total', 3);
            $page->where('summary.critical', 2);
            $page->where('catalogSync.missing', 2);
        });

        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'removed-mapped'])]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'removed-mapped']);
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'removed-mapped', 'is_present' => true]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'removed-mapped', 'is_active' => true, 'disabled_by_sync' => false]);
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
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'single-target', 'is_present' => false]);
        $this->assertDatabaseHas('provider_mappings', ['external_sku' => 'single-target', 'is_active' => false, 'disabled_by_sync' => true]);
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'other-sku', 'buyer_active' => true]);
    }

    public function test_manual_disable_after_missing_sync_prevents_automatic_reactivation(): void
    {
        $this->login();
        $this->mappedItem('user-disabled-after-sync');
        $this->mappedItem('still-live');
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'still-live'])]);
        app(DigiflazzCatalogService::class)->sync();

        $mapping = ProviderMapping::where('external_sku', 'user-disabled-after-sync')->firstOrFail();
        $this->assertTrue((bool) $mapping->fresh()->disabled_by_sync);
        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => false, 'customer_no_template' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse((bool) $mapping->fresh()->disabled_by_sync);

        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'still-live']),
            $this->row(['buyer_sku_code' => 'user-disabled-after-sync']),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseHas('digiflazz_catalog_items', [
            'buyer_sku_code' => 'user-disabled-after-sync', 'is_present' => true,
        ]);
        $this->assertDatabaseHas('provider_mappings', [
            'external_sku' => 'user-disabled-after-sync',
            'is_active' => false, 'disabled_by_sync' => false,
        ]);
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
}
