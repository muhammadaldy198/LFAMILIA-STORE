<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Models\User;
use App\Services\CatalogNominalOrder;
use App\Services\CatalogPricingSettings;
use App\Services\CheckoutPricing;
use App\Services\CustomerCleanupService;
use App\Services\DigiflazzCatalogService;
use App\Services\DigiflazzFixedPricePolicy;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminPanelRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(array $permissions = [], string $role = 'SUPER_ADMIN'): void
    {
        $this->actingAs($this->createAdmin([
            'name' => 'Restoration Test', 'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('restoration-test-password'), 'role' => $role,
            'permissions' => $permissions, 'is_active' => true,
        ]), 'admin');
    }

    private function product(string $mode = 'AUTO_PROVIDER'): Product
    {
        return Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'), 'name' => 'Restore Test',
            'slug' => 'restore-'.bin2hex(random_bytes(5)), 'is_active' => true,
            'fulfillment_mode' => $mode, 'margin_percent' => 10,
        ]);
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'buyer_sku_code' => 'restore-sku', 'product_name' => 'Restore 10 Diamonds',
            'price' => 10000, 'category' => 'Games', 'brand' => 'Restore', 'type' => 'Umum',
            'seller_name' => 'Seller', 'buyer_product_status' => true, 'seller_product_status' => true,
            'unlimited_stock' => true, 'stock' => 0, 'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
        ], $overrides);
    }

    private function fakeCatalog(array $rows): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'is_active' => true, 'config_ciphertext' => ['username' => 'test', 'api_key' => 'test', 'base_url' => 'https://digiflazz.test'],
        ]);
        Http::swap(new Factory);
        Http::fake(['https://digiflazz.test/v1/price-list' => Http::response(['data' => $rows])]);
    }

    public function test_template_backfill_requires_all_active_mappings_to_agree_and_preserves_existing_product_value(): void
    {
        $product = $this->product();
        $product->update(['customer_no_template' => null]);
        $firstPackage = $product->packages()->create(['code' => 'BACKFILL-1', 'name' => 'Nominal 1', 'is_active' => true]);
        $secondPackage = $product->packages()->create(['code' => 'BACKFILL-2', 'name' => 'Nominal 2', 'is_active' => true]);
        $providerId = Provider::where('code', 'DIGIFLAZZ')->value('id');
        foreach ([[$firstPackage->id, '{{destination}}'], [$secondPackage->id, '{{destination}}']] as [$packageId, $template]) {
            DB::table('provider_mappings')->insert([
                'provider_id' => $providerId, 'product_package_id' => $packageId,
                'external_sku' => 'backfill-'.$packageId, 'cost_idr' => 100, 'max_price_idr' => 100,
                'fulfillment_config' => json_encode(['customer_no_template' => $template]),
                'priority' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (require database_path('migrations/2026_10_18_134621_backfill_product_customer_no_templates.php'))->up();
        $this->assertSame('{{destination}}', $product->fresh()->customer_no_template);

        $product->update(['customer_no_template' => '{{already_set}}']);
        (require database_path('migrations/2026_10_18_134621_backfill_product_customer_no_templates.php'))->up();
        $this->assertSame('{{already_set}}', $product->fresh()->customer_no_template);
    }

    public function test_sync_import_and_resync_preserve_baseline_and_inactive_import(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        $this->post('/admin/digiflazz/sync')->assertRedirect()->assertSessionHasNoErrors();
        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->first();
        $product = $this->product();
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$item->id]])->assertRedirect()->assertSessionHasNoErrors();
        $package = $product->packages()->firstOrFail();
        $mapping = $package->mappings()->firstOrFail();
        $this->assertFalse($package->is_active);
        $this->assertFalse($mapping->is_active);
        // SKU is connected at the seller price without a separate cost uplift.
        $this->assertSame(10000, (int) $mapping->max_price_idr);
        $this->fakeCatalog([$this->row(['price' => 12000])]);
        $this->post('/admin/catalog/mappings/'.$mapping->id.'/sync')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(12000, (int) $mapping->fresh()->cost_idr);
        $this->assertSame(10000, (int) DB::table('digiflazz_catalog_items')->where('id', $item->id)->value('baseline_price_idr'));
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$item->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, $product->packages()->count());
    }

    public function test_importing_another_nominal_preserves_existing_nominal_margin(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();
        $itemId = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');

        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$itemId],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $package = $product->packages()->firstOrFail();
        $package->update(['is_active' => true]);
        $package->mappings()->update(['is_active' => true]);
        $this->assertSame('PRODUCT_MARGIN', $package->fresh()->pricing_mode);

        // Impor SKU lain tidak boleh menimpa margin nominal yang sudah diatur.
        $otherId = DB::table('digiflazz_catalog_items')->insertGetId([
            'buyer_sku_code' => 'restore-sku-2', 'product_name' => 'Restore 20', 'category' => 'Games',
            'brand' => 'Restore', 'type' => 'Umum', 'seller_name' => 'Seller A', 'price_idr' => 20000,
            'baseline_price_idr' => 20000, 'buyer_active' => true, 'seller_active' => true,
            'unlimited_stock' => true, 'stock' => 0, 'is_present' => true,
            'start_cut_off' => '00:00', 'end_cut_off' => '00:00', 'synced_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$otherId],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('PRODUCT_MARGIN', $package->fresh()->pricing_mode);
        $this->assertSame(11000, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);
    }

    public function test_activating_automatic_product_validates_existing_destination_template(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->update(['is_active' => false, 'customer_no_template' => null]);
        foreach (['user_id', 'zone_id'] as $index => $key) {
            $product->fields()->create(['field_key' => $key, 'label' => $key, 'type' => 'text', 'is_required' => true, 'sort_order' => $index]);
        }
        $package = $product->packages()->create(['code' => 'ACTIVATE-TEMPLATE', 'name' => 'Activate Template', 'is_active' => true]);
        ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => Provider::where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'activate-template-'.bin2hex(random_bytes(3)), 'cost_idr' => 10000,
            'max_price_idr' => 10200, 'priority' => 0, 'is_active' => true,
        ]);

        $this->put('/admin/catalog/products/'.$product->id, [
            'category_id' => $product->category_id, 'name' => $product->name, 'description' => $product->description,
            'margin_percent' => 10, 'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasErrors('customer_no_template');

        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_nickname_reference_does_not_satisfy_required_provider_template_field(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->fields()->create(['field_key' => 'user_id', 'label' => 'ID pengguna', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $product->fields()->create(['field_key' => 'zone_id', 'label' => 'Zona', 'type' => 'text', 'is_required' => false, 'sort_order' => 1]);
        $product->update([
            'nickname_check_enabled' => true, 'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id', 'nickname_server_field_key' => 'zone_id',
            'customer_no_template' => '{{user_id}}{{zone_id}}',
        ]);
        $itemId = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$itemId], 'publish' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'customer_no_template' => '{{zone_id}}',
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'ID pengguna', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'zone_id', 'label' => 'Zona', 'type' => 'text', 'is_required' => false],
            ],
        ])->assertSessionHasErrors('customer_no_template');
    }

    public function test_new_automatic_product_inherits_global_markup_until_explicit_override(): void
    {
        $this->login();
        $category = Category::create(['name' => 'Global markup', 'slug' => 'global-markup', 'sort_order' => 0, 'is_active' => true]);

        $this->post('/admin/catalog/products', [
            'category_id' => $category->id, 'name' => 'Inherited markup', 'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10, 'sort_order' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Product::where('slug', 'inherited-markup')->firstOrFail()->sell_markup_percent);
    }

    public function test_publish_import_activates_product_nominal_and_source_with_price_and_destination(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->update(['is_active' => false, 'customer_no_template' => '{{user_id}}{{zone_id}}']);
        foreach (['user_id', 'zone_id'] as $index => $key) {
            $product->fields()->create(['field_key' => $key, 'label' => $key, 'type' => 'text', 'is_required' => true, 'sort_order' => $index]);
        }
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id], 'publish' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $package = $product->packages()->firstOrFail();
        $mapping = $package->mappings()->firstOrFail();
        $this->assertTrue($product->fresh()->is_active);
        $this->assertTrue($package->is_active);
        $this->assertTrue($mapping->is_active);
        $this->assertSame('{{user_id}}{{zone_id}}', $product->fresh()->customer_no_template);
        // Margin is owned by the product; imported SKUs never rename it or reprice the nominal.
        $this->assertEqualsWithDelta(10, (float) $product->fresh()->margin_percent, 0.0001);
        $this->assertSame('PRODUCT_MARGIN', $package->fresh()->pricing_mode);
        $this->assertNull($product->fresh()->sell_markup_percent);
        // Redesign: store cost (10000 + 2%) × import margin 12% = 11424.
        $this->assertSame(11000, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);
        $response = $this->get('/catalog/'.$product->slug);
        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page): void {
            $page->where('packages.0.is_available', true);
            $page->where('packages.0.price_idr', 11000);
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.mapping.updated', 'target_id' => (string) $mapping->id]);
        Http::assertSentCount(1);
    }

    public function test_publish_import_uses_the_single_customer_field_without_extra_configuration(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->fields()->create(['field_key' => 'destination', 'label' => 'ID tujuan', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $product->update(['customer_no_template' => '{{destination}}']);
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id], 'publish' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $package = $product->packages()->firstOrFail();
        $this->assertSame('{{destination}}', $product->fresh()->customer_no_template);
        $this->assertSame(11000, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);
    }

    public function test_publish_import_rejects_invalid_destination_formats_without_partial_activation(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->update(['is_active' => false]);
        foreach (['user_id', 'zone_id'] as $index => $key) {
            $product->fields()->create(['field_key' => $key, 'label' => $key, 'type' => 'text', 'is_required' => true, 'sort_order' => $index]);
        }
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');
        foreach (['', '{{user_id}}', '{{unknown}}{{zone_id}}', '{{user_id}}{{zone_id}'] as $template) {
            $this->post('/admin/catalog/products/'.$product->id.'/import', [
                'item_ids' => [$id], 'publish' => true, 'customer_no_template' => $template,
            ])->assertSessionHasErrors('customer_no_template');
            $this->assertSame(0, $product->packages()->count());
            $this->assertFalse($product->fresh()->is_active);
        }
    }

    public function test_publish_import_keeps_batch_atomic_and_does_not_enable_disabled_provider(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row(), $this->row(['buyer_sku_code' => 'restore-unavailable', 'seller_product_status' => false])]);
        app(DigiflazzCatalogService::class)->sync();
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->update(['is_active' => false]);
        $product->fields()->create(['field_key' => 'destination', 'label' => 'ID tujuan', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $ids = DB::table('digiflazz_catalog_items')->whereIn('buyer_sku_code', ['restore-sku', 'restore-unavailable'])->pluck('id')->all();
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => $ids, 'publish' => true,
        ])->assertSessionHasErrors('item_ids');
        $this->assertSame(0, $product->packages()->count());
        $this->assertFalse($product->fresh()->is_active);
        $provider->forceFill(['is_active' => false])->save();
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id], 'publish' => true,
        ])->assertSessionHasErrors('publish');
        $this->assertSame(0, (int) $provider->fresh()->is_active);
        $this->assertSame(0, $product->packages()->count());
    }

    public function test_grouped_import_creates_one_numeric_nominal_with_one_automatic_source(): void
    {
        $this->login();
        $rows = [];
        foreach ([20, 7, 10] as $amount) {
            foreach (['A', 'B'] as $seller) {
                $rows[] = $this->row(['buyer_sku_code' => 'group-'.$amount.'-'.$seller, 'product_name' => 'Restore '.$amount.' Diamonds', 'price' => $amount * 1000 + ($seller === 'B' ? 50 : 0)]);
            }
        }
        $this->fakeCatalog($rows);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->fields()->create(['field_key' => 'destination', 'label' => 'ID tujuan', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $ids = DB::table('digiflazz_catalog_items')->whereIn('buyer_sku_code', ['group-20-A', 'group-7-A', 'group-10-B'])->pluck('id')->all();
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => $ids, 'publish' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $packages = $product->packages()->orderBy('sort_order')->get();
        $this->assertSame([7, 10, 20], $packages->pluck('nominal_value')->map(fn ($value): int => (int) $value)->all());
        foreach ($packages as $package) {
            $this->assertSame(1, $package->mappings()->count());
            $this->assertSame(1, $package->mappings()->where('is_active', true)->count());
            $this->assertSame([0], $package->mappings()->orderBy('priority')->pluck('priority')->map(fn ($value): int => (int) $value)->all());
            // Price is calculated from current seller SKU and product-level 10% markup.
            $quote = app(CheckoutPricing::class)->forPackage($package->id);
            $sellerPrice = (int) $package->mappings()->firstOrFail()->cost_idr;
            $this->assertSame('PRODUCT_MARGIN', $package->pricing_mode);
            $this->assertSame($sellerPrice + intdiv(($sellerPrice * 10) + 99, 100), $quote['subtotal_idr']);
            $this->assertSame(1, $package->mappings()->count());
        }
        $response = $this->get('/catalog/'.$product->slug);
        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page): void {
            $page->where('packages.0.nominal_value', 7);
            $page->where('packages.1.nominal_value', 10);
            $page->where('packages.2.nominal_value', 20);
        });
    }

    public function test_import_keeps_exactly_one_selected_source_even_when_cheaper_peers_exist(): void
    {
        $this->login();
        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'rank-expensive', 'product_name' => 'Restore 10 Diamonds', 'price' => 10500]),
            $this->row(['buyer_sku_code' => 'rank-cheap', 'product_name' => 'Restore 10 Diamonds', 'price' => 10000]),
            $this->row(['buyer_sku_code' => 'rank-middle', 'product_name' => 'Restore 10 Diamonds', 'price' => 10200]),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->fields()->create([
            'field_key' => 'destination', 'label' => 'ID tujuan',
            'type' => 'text', 'is_required' => true, 'sort_order' => 0,
        ]);
        $skuId = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'rank-expensive')->value('id');
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$skuId], 'publish' => true,
        ])->assertSessionHasNoErrors();
        $package = $product->packages()->sole();
        $this->assertSame(['rank-expensive'], $package->mappings()->pluck('external_sku')->all());
        $this->post('/admin/catalog/products/'.$product->id.'/auto-sources')->assertNotFound();
        $this->assertSame(1, $package->mappings()->count());
        $this->assertSame('rank-expensive', app(CheckoutPricing::class)->forPackage($package->id)['provider_sku']);
    }

    public function test_import_rejects_duplicate_nominals(): void
    {
        $this->login();
        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'plain', 'product_name' => 'Restore 10 Diamonds']),
            $this->row(['buyer_sku_code' => 'region', 'product_name' => 'Restore 10 Diamonds', 'type' => 'Global']),
            $this->row(['buyer_sku_code' => 'bonus', 'product_name' => 'Restore 10 Diamonds + Bonus']),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        $product = $this->product();
        $ids = DB::table('digiflazz_catalog_items')->whereIn('buyer_sku_code', ['plain', 'region', 'bonus'])->pluck('id')->all();
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => $ids,
        ])->assertSessionHasErrors('item_ids');
        $this->assertSame(0, $product->packages()->count());
    }

    public function test_full_sync_does_not_attach_backups_and_preserves_active_orphaned_mapping(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'sync-A']), $this->row(['buyer_sku_code' => 'sync-B', 'price' => 10100])]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->fields()->create(['field_key' => 'destination', 'label' => 'ID tujuan', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'sync-A')->value('id');
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id], 'publish' => true,
        ])->assertSessionHasNoErrors();
        $package = $product->packages()->firstOrFail();
        $this->assertSame(1, $package->mappings()->count());
        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'sync-B', 'price' => 10100]),
            $this->row(['buyer_sku_code' => 'sync-C', 'price' => 10200]),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseMissing('digiflazz_catalog_items', ['buyer_sku_code' => 'sync-A']);
        // Mapping tanpa riwayat order/fulfillment dibersihkan saat sync (D-SKU rule).
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'sync-A']);
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'sync-C']);
        $this->assertArrayNotHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'sync-A'])]);
        app(DigiflazzCatalogService::class)->sync();
        // Mapping tanpa riwayat sudah dihapus — sync tidak bisa membuatnya
        // kembali secara otomatis; catalog item sync-A kembali hadir tapi
        // perlu di-import ulang oleh admin bila diperlukan.
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'sync-A', 'is_present' => true]);
        $this->assertArrayNotHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));
    }

    public function test_import_rejects_legacy_auto_sources_payload_and_auto_source_route(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'bulk-A'])]);
        app(DigiflazzCatalogService::class)->sync();
        $product = $this->product();
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'bulk-A')->value('id');

        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id], 'auto_sources' => false,
        ])->assertSessionHasErrors('auto_sources');
        $this->assertSame(0, $product->packages()->count());
        $this->post('/admin/catalog/products/'.$product->id.'/auto-sources')->assertNotFound();
    }

    public function test_thousand_supplier_skus_import_as_five_hundred_nominals_without_auto_backups(): void
    {
        $this->login();
        $rows = [];
        for ($amount = 1; $amount <= 500; $amount++) {
            foreach (['A', 'B'] as $seller) {
                $rows[] = $this->row(['buyer_sku_code' => 'large-'.$amount.'-'.$seller, 'product_name' => 'Restore '.$amount.' Diamonds', 'price' => 10000 + $amount + ($seller === 'B' ? 1 : 0)]);
            }
        }
        $this->fakeCatalog($rows);
        app(DigiflazzCatalogService::class)->sync();
        $product = $this->product();
        $ids = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'like', 'large-%-A')->pluck('id')->all();
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => $ids,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(500, $product->packages()->count());
        $this->assertSame(500, ProviderMapping::whereIn('product_package_id', $product->packages()->pluck('id'))->count());
        $this->assertSame(1, (int) $product->packages()->orderBy('sort_order')->firstOrFail()->nominal_value);
        $this->assertSame(500, (int) $product->packages()->orderByDesc('sort_order')->firstOrFail()->nominal_value);
        Http::assertSentCount(1);
    }

    public function test_customer_nominal_order_is_numeric_even_when_imported_values_are_missing(): void
    {
        $names = [100, 2000, 10, 200, 20, 7];
        $items = collect($names)->map(fn (int $value): object => (object) ['id' => $value, 'name' => 'Game '.$value.' Diamonds', 'nominal_value' => null]);
        $ordered = app(CatalogNominalOrder::class)->sort($items);
        $this->assertSame([7, 10, 20, 100, 200, 2000], $ordered->pluck('id')->all());
        $this->assertSame(2000, app(CatalogNominalOrder::class)->value('Game 2.000 Diamonds', 'Game'));
    }

    public function test_invalid_provider_response_is_atomic_and_does_not_reprice(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        $this->fakeCatalog([$this->row(['price' => 15000]), $this->row(['buyer_sku_code' => 'bad', 'price' => 0])]);
        $this->post('/admin/digiflazz/sync')->assertSessionHasErrors('sync');
        $this->assertSame(10000, (int) DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('price_idr'));
    }

    public function test_digiflazz_import_respects_configured_product_tabs(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row(['type' => 'Weekly'])]);
        app(DigiflazzCatalogService::class)->sync();

        $product = $this->product();
        $product->update(['package_tabs_enabled' => true, 'package_tabs' => ['Diamonds']]);
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');

        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id],
        ])->assertSessionHasErrors('item_ids');
        $this->assertSame(0, $product->packages()->count());

        $product->update(['package_tabs' => ['Diamonds', 'Weekly']]);
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Weekly', $product->packages()->firstOrFail()->group_name);
    }

    public function test_unavailable_and_stale_skus_cannot_be_imported(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row(['seller_product_status' => false])]);
        app(DigiflazzCatalogService::class)->sync();
        $id = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('id');
        $product = $this->product();
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$id]])->assertSessionHasErrors('item_ids');
        DB::table('digiflazz_catalog_items')->where('id', $id)->update(['seller_active' => true, 'synced_at' => now()->subDays(2)]);
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$id]])->assertSessionHasErrors('item_ids');
        $this->assertSame(0, $product->packages()->count());
    }

    public function test_price_modes_and_availability_are_enforced_server_side(): void
    {
        $product = $this->product();
        $package = ProductPackage::create(['product_id' => $product->id, 'code' => 'R10', 'name' => '10', 'is_active' => true]);
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();
        ProviderMapping::create(['product_package_id' => $package->id, 'provider_id' => $provider->id, 'external_sku' => 'restore-sku', 'cost_idr' => 10000, 'max_price_idr' => 10000, 'is_active' => true]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
        $pricing = app(CheckoutPricing::class);
        $this->assertSame(11000, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'PERCENT', 'margin_percent' => 5]);
        $this->assertSame(11000, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'FIXED', 'margin_fixed_idr' => 123]);
        $this->assertSame(11000, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 15000]);
        $this->assertSame(11000, $pricing->forPackage($package->id)['subtotal_idr']);
        $this->fakeCatalog([$this->row(['seller_product_status' => false])]);
        app(DigiflazzCatalogService::class)->sync();
        $this->expectException(ValidationException::class);
        $pricing->forPackage($package->id);
    }

    public function test_cut_off_crossing_midnight_and_stock_are_checked(): void
    {
        $service = app(DigiflazzCatalogService::class);
        $item = (object) ['buyer_active' => true, 'seller_active' => true, 'unlimited_stock' => true, 'stock' => 0, 'start_cut_off' => '23:45', 'end_cut_off' => '00:15'];
        $this->travelTo(now('Asia/Jakarta')->startOfDay());
        $this->assertFalse($service->available($item));
        $this->travelTo(now('Asia/Jakarta')->startOfDay()->addHours(12));
        $this->assertTrue($service->available($item));
        $item->unlimited_stock = false;
        $this->assertFalse($service->available($item));
        $this->travelBack();
    }

    public function test_reorder_rejects_other_products_and_saves_exact_order(): void
    {
        $this->login();
        $product = $this->product();
        $other = $this->product();
        $a = ProductPackage::create(['product_id' => $product->id, 'code' => 'A', 'name' => 'A', 'sort_order' => 0]);
        $b = ProductPackage::create(['product_id' => $product->id, 'code' => 'B', 'name' => 'B', 'sort_order' => 1]);
        $x = ProductPackage::create(['product_id' => $other->id, 'code' => 'X', 'name' => 'X']);
        $this->put('/admin/catalog/products/'.$product->id.'/packages/reorder', ['ids' => [$a->id, $x->id]])->assertSessionHasErrors('ids');
        $this->put('/admin/catalog/products/'.$product->id.'/packages/reorder', ['ids' => [$b->id, $a->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$b->id, $a->id], $product->packages()->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_fields_save_order_and_protect_nickname_references(): void
    {
        $this->login();
        $product = $this->product();
        $fields = [
            ['field_key' => 'user_id', 'label' => 'User ID', 'placeholder' => '123', 'type' => 'text', 'is_required' => true],
            ['field_key' => 'zone_id', 'label' => 'Zone', 'placeholder' => '456', 'type' => 'text', 'is_required' => true],
        ];
        $this->put('/admin/catalog/products/'.$product->id.'/fields', ['fields' => $fields])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['user_id', 'zone_id'], $product->fields()->orderBy('sort_order')->pluck('field_key')->all());
        $product->update(['nickname_check_enabled' => true, 'nickname_game_code' => 'mobile-legends', 'nickname_user_field_key' => 'user_id', 'nickname_server_field_key' => 'zone_id']);
        $this->put('/admin/catalog/products/'.$product->id.'/fields', ['fields' => [$fields[0]]])->assertSessionHasErrors('fields');
        $this->assertSame(2, $product->fields()->count());
    }

    public function test_categories_have_their_own_page_with_product_counts(): void
    {
        $this->login();
        $category = Category::create([
            'name' => 'Kategori Menu Sendiri', 'slug' => 'kategori-menu-sendiri', 'sort_order' => 92, 'is_active' => true,
        ]);

        $this->get('/admin/catalog/categories')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Categories')
            ->where('categories', fn ($rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['id'] === $category->id && array_key_exists('products_count', $row),
            )));
    }

    public function test_catalog_slugs_are_editable_and_handling_mode_changes_only_while_product_is_empty(): void
    {
        $this->login();
        $category = Category::create([
            'name' => 'Editable Category', 'slug' => 'editable-category', 'sort_order' => 90, 'is_active' => true,
        ]);
        $this->put('/admin/catalog/categories/'.$category->id, [
            'name' => 'Kategori Bisa Diubah', 'slug' => 'kategori-bisa-diubah', 'sort_order' => 91, 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('kategori-bisa-diubah', $category->fresh()->slug);

        $this->post('/admin/catalog/products', [
            'category_id' => $category->id, 'name' => 'Produk Editable', 'slug' => 'produk-custom',
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 11, 'sort_order' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::where('slug', 'produk-custom')->firstOrFail();

        $this->put('/admin/catalog/products/'.$product->id, [
            'category_id' => $category->id, 'name' => 'Produk Editable', 'slug' => 'produk-custom-baru',
            'fulfillment_mode' => 'MANUAL', 'margin_percent' => 11, 'sort_order' => 0, 'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('produk-custom-baru', $product->fresh()->slug);
        $this->assertSame('MANUAL', $product->fresh()->fulfillment_mode);

        $this->post('/admin/catalog/products/'.$product->id.'/packages', [
            'code' => 'CUSTOM10', 'name' => 'Custom 10', 'sort_order' => 0, 'cost_idr' => 10000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->put('/admin/catalog/products/'.$product->id, [
            'category_id' => $category->id, 'name' => 'Produk Editable', 'slug' => 'produk-custom-baru',
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 11, 'sort_order' => 0, 'is_active' => false,
        ])->assertSessionHasErrors('fulfillment_mode');
        $this->assertSame('MANUAL', $product->fresh()->fulfillment_mode);
    }

    public function test_product_cannot_be_activated_without_any_nominal(): void
    {
        $this->login();
        $product = $this->product();
        $product->update(['is_active' => false]);

        $this->put('/admin/catalog/products/'.$product->id, [
            'category_id' => $product->category_id, 'name' => $product->name, 'slug' => $product->slug,
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10,
            'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasErrors('is_active');

        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_manual_mapping_cost_edit_updates_checkout_store_cost(): void
    {
        $this->login();
        $product = $this->product('MANUAL');
        $product->category->update(['is_active' => true]);
        $product->update(['sell_markup_percent' => 10]);
        $provider = Provider::where('code', 'MANUAL')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();
        $package = ProductPackage::create([
            'product_id' => $product->id, 'code' => 'MANUAL-COST', 'name' => 'Manual Cost',
            'pricing_mode' => 'PRODUCT_MARGIN', 'is_active' => true,
        ]);
        $mapping = ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $provider->id,
            'cost_idr' => 10000, 'store_cost_idr' => 10000, 'priority' => 0, 'is_active' => true,
        ]);

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true, 'cost_idr' => 12000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(12000, (int) $mapping->fresh()->store_cost_idr);
        $this->assertSame(13200, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);
    }

    public function test_added_manual_nominal_initializes_authoritative_store_cost(): void
    {
        $this->login();
        $product = $this->product('MANUAL');
        $product->category->update(['is_active' => true]);

        $this->post('/admin/catalog/products/'.$product->id.'/packages', [
            'code' => 'MANUAL-ADDED-COST', 'name' => 'Manual Added Cost', 'sort_order' => 0,
            'cost_idr' => 12345,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $package = $product->packages()->where('code', 'MANUAL-ADDED-COST')->firstOrFail();
        $mapping = $package->mappings()->firstOrFail();
        $this->assertSame(12345, (int) $mapping->cost_idr);
        $this->assertSame(12345, (int) $mapping->store_cost_idr);
    }

    public function test_zero_cost_manual_mapping_can_be_saved_without_changing_checkout_availability(): void
    {
        $this->login();
        $product = $this->product('MANUAL');
        $product->category->update(['is_active' => true]);
        $provider = Provider::where('code', 'MANUAL')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();
        $package = ProductPackage::create([
            'product_id' => $product->id, 'code' => 'ZERO-COST', 'name' => 'Zero Cost',
            'pricing_mode' => 'PRODUCT_MARGIN', 'is_active' => true,
        ]);
        $mapping = ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $provider->id,
            'cost_idr' => 0, 'store_cost_idr' => 0, 'priority' => 0, 'is_active' => false,
        ]);

        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 1, 'is_active' => true, 'cost_idr' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $mapping->fresh()->cost_idr);
        $this->assertSame(0, (int) $mapping->fresh()->store_cost_idr);
        $this->assertSame(1, (int) $mapping->fresh()->priority);
        try {
            app(CheckoutPricing::class)->forPackage($package->id);
            $this->fail('A zero-cost mapping must not become an eligible checkout price.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('package_id', $exception->errors());
        }
    }

    public function test_manual_nominal_can_be_duplicated_and_catalog_deletes_protect_order_history(): void
    {
        $this->login();
        $product = $this->product('MANUAL');
        $provider = Provider::where('code', 'MANUAL')->firstOrFail();
        $package = ProductPackage::create([
            'product_id' => $product->id, 'code' => 'MANUAL10', 'name' => 'Manual 10',
            'group_name' => 'Manual', 'sort_order' => 0, 'is_active' => false,
            'sell_markup_percent' => 13, 'cost_uplift_percent' => 3,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $provider->id,
            'cost_idr' => 9000, 'store_cost_idr' => 9500, 'priority' => 0, 'is_active' => false,
        ]);

        $this->post('/admin/catalog/packages/'.$package->id.'/duplicate')->assertRedirect()->assertSessionHasNoErrors();
        $copy = $product->packages()->where('id', '!=', $package->id)->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertStringContainsString('Salinan', $copy->name);
        $this->assertSame(9000, (int) $copy->mappings()->firstOrFail()->cost_idr);
        $this->assertSame(9500, (int) $copy->mappings()->firstOrFail()->store_cost_idr);
        $this->assertSame('13.0000', (string) $copy->sell_markup_percent);
        $this->assertSame('3.0000', (string) $copy->cost_uplift_percent);
        $copiedMapping = $copy->mappings()->firstOrFail();
        $this->assertFalse($copiedMapping->is_active);

        $this->put('/admin/catalog/mappings/'.$copiedMapping->id, [
            'priority' => 0, 'is_active' => true, 'cost_idr' => 9000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(9500, (int) $copiedMapping->fresh()->store_cost_idr); // Vue omits store_cost_idr until intentionally edited.

        $this->put('/admin/catalog/mappings/'.$copiedMapping->id, [
            'priority' => 0, 'is_active' => true, 'cost_idr' => 10000, 'store_cost_idr' => 11000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(10000, (int) $copiedMapping->fresh()->cost_idr);
        $this->assertSame(11000, (int) $copiedMapping->fresh()->store_cost_idr);

        $this->put('/admin/catalog/mappings/'.$copiedMapping->id, [
            'priority' => 0, 'is_active' => true, 'cost_idr' => 12000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(12000, (int) $copiedMapping->fresh()->store_cost_idr);

        $this->put('/admin/catalog/mappings/'.$copiedMapping->id, [
            'priority' => 0, 'is_active' => true, 'cost_idr' => 12000, 'store_cost_idr' => 9000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(9000, (int) $copiedMapping->fresh()->store_cost_idr);
        $product->update(['is_active' => true]);
        $provider->forceFill(['is_active' => true])->save();
        $copy->update(['is_active' => true]);
        $quote = app(CheckoutPricing::class)->forPackage($copy->id);
        $this->assertSame(9000, $quote['cost_idr']);
        $this->assertSame(10170, $quote['subtotal_idr']);

        $this->delete('/admin/catalog/packages/'.$copy->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(ProductPackage::find($copy->id));

        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'CATALOG-GUARD-'.bin2hex(random_bytes(4)),
            'product_id' => $product->id, 'product_package_id' => $package->id,
            'status' => 'PENDING_PAYMENT', 'customer_input' => '{}', 'snapshot' => '{}',
            'cost_idr' => 9000, 'margin_idr' => 0, 'total_idr' => 9000,
            'idempotency_key' => bin2hex(random_bytes(20)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->delete('/admin/catalog/packages/'.$package->id)->assertSessionHasErrors('package');
        $this->delete('/admin/catalog/products/'.$product->id)->assertSessionHasErrors('product');
        $this->assertNotNull(ProductPackage::find($package->id));
        $this->assertNotNull(Product::find($product->id));
        DB::table('orders')->where('id', $orderId)->delete();

        $this->delete('/admin/catalog/products/'.$product->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Product::find($product->id));
    }

    public function test_product_display_controls_and_package_tabs_are_configurable_and_reach_customer_frontend(): void
    {
        $this->login();
        $product = $this->product('MANUAL');
        $product->update(['is_active' => false]);
        $provider = Provider::where('code', 'MANUAL')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();

        foreach ([['A', 'Diamond 10', 'Diamonds', 10000], ['B', 'Weekly Pass', 'Pass', 20000]] as [$code, $name, $group, $cost]) {
            $package = ProductPackage::create([
                'product_id' => $product->id, 'code' => $code, 'name' => $name,
                'group_name' => $group, 'sort_order' => $code === 'A' ? 0 : 1, 'is_active' => true,
            ]);
            ProviderMapping::create([
                'product_package_id' => $package->id, 'provider_id' => $provider->id,
                'cost_idr' => $cost, 'priority' => 0, 'is_active' => true,
            ]);
        }

        $this->put('/admin/catalog/products/'.$product->id, [
            'category_id' => $product->category_id, 'name' => $product->name, 'slug' => $product->slug,
            'fulfillment_mode' => 'MANUAL', 'margin_percent' => 10, 'sort_order' => 0, 'is_active' => true,
            'initials' => 'RT', 'accent_color' => '#123456', 'instant' => true,
            'package_tabs_enabled' => true, 'package_tabs' => ['Pass', 'Diamonds'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data', fn ($products) => collect($products)->contains(
                fn ($row) => $row['slug'] === $product->slug
                    && $row['initials'] === 'RT'
                    && $row['accent_color'] === '#123456'
                    && $row['instant'] === true
            )));

        $this->get('/catalog/'.$product->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.package_tabs_enabled', true)
            ->where('product.package_tabs', ['Pass', 'Diamonds'])
            ->where('packages.0.group_name', 'Diamonds')
            ->where('packages.1.group_name', 'Pass'));
    }

    public function test_voucher_stock_is_editable_encrypted_and_delivered_once(): void
    {
        Queue::fake();
        $this->login();
        $product = $this->product();
        $package = ProductPackage::create([
            'product_id' => $product->id,
            'code' => 'STOCK10',
            'name' => 'Kode Digital 10',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->post('/admin/catalog/packages/'.$package->id.'/voucher-stock', [
            'stock_key' => 'digital.kode-10',
            'cost_idr' => 5000,
            'priority' => 0,
            'is_active' => true,
            'codes_text' => "SECRET-CODE-001\nSECRET-CODE-002\nSECRET-CODE-001",
        ])->assertRedirect()->assertSessionHasNoErrors();

        $provider = Provider::where('code', 'VOUCHER_STOCK')->firstOrFail();
        $mapping = ProviderMapping::where('product_package_id', $package->id)
            ->where('provider_id', $provider->id)
            ->firstOrFail();
        $this->assertSame('digital.kode-10', data_get($mapping->fulfillment_config, 'stock_key'));
        $this->assertSame(5000, (int) $mapping->store_cost_idr);
        $this->assertSame(2, DB::table('voucher_stock_codes')->where('stock_key', 'digital.kode-10')->count());
        $this->assertSame(2, DB::table('voucher_stock_codes')->where('status', 'AVAILABLE')->count());
        $this->assertDatabaseMissing('voucher_stock_codes', ['code_ciphertext' => 'SECRET-CODE-001']);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('VOUCHER_STOCK', $quote['provider_code']);
        $this->assertSame(5500, $quote['subtotal_idr']);

        $snapshot = [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'fulfillment_mode' => 'AUTO_PROVIDER',
            ],
            'package' => ['id' => $package->id, 'name' => $package->name],
            'provider' => [
                'mapping_id' => $mapping->id,
                'code' => 'VOUCHER_STOCK',
                'sku' => $mapping->external_sku,
                'cost_idr' => 5000,
                'max_price_idr' => null,
            ],
            'pricing' => ['cost_idr' => 5000, 'total_idr' => 5500],
            'customer_input' => [],
        ];
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'STOCK-'.bin2hex(random_bytes(6)),
            'product_id' => $product->id,
            'product_package_id' => $package->id,
            'provider_mapping_id' => $mapping->id,
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => '{}',
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'cost_idr' => 5000,
            'margin_idr' => 500,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 5500,
            'idempotency_key' => bin2hex(random_bytes(20)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $service->sendAttempt((int) $attempt->id);
        $service->sendAttempt((int) $attempt->id);

        $order = DB::table('orders')->where('id', $orderId)->first();
        $delivery = json_decode((string) $order->delivery_payload, true);
        $this->assertSame('SUCCESS', $order->status);
        $this->assertContains($delivery['code'], ['SECRET-CODE-001', 'SECRET-CODE-002']);
        $this->assertSame(1, DB::table('voucher_stock_codes')->where('order_id', $orderId)->where('status', 'DELIVERED')->count());
        $this->assertSame(1, DB::table('voucher_stock_codes')->where('stock_key', 'digital.kode-10')->where('status', 'AVAILABLE')->count());
    }

    public function test_voucher_stock_reconciliation_stays_local_after_interrupted_send(): void
    {
        Queue::fake();
        Http::swap(new Factory);
        Http::fake();
        $this->login();
        $product = $this->product();
        $package = ProductPackage::create([
            'product_id' => $product->id,
            'code' => 'STOCKREC',
            'name' => 'Kode Recovery',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->post('/admin/catalog/packages/'.$package->id.'/voucher-stock', [
            'stock_key' => 'digital.recovery',
            'cost_idr' => 6000,
            'priority' => 0,
            'is_active' => true,
            'codes_text' => 'RECOVERY-CODE-001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $mapping = ProviderMapping::where('product_package_id', $package->id)
            ->where('provider_id', Provider::where('code', 'VOUCHER_STOCK')->value('id'))
            ->firstOrFail();
        $snapshot = [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'fulfillment_mode' => 'AUTO_PROVIDER',
            ],
            'package' => ['id' => $package->id, 'name' => $package->name],
            'provider' => [
                'mapping_id' => $mapping->id,
                'code' => 'VOUCHER_STOCK',
                'sku' => $mapping->external_sku,
                'cost_idr' => 6000,
                'max_price_idr' => null,
            ],
            'pricing' => ['cost_idr' => 6000, 'total_idr' => 6600],
            'customer_input' => [],
        ];
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'STOCK-REC-'.bin2hex(random_bytes(5)),
            'product_id' => $product->id,
            'product_package_id' => $package->id,
            'provider_mapping_id' => $mapping->id,
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => '{}',
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'cost_idr' => 6000,
            'margin_idr' => 600,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 6600,
            'idempotency_key' => bin2hex(random_bytes(20)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
            'status' => 'SENDING',
            'request_payload' => json_encode([
                'stock_key' => 'digital.recovery',
                'ref_id' => $attempt->external_reference,
            ], JSON_THROW_ON_ERROR),
        ]);

        $service->reconcileAttempt((int) $attempt->id);

        $order = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $delivery = json_decode((string) $order->delivery_payload, true);
        $this->assertSame('SUCCESS', $order->status);
        $this->assertSame('RECOVERY-CODE-001', $delivery['code']);
        $this->assertDatabaseHas('voucher_stock_codes', [
            'order_id' => $orderId,
            'status' => 'DELIVERED',
        ]);
        Http::assertNothingSent();
    }

    public function test_fixed_price_import_cutoff_when_both_sellers_spike(): void
    {
        $this->login();
        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'fixed-price-A', 'price' => 10000]),
            $this->row(['buyer_sku_code' => 'fixed-price-B', 'price' => 10010]),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->fields()->create([
            'field_key' => 'destination', 'label' => 'ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $itemId = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'fixed-price-A')->value('id');
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$itemId], 'publish' => true,
        ])->assertSessionHasNoErrors();

        $package = $product->packages()->firstOrFail();
        $this->assertSame('PRODUCT_MARGIN', $package->pricing_mode);
        $this->assertSame(11000, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);
        $this->assertSame(10000, app(CheckoutPricing::class)->forPackage($package->id)['max_price_idr']);

        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'fixed-price-A', 'price' => 12500]),
            $this->row(['buyer_sku_code' => 'fixed-price-B', 'price' => 10010]),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        // The unselected seller is never auto-attached as a backup.
        $this->assertSame(13750, app(CheckoutPricing::class)->forPackages([$package->id])[$package->id]['subtotal_idr']);

        $this->fakeCatalog([
            $this->row(['buyer_sku_code' => 'fixed-price-A', 'price' => 12500]),
            $this->row(['buyer_sku_code' => 'fixed-price-B', 'price' => 11800]),
        ]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertSame(13750, app(CheckoutPricing::class)->forPackages([$package->id])[$package->id]['subtotal_idr']);
        $this->assertSame('PRODUCT_MARGIN', $package->fresh()->pricing_mode);
    }

    public function test_fixed_price_recommendation_uses_store_cost_and_checkout_price_matches_admin_preview(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row(['buyer_sku_code' => 'store-price-sku', 'price' => 10000])]);
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = $this->product();
        $product->category->update(['is_active' => true]);
        $product->update(['sell_markup_percent' => 10]);
        $package = $product->packages()->create([
            'code' => 'DF_store_cost', 'name' => 'Paket Store Cost', 'pricing_mode' => 'PRODUCT_MARGIN',
            'margin_percent' => 0, 'is_active' => true,
        ]);
        $package->mappings()->create([
            'provider_id' => Provider::where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'store-price-sku', 'cost_idr' => 10000,
            'seller_reference_price_idr' => 10000, 'store_cost_idr' => 10200,
            'max_price_idr' => 10200, 'is_active' => true, 'priority' => 0,
        ]);
        app(DigiflazzCatalogService::class)->sync();
        $package->mappings()->where('external_sku', 'store-price-sku')->update([
            'store_cost_idr' => 10200, 'max_price_idr' => 10200,
        ]);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame(11220, $quote['subtotal_idr']);

        $proposal = collect(app(DigiflazzFixedPricePolicy::class)->proposals())
            ->firstWhere('package_id', $package->id);
        $this->assertSame(11220, $proposal['sell_price_idr']);

        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'store-price-sku')->update(['price_idr' => 12500]);
        $this->assertNull(collect(app(DigiflazzFixedPricePolicy::class)->proposals())->firstWhere('package_id', $package->id));
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'store-price-sku')->update(['price_idr' => 10000]);

        $package->update(['pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 15000]);
        $this->assertSame(15000, app(CheckoutPricing::class)->forPackage($package->id)['subtotal_idr']);

        $this->get('/admin/catalog')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Catalog')
            ->where('checkoutPreviews.'.$package->id, 15000)
            ->has('products', 1)
            ->where('products.0.packages.0.id', $package->id)
            ->where('products.0.packages.0.mappings.0.store_cost_idr', 10200));

        $package->mappings()->update(['is_active' => false]);
        $this->assertArrayNotHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id], true));
        $this->assertArrayNotHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));
    }

    public function test_fixed_sell_price_below_cost_is_rejected(): void
    {
        $this->login();
        $product = $this->product();
        $package = ProductPackage::create(['product_id' => $product->id, 'code' => 'FLOOR', 'name' => 'Floor']);
        // Mapping AKTIF: guard D17.3 hanya menghitung mapping aktif — mapping
        // nonaktif memang sengaja tidak ikut guard (kasus nonaktif sudah
        // dicover AdminPackagePriceGuardTest).
        ProviderMapping::create(['product_package_id' => $package->id, 'provider_id' => Provider::where('code', 'DIGIFLAZZ')->value('id'), 'external_sku' => 'floor-sku', 'cost_idr' => 10000, 'max_price_idr' => 10000, 'is_active' => true]);
        $this->put('/admin/catalog/packages/'.$package->id, ['code' => 'FLOOR', 'name' => 'Floor', 'sort_order' => 0, 'is_active' => false,
            'pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 9000])->assertSessionHasErrors('sell_price_idr');
        $this->assertSame('PRODUCT_MARGIN', $package->fresh()->pricing_mode);
    }

    public function test_full_sync_blocks_mapped_skus_absent_from_response(): void
    {
        $product = $this->product();
        $package = ProductPackage::create(['product_id' => $product->id, 'code' => 'GONE', 'name' => 'Gone', 'is_active' => true]);
        Provider::where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        ProviderMapping::create(['product_package_id' => $package->id, 'provider_id' => Provider::where('code', 'DIGIFLAZZ')->value('id'), 'external_sku' => 'gone-sku', 'cost_idr' => 10000, 'max_price_idr' => 10000, 'is_active' => true]);
        $this->fakeCatalog([$this->row()]);
        app(DigiflazzCatalogService::class)->sync();
        $this->assertDatabaseMissing('digiflazz_catalog_items', ['buyer_sku_code' => 'gone-sku']);
        // Mapping tanpa riwayat order/fulfillment dibersihkan saat sync (D-SKU rule).
        $this->assertDatabaseMissing('provider_mappings', ['external_sku' => 'gone-sku']);
        // Checkout untuk package ini tidak tersedia karena mapping sudah hilang.
        $this->assertArrayNotHasKey($package->id, app(CheckoutPricing::class)->forPackages([$package->id]));
    }

    public function test_new_operations_are_permission_gated(): void
    {
        $this->login(['dashboard.view'], 'ADMIN');
        $this->post('/admin/digiflazz/sync')->assertForbidden();
        $this->put('/admin/support/quick-replies', ['replies' => ['Hello']])->assertForbidden();
    }

    public function test_support_reply_is_saved_with_status_and_audit(): void
    {
        Queue::fake();
        $this->login();
        $user = tap(User::create(['name' => 'Support User', 'email' => bin2hex(random_bytes(4)).'@example.test', 'password' => bcrypt('support-password-123')]), fn ($u) => $u->forceFill(['membership_tier_code' => 'BASIC'])->saveQuietly());
        $id = DB::table('support_tickets')->insertGetId(['user_id' => $user->id, 'subject' => 'Help', 'message' => 'Help me', 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
        $this->put('/admin/support/'.$id, ['status' => 'IN_PROGRESS', 'reply' => 'Sedang kami periksa.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'status' => 'IN_PROGRESS']);
        $this->assertDatabaseHas('support_ticket_messages', ['support_ticket_id' => $id, 'sender_type' => 'ADMIN', 'message' => 'Sedang kami periksa.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.updated', 'target_id' => (string) $id]);
    }

    public function test_populated_order_and_customer_details_include_history(): void
    {
        $this->login();
        $product = $this->product();
        $package = ProductPackage::create(['product_id' => $product->id, 'code' => 'HISTORY', 'name' => 'History']);
        $user = tap(User::create(['name' => 'History User', 'email' => bin2hex(random_bytes(4)).'@example.test', 'password' => bcrypt('history-password-123')]), fn ($u) => $u->forceFill(['membership_tier_code' => 'BASIC'])->saveQuietly());
        $id = DB::table('orders')->insertGetId([
            'order_number' => 'HISTORY-'.bin2hex(random_bytes(4)), 'user_id' => $user->id,
            'product_id' => $product->id, 'product_package_id' => $package->id,
            'status' => 'SUCCESS', 'customer_input' => json_encode(['user_id' => '123']),
            'snapshot' => '{}', 'cost_idr' => 10000, 'margin_idr' => 1000, 'total_idr' => 11000,
            'idempotency_key' => bin2hex(random_bytes(20)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_transactions')->insert(['order_id' => $id, 'gateway_code' => 'MANUAL_QRIS', 'channel_code' => 'manual_qris',
            'amount_idr' => 11000, 'status' => 'PAID', 'idempotency_key' => bin2hex(random_bytes(20)), 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/admin/orders/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/OrderDetail')->has('payments', 1)->etc());
        $this->get('/admin/customers/'.$user->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/CustomerDetail')->has('orders.data', 1)->etc());
    }

    public function test_reports_and_quick_reply_configuration_are_functional(): void
    {
        $this->login();
        $this->get('/admin/reports?from=2026-01-01&to=2026-12-31')->assertOk();
        $this->put('/admin/support/quick-replies', ['replies' => ['Mohon sertakan invoice.']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['Mohon sertakan invoice.'], json_decode(DB::table('system_settings')->where('key', 'support.quick_replies')->value('value'), true));
    }

    public function test_catalog_editor_preserves_custom_order_over_nominal_value(): void
    {
        $this->login();
        $product = $this->product();
        $first = $product->packages()->create(['code' => 'FIRST', 'name' => 'Large first', 'nominal_value' => 100, 'sort_order' => 0]);
        $second = $product->packages()->create(['code' => 'SECOND', 'name' => 'Small second', 'nominal_value' => 1, 'sort_order' => 1]);
        $this->get('/admin/catalog')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $product->id)['packages'][0]['id'] === $first->id));
        $this->put('/admin/catalog/products/'.$product->id.'/packages/reorder', ['ids' => [$second->id, $first->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/admin/catalog')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $product->id)['packages'][0]['id'] === $second->id));
    }

    public function test_owner_controls_supplier_auto_sync_and_admin_without_permission_cannot(): void
    {
        $this->login();
        $this->put('/admin/digiflazz/settings', ['enabled' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(json_decode(DB::table('system_settings')->where('key', 'digiflazz.auto_sync')->value('value')));
        $this->artisan('lfamilia:sync-digiflazz-catalog')->assertExitCode(0);
        $this->login([], 'ADMIN');
        $this->put('/admin/digiflazz/settings', ['enabled' => true])->assertForbidden();
    }

    public function test_cleanup_settings_and_execution_retain_accounts_with_history(): void
    {
        $this->login();
        $this->put('/admin/customers/cleanup/settings', ['enabled' => false, 'inactivity_days' => 7])->assertRedirect()->assertSessionHasNoErrors();
        $empty = User::create(['name' => 'Inactive empty', 'email' => 'empty-cleanup@example.test', 'created_at' => now()->subDays(10)]);
        $funded = User::create(['name' => 'Inactive funded', 'email' => 'funded-cleanup@example.test', 'created_at' => now()->subDays(10)]);
        $empty->forceFill(['created_at' => now()->subDays(10), 'last_active_at' => now()->subDays(10)])->save();
        $funded->forceFill(['created_at' => now()->subDays(10), 'last_active_at' => now()->subDays(10)])->save();
        DB::table('wallets')->where('user_id', $funded->id)->update(['balance_idr' => 1000]);
        $service = app(CustomerCleanupService::class);
        $this->assertSame(0, $service->run());
        $this->assertNotNull(User::find($empty->id));
        $this->post('/admin/customers/cleanup/run')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(User::find($empty->id));
        $this->assertNotNull(User::find($funded->id));
        $this->assertNull(User::withTrashed()->find($empty->id)->email);
        $this->delete('/admin/customers/'.$funded->id)->assertSessionHasErrors('account');
        $this->put('/admin/customers/cleanup/settings', ['enabled' => true, 'inactivity_days' => 6])->assertSessionHasErrors('inactivity_days');
        $this->login([], 'ADMIN');
        $this->post('/admin/customers/cleanup/run')->assertForbidden();
        $this->delete('/admin/customers/'.$funded->id)->assertForbidden();
    }

    public function test_admin_controls_global_cost_uplift_and_sell_markup(): void
    {
        $this->login();

        $this->put('/admin/catalog/pricing', ['cost_uplift_percent' => 1001, 'sell_markup_percent' => 10])
            ->assertSessionHasErrors('cost_uplift_percent');
        $this->put('/admin/catalog/pricing', ['cost_uplift_percent' => 2, 'sell_markup_percent' => -1])
            ->assertSessionHasErrors('sell_markup_percent');

        $this->put('/admin/catalog/pricing', ['cost_uplift_percent' => 3, 'sell_markup_percent' => 15.5])
            ->assertRedirect()->assertSessionHasNoErrors();

        $settings = app(CatalogPricingSettings::class);
        $this->assertSame('3', $settings->costUpliftPercent());
        $this->assertSame('15.5', $settings->sellMarkupPercent());
        // Exact percent arithmetic: 10.200 + 15% = 11.730.
        $this->assertSame(11730, $settings->applyPercent(10200, '15'));
        // Rounded up, never down: 10.001 + 3% = 10.301,03 -> 10.302.
        $this->assertSame(10302, $settings->applyPercent(10001, '3'));

        $this->login([], 'ADMIN');
        $this->put('/admin/catalog/pricing', ['cost_uplift_percent' => 4, 'sell_markup_percent' => 20])
            ->assertForbidden();
        $this->assertSame('3', app(CatalogPricingSettings::class)->costUpliftPercent());
    }

    public function test_admin_can_set_and_clear_markup_and_uplift_overrides_per_game_and_nominal(): void
    {
        $this->login();
        $product = $this->product();

        // Produk tidak punya markup sendiri: angkanya hidup di nominal.
        $this->put('/admin/catalog/products/'.$product->id, [
            'category_id' => $product->category_id, 'name' => $product->name, 'slug' => $product->slug,
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10, 'sort_order' => 0, 'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->sell_markup_percent);

        $package = ProductPackage::create([
            'product_id' => $product->id, 'code' => 'OVR10', 'name' => 'Override 10',
            'sort_order' => 0, 'is_active' => false, 'pricing_mode' => 'PRODUCT_MARGIN',
        ]);
        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'OVR10', 'name' => 'Override 10', 'sort_order' => 0, 'is_active' => false,
            'pricing_mode' => 'PRODUCT_MARGIN', 'sell_markup_percent' => 8, 'cost_uplift_percent' => 1.5,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8.0, (float) $package->fresh()->sell_markup_percent);
        $this->assertSame(1.5, (float) $package->fresh()->cost_uplift_percent);

        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'OVR10', 'name' => 'Override 10', 'sort_order' => 0, 'is_active' => false,
            'pricing_mode' => 'PRODUCT_MARGIN', 'sell_markup_percent' => null, 'cost_uplift_percent' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($package->fresh()->sell_markup_percent);
        $this->assertNull($package->fresh()->cost_uplift_percent);
    }

    public function test_catalog_index_exposes_pricing_controls_and_store_cost_columns(): void
    {
        $this->login();
        $product = $this->product();
        $product->update(['cost_uplift_percent' => 2]);
        $package = ProductPackage::create([
            'product_id' => $product->id, 'code' => 'VIEW10', 'name' => 'View 10',
            'sort_order' => 0, 'is_active' => false, 'pricing_mode' => 'PRODUCT_MARGIN',
            'sell_markup_percent' => 9,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => Provider::where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'view-sku', 'cost_idr' => 10000,
            'seller_reference_price_idr' => 10000, 'store_cost_idr' => 10200,
            'max_price_idr' => 10200, 'priority' => 0, 'is_active' => false,
        ]);

        $this->get('/admin/catalog')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pricingSettings.cost_uplift_percent', '2')
            ->where('pricingSettings.sell_markup_percent', '10')
            ->where('products', function ($products) use ($product, $package) {
                $row = collect($products)->firstWhere('id', $product->id);
                $nominal = collect($row['packages'])->firstWhere('id', $package->id);
                $mapping = $nominal['mappings'][0];

                return ! array_key_exists('sell_markup_percent', $row)
                    && (float) $nominal['sell_markup_percent'] === 9.0
                    && array_key_exists('cost_uplift_percent', $nominal)
                    && (int) $mapping['store_cost_idr'] === 10200
                    && (int) $mapping['seller_reference_price_idr'] === 10000;
            }));
    }
}
