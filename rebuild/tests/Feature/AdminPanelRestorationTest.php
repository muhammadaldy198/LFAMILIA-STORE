<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Models\User;
use App\Services\CheckoutPricing;
use App\Services\CustomerCleanupService;
use App\Services\DigiflazzCatalogService;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $this->actingAs(AdminUser::create([
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

    public function test_sync_import_and_resync_preserve_baseline_and_inactive_import(): void
    {
        $this->login();
        $this->fakeCatalog([$this->row()]);
        $this->post('/admin/digiflazz/sync')->assertRedirect()->assertSessionHasNoErrors();
        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->first();
        $product = $this->product();
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$item->id], 'margin_percent' => 12])->assertRedirect()->assertSessionHasNoErrors();
        $package = $product->packages()->firstOrFail();
        $mapping = $package->mappings()->firstOrFail();
        $this->assertFalse($package->is_active);
        $this->assertFalse($mapping->is_active);
        $this->assertSame(10000, (int) $mapping->max_price_idr);
        $this->fakeCatalog([$this->row(['price' => 12000])]);
        $this->post('/admin/catalog/mappings/'.$mapping->id.'/sync')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(12000, (int) $mapping->fresh()->cost_idr);
        $this->assertSame(10000, (int) DB::table('digiflazz_catalog_items')->where('id', $item->id)->value('baseline_price_idr'));
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$item->id], 'margin_percent' => 12])->assertSessionHasErrors('item_ids');
        $this->assertSame(1, $product->packages()->count());
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
            'item_ids' => [$id], 'margin_percent' => 10,
        ])->assertSessionHasErrors('item_ids');
        $this->assertSame(0, $product->packages()->count());

        $product->update(['package_tabs' => ['Diamonds', 'Weekly']]);
        $this->post('/admin/catalog/products/'.$product->id.'/import', [
            'item_ids' => [$id], 'margin_percent' => 10,
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
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$id], 'margin_percent' => 10])->assertSessionHasErrors('item_ids');
        DB::table('digiflazz_catalog_items')->where('id', $id)->update(['seller_active' => true, 'synced_at' => now()->subDays(2)]);
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$id], 'margin_percent' => 10])->assertSessionHasErrors('item_ids');
        $this->assertSame(0, $product->packages()->count());
    }

    public function test_price_modes_and_availability_are_enforced_server_side(): void
    {
        $product = $this->product();
        $package = ProductPackage::create(['product_id' => $product->id, 'code' => 'R10', 'name' => '10', 'is_active' => true]);
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $provider->forceFill(['is_active' => true])->save();
        ProviderMapping::create(['product_package_id' => $package->id, 'provider_id' => $provider->id, 'external_sku' => 'restore-sku', 'cost_idr' => 10000, 'max_price_idr' => 10000, 'is_active' => true]);
        $pricing = app(CheckoutPricing::class);
        $this->assertSame(11000, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'PERCENT', 'margin_percent' => 5]);
        $this->assertSame(10500, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'FIXED', 'margin_fixed_idr' => 123]);
        $this->assertSame(10123, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 15000]);
        $this->assertSame(15000, $pricing->forPackage($package->id)['subtotal_idr']);
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

    public function test_global_margin_updates_only_automatic_products_and_preserves_manual_and_nominal_overrides(): void
    {
        $this->login();
        $product = $this->product();
        $manual = $this->product('MANUAL');
        $manual->update(['margin_percent' => 9]);
        $package = ProductPackage::create(['product_id' => $product->id, 'code' => 'OVERRIDE', 'name' => 'Override', 'pricing_mode' => 'PERCENT', 'margin_percent' => 7]);
        $this->put('/admin/catalog/margin', ['margin_percent' => 15])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('15.0000', (string) $product->fresh()->margin_percent);
        $this->assertSame('9.0000', (string) $manual->fresh()->margin_percent);
        $this->assertSame('7.0000', (string) $package->fresh()->margin_percent);
        $this->assertSame(15.0, (float) json_decode(DB::table('system_settings')->where('key', 'catalog.default_margin_percent')->value('value'), true));
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.margin.global_updated']);
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

    public function test_manual_nominal_can_be_duplicated_and_catalog_deletes_protect_order_history(): void
    {
        $this->login();
        $product = $this->product('MANUAL');
        $provider = Provider::where('code', 'MANUAL')->firstOrFail();
        $package = ProductPackage::create([
            'product_id' => $product->id, 'code' => 'MANUAL10', 'name' => 'Manual 10',
            'group_name' => 'Manual', 'sort_order' => 0, 'is_active' => false,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $provider->id,
            'cost_idr' => 9000, 'priority' => 0, 'is_active' => false,
        ]);

        $this->post('/admin/catalog/packages/'.$package->id.'/duplicate')->assertRedirect()->assertSessionHasNoErrors();
        $copy = $product->packages()->where('id', '!=', $package->id)->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertStringContainsString('Salinan', $copy->name);
        $this->assertSame(9000, (int) $copy->mappings()->firstOrFail()->cost_idr);
        $this->assertFalse($copy->mappings()->firstOrFail()->is_active);

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
        $this->assertSame(2, DB::table('voucher_stock_codes')->where('stock_key', 'digital.kode-10')->count());
        $this->assertSame(2, DB::table('voucher_stock_codes')->where('status', 'AVAILABLE')->count());
        $this->assertDatabaseMissing('voucher_stock_codes', ['code_ciphertext' => 'SECRET-CODE-001']);

        $quote = app(CheckoutPricing::class)->forPackage($package->id);
        $this->assertSame('VOUCHER_STOCK', $quote['provider_code']);

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

    public function test_fixed_sell_price_below_cost_is_rejected(): void
    {
        $this->login();
        $product = $this->product();
        $package = ProductPackage::create(['product_id' => $product->id, 'code' => 'FLOOR', 'name' => 'Floor']);
        ProviderMapping::create(['product_package_id' => $package->id, 'provider_id' => Provider::where('code', 'DIGIFLAZZ')->value('id'), 'external_sku' => 'floor-sku', 'cost_idr' => 10000, 'max_price_idr' => 10000, 'is_active' => false]);
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
        $this->assertDatabaseHas('digiflazz_catalog_items', ['buyer_sku_code' => 'gone-sku', 'buyer_active' => false]);
        $this->expectException(ValidationException::class);
        app(CheckoutPricing::class)->forPackage($package->id);
    }

    public function test_new_operations_are_permission_gated(): void
    {
        $this->login(['dashboard.view'], 'ADMIN');
        $this->post('/admin/digiflazz/sync')->assertForbidden();
        $this->put('/admin/catalog/margin', ['margin_percent' => 10])->assertForbidden();
        $this->put('/admin/support/quick-replies', ['replies' => ['Hello']])->assertForbidden();
    }

    public function test_support_reply_is_saved_with_status_and_audit(): void
    {
        Queue::fake();
        $this->login();
        $user = User::create(['name' => 'Support User', 'email' => bin2hex(random_bytes(4)).'@example.test', 'password' => bcrypt('support-password-123'), 'membership_tier_code' => 'BASIC']);
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
        $user = User::create(['name' => 'History User', 'email' => bin2hex(random_bytes(4)).'@example.test', 'password' => bcrypt('history-password-123'), 'membership_tier_code' => 'BASIC']);
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

    public function test_admin_activation_is_single_use_and_login_redirects_to_panel(): void
    {
        $admin = AdminUser::create(['name' => 'Activation', 'email' => 'activation-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => bcrypt('before-password-123'), 'role' => 'SUPER_ADMIN', 'is_active' => true]);
        $token = bin2hex(random_bytes(32));
        $key = 'admin_activation:'.hash('sha256', $token);
        Cache::put($key, ['id' => $admin->id, 'password_fingerprint' => hash('sha256', $admin->password)], now()->addMinutes(30));
        $this->get('/admin/activate?token='.$token)->assertOk();
        $this->post('/admin/activate', ['token' => $token, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertRedirect('/admin/login')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password-123', $admin->fresh()->password));
        $this->post('/admin/activate', ['token' => $token, 'password' => 'other-password-123', 'password_confirmation' => 'other-password-123'])->assertStatus(410);
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'new-password-123'])->assertRedirect('/admin/panel');
        $this->get('/admin/panel')->assertOk();
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
}
