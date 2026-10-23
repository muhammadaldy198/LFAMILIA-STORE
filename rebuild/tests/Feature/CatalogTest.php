<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\HomeBanner;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Models\StoreAsset;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzCatalogImport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(string $role = 'SUPER_ADMIN'): AdminUser
    {
        return $this->createAdmin([
            'name' => 'Catalog Admin',
            'email' => strtolower($role).'@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_index_hides_products_without_buyable_packages(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $suffix = bin2hex(random_bytes(3));

        $buyable = Product::create([
            'category_id' => $category->id,
            'name' => 'Buyable Catalog '.$suffix,
            'slug' => 'buyable-catalog-'.$suffix,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $buyablePackage = $buyable->packages()->create([
            'code' => 'BUYABLE'.$suffix, 'name' => 'Buyable Package', 'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $buyablePackage->id,
            'provider_id' => $providerId,
            'external_sku' => 'BUYABLE-SKU-'.$suffix,
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($buyablePackage->id);

        $deadPrice = Product::create([
            'category_id' => $category->id,
            'name' => 'Dead Price Catalog '.$suffix,
            'slug' => 'dead-price-catalog-'.$suffix,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $deadPricePackage = $deadPrice->packages()->create([
            'code' => 'DEADPRICE'.$suffix, 'name' => 'Dead Price Package', 'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $deadPricePackage->id,
            'provider_id' => $providerId,
            'external_sku' => 'DEADPRICE-SKU-'.$suffix,
            'cost_idr' => 13000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deadNone = Product::create([
            'category_id' => $category->id,
            'name' => 'Dead None Catalog '.$suffix,
            'slug' => 'dead-none-catalog-'.$suffix,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $deadNone->packages()->create([
            'code' => 'DEADNONE'.$suffix, 'name' => 'Dead None Package', 'is_active' => true,
        ]);

        $this->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Index')
                ->where('products.data', function ($items) use ($buyable, $deadPrice, $deadNone): bool {
                    $slugs = collect($items)->pluck('slug')->all();

                    return in_array($buyable->slug, $slugs, true)
                        && ! in_array($deadPrice->slug, $slugs, true)
                        && ! in_array($deadNone->slug, $slugs, true);
                })
                ->etc());
    }

    public function test_search_matches_category_description_and_package_name_and_hides_unbuyable(): void
    {
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $suffix = bin2hex(random_bytes(3));

        $category = Category::create([
            'name' => 'Kategori Cari '.$suffix,
            'slug' => 'kategori-cari-'.$suffix,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Cari '.$suffix,
            'slug' => 'produk-cari-'.$suffix,
            'description' => 'Deskripsi unik cari '.$suffix,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'CARI'.$suffix, 'name' => 'Nominal Cari '.$suffix, 'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'CARI-SKU-'.$suffix,
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);

        $dead = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Mati '.$suffix,
            'slug' => 'produk-mati-'.$suffix,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $dead->packages()->create([
            'code' => 'MATI'.$suffix, 'name' => 'Nominal Mati', 'is_active' => true,
        ]);

        // Kategori.
        $this->getJson('/catalog/search?q=Kategori Cari '.$suffix)
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.slug', $product->slug);
        // Deskripsi.
        $this->getJson('/catalog/search?q=Deskripsi unik cari '.$suffix)
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.slug', $product->slug);
        // Nama paket.
        $this->getJson('/catalog/search?q=Nominal Cari '.$suffix)
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.slug', $product->slug);
        // Produk mati tidak muncul meski namanya cocok.
        $this->getJson('/catalog/search?q=Produk Mati '.$suffix)
            ->assertOk()
            ->assertJsonCount(0, 'products');
        // Filter q di halaman katalog ikut perluasan yang sama.
        $this->get('/?q=Nominal Cari '.$suffix)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data', fn ($items) => collect($items)->contains('slug', $product->slug))
                ->etc());
    }

    public function test_search_package_name_matches_only_buyable_packages(): void
    {
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $suffix = bin2hex(random_bytes(3));

        $category = Category::create([
            'name' => 'Kategori Campur '.$suffix,
            'slug' => 'kategori-campur-'.$suffix,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Campur '.$suffix,
            'slug' => 'produk-campur-'.$suffix,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        // Paket TERBELI bernama "Terlihat".
        $visible = $product->packages()->create([
            'code' => 'TERLIHAT'.$suffix, 'name' => 'Paket Terlihat '.$suffix, 'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $visible->id,
            'provider_id' => $providerId,
            'external_sku' => 'TERLIHAT-SKU-'.$suffix,
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($visible->id);
        // Paket AKTIF tapi TAK TERBELI (tanpa mapping) bernama
        // "Tersembunyi" — nominalnya sengaja tidak tampil di halaman
        // produk (D11). Sebelum fix, cari "Tersembunyi" tetap
        // mengembalikan produk ini: searchScope mencocokkan nama
        // paket tak terbeli, sementara syarat buyableProductIds
        // dipenuhi lewat paket Terlihat.
        $product->packages()->create([
            'code' => 'TERSEMBUNYI'.$suffix, 'name' => 'Paket Tersembunyi '.$suffix, 'is_active' => true,
        ]);

        // Jalur instant search.
        $this->getJson('/catalog/search?q=Tersembunyi '.$suffix)
            ->assertOk()
            ->assertJsonCount(0, 'products');
        $this->getJson('/catalog/search?q=Terlihat '.$suffix)
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.slug', $product->slug);
        // Jalur listing (filter q halaman katalog).
        $this->get('/?q=Tersembunyi '.$suffix)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data', fn ($items) => ! collect($items)->contains('slug', $product->slug))
                ->etc());
        $this->get('/?q=Terlihat '.$suffix)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data', fn ($items) => collect($items)->contains('slug', $product->slug))
                ->etc());
    }

    public function test_default_categories_are_extensible_and_exclude_ewallet(): void
    {
        $this->assertSame([
            'game', 'pulsa', 'paket-data', 'pln', 'voucher', 'entertainment', 'ppob-lainnya',
        ], Category::orderBy('sort_order')->pluck('slug')->all());
        $this->assertFalse(Category::where('slug', 'e-wallet')->exists());
    }

    public function test_home_category_filter_supports_partial_inertia_updates_without_reloading_home_sections(): void
    {
        $this->get('/?category=game')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Index')
                ->where('filters.category', 'game')
                ->has('categories')
                ->has('products.data')
                ->etc());

        // A real Inertia visit includes the asset version from the initial HTML page.
        // Omitting it causes the Inertia middleware to return a 409 asset-version redirect.
        $page = $this->get('/')->viewData('page');
        $version = (string) ($page['version'] ?? '');

        // The homepage tabs ask Inertia for only the filtered product list and filters.
        // Banners, navigation categories and news should remain mounted in the browser.
        $this->withHeaders([
            'X-Inertia-Version' => $version,
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Catalog/Index',
            'X-Inertia-Partial-Data' => 'products,filters',
        ])->get('/?category=game')->assertOk()
            ->assertJsonPath('component', 'Catalog/Index')
            ->assertJsonPath('props.filters.category', 'game')
            ->assertJsonStructure(['props' => ['products' => ['data']]])
            ->assertJsonMissingPath('props.categories')
            ->assertJsonMissingPath('props.banners')
            ->assertJsonMissingPath('props.news');
    }

    public function test_instant_search_returns_only_active_orderable_products(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $active = Product::create([
            'category_id' => $category->id,
            'name' => 'LiveSearch Regression Game',
            'slug' => 'livesearch-regression-game',
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);
        $activePackage = $active->packages()->create([
            'code' => 'SEARCHLIVE1', 'name' => 'Search Package', 'is_active' => true,
        ]);
        // D11: "orderable" berarti punya paket yang benar-benar bisa dibeli.
        DB::table('provider_mappings')->insert([
            'product_package_id' => $activePackage->id,
            'provider_id' => $providerId,
            'external_sku' => 'SEARCHLIVE-SKU-'.bin2hex(random_bytes(3)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($activePackage->id);
        $hidden = Product::create([
            'category_id' => $category->id,
            'name' => 'LiveSearch Hidden Game',
            'slug' => 'livesearch-hidden-game',
            'margin_percent' => 10,
            'is_active' => false,
        ]);
        $hidden->packages()->create([
            'code' => 'SEARCHHIDDEN1', 'name' => 'Hidden Package', 'is_active' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'LiveSearch No Package',
            'slug' => 'livesearch-no-package',
            'margin_percent' => 10,
            'is_active' => true,
        ]);

        $this->getJson('/catalog/search?q=LiveSearch')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.slug', $active->slug)
            ->assertJsonPath('products.0.category_name', $category->name)
            ->assertJsonMissingPath('products.0.margin_percent');

        $this->getJson('/catalog/search?q='.str_repeat('x', 81))
            ->assertUnprocessable();
        $this->getJson('/catalog/search?q=')->assertUnprocessable();
    }

    public function test_only_super_admin_can_manage_a_manual_product_and_customer_sees_no_provider_data(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        // D11: keterbelian memakai CheckoutPricing yang sama dengan checkout —
        // provider MANUAL harus aktif agar produk manual bisa dibeli (dan tampil).
        DB::table('providers')->where('code', 'MANUAL')->update(['is_active' => true]);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        $this->post('/admin/catalog/products', [
            'category_id' => $category->id,
            'name' => 'Manual Gift',
            'description' => 'Gift game',
            'fulfillment_mode' => 'MANUAL',
            'manual_instructions' => 'Check order in panel',
            'margin_percent' => 10,
            'sort_order' => 1,
        ])->assertRedirect();

        $product = Product::where('slug', 'manual-gift')->firstOrFail();
        $this->assertFalse($product->is_active);
        $this->post('/admin/catalog/products/'.$product->id.'/packages', [
            'code' => 'GIFT10', 'name' => '10 Gift', 'nominal_value' => 10,
            'sort_order' => 0, 'cost_idr' => 8000,
        ])->assertRedirect();
        $package = ProductPackage::where('product_id', $product->id)->firstOrFail();
        $mapping = ProviderMapping::where('product_package_id', $package->id)->firstOrFail();
        $this->assertNull($mapping->external_sku);
        $this->assertSame('MANUAL', DB::table('providers')->where('id', $mapping->provider_id)->value('code'));

        $this->get('/catalog/manual-gift')->assertNotFound();
        $this->put('/admin/catalog/products/'.$product->id, [
            'category_id' => $category->id, 'name' => 'Manual Gift', 'description' => 'Gift game',
            'manual_instructions' => 'Check order in panel', 'margin_percent' => 10,
            'sort_order' => 1, 'is_active' => true,
        ])->assertRedirect();
        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'GIFT10', 'name' => '10 Gift', 'nominal_value' => 10,
            'sort_order' => 0, 'is_active' => true,
        ])->assertRedirect();
        // Mapping MANUAL dibuat nonaktif oleh endpoint tambah nominal —
        // admin mengaktifkannya lewat endpoint mapping (alur admin asli).
        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 0, 'is_active' => true, 'cost_idr' => 8000,
        ])->assertRedirect();

        $this->get('/?mode=manual')->assertOk()->assertSee('Manual Gift');
        $this->get('/catalog/manual-gift')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Show')
                ->where('product.name', 'Manual Gift')
                ->missing('product.manual_instructions')
                ->missing('product.fulfillment_mode')
                ->where('packages.0.name', '10 Gift')
                ->missing('packages.0.cost_idr')
                ->missing('packages.0.mappings')
                ->etc());
        $this->assertGreaterThan(0, DB::table('audit_logs')
            ->where('actor_id', (string) $admin->id)->count());

        $this->actingAs($this->admin('ADMIN'), 'admin');
        $this->get('/admin/catalog')->assertForbidden();
        $this->post('/admin/catalog/products', [
            'category_id' => $category->id, 'name' => 'Forbidden',
        ])->assertForbidden();
    }

    public function test_nominals_sort_by_nominal_value_and_fields_are_configurable(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Diamond Test', 'slug' => 'diamond-test',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER', 'is_active' => true,
        ]);
        // D11: nominal tanpa mapping yang bisa dibeli tidak tampil — beri
        // mapping agar kedua nominal tetap tampil dan urutannya bisa dites.
        foreach ([['D100', '100 Diamonds', 100], ['D5', '5 Diamonds', 5]] as [$code, $name, $nominal]) {
            $package = $product->packages()->create([
                'code' => $code, 'name' => $name, 'nominal_value' => $nominal, 'is_active' => true,
            ]);
            DB::table('provider_mappings')->insert([
                'product_package_id' => $package->id,
                'provider_id' => $providerId,
                'external_sku' => 'DIAMOND-SKU-'.$code.'-'.bin2hex(random_bytes(3)),
                'cost_idr' => 10000,
                'max_price_idr' => 12000,
                'priority' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->seedCheckoutDigiflazzCatalog($package->id);
        }
        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'customer_no_template' => '{{user_id}}{{zone_id}}',
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => false],
            ],
        ])->assertRedirect();
        $this->get('/catalog/diamond-test')->assertOk()
            ->assertSeeInOrder(['5 Diamonds', '100 Diamonds'])->assertSee('User ID');
        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'user_id', 'label' => 'Again', 'type' => 'text', 'is_required' => true],
            ],
        ])->assertSessionHasErrors('fields.1.field_key');
    }

    public function test_product_template_is_saved_and_legacy_mapping_fields_cannot_be_removed(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $category = Category::where('slug', 'game')->firstOrFail();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Shared Template Game', 'slug' => 'shared-template-game',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER', 'is_active' => false,
        ]);
        $product->fields()->create(['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $product->fields()->create(['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 1]);
        $package = $product->packages()->create(['code' => 'SHARED1', 'name' => 'Paket', 'is_active' => false]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id, 'provider_id' => $providerId, 'external_sku' => 'SHARED-SKU',
            'cost_idr' => 10000, 'max_price_idr' => 12000, 'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'priority' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = [
            'category_id' => $category->id, 'name' => $product->name, 'slug' => $product->slug,
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10, 'sort_order' => 0, 'is_active' => false,
            'customer_no_template' => '{{user_id}}{{zone_id}}',
        ];
        $legacyOnlyPayload = $payload;
        unset($legacyOnlyPayload['customer_no_template']);
        $this->put('/admin/catalog/products/'.$product->id, $legacyOnlyPayload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->customer_no_template);
        $legacyConfig = json_decode((string) DB::table('provider_mappings')
            ->where('product_package_id', $package->id)->value('fulfillment_config'), true);
        $this->assertSame('{{user_id}}{{zone_id}}', $legacyConfig['customer_no_template']);

        $this->put('/admin/catalog/products/'.$product->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('{{user_id}}{{zone_id}}', $product->fresh()->customer_no_template);
        $this->put('/admin/catalog/products/'.$product->id, [...$payload, 'customer_no_template' => null])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->customer_no_template);
        $legacyConfig = json_decode((string) DB::table('provider_mappings')
            ->where('product_package_id', $package->id)->value('fulfillment_config'), true);
        $this->assertArrayNotHasKey('customer_no_template', $legacyConfig);

        DB::table('provider_mappings')->where('product_package_id', $package->id)->update([
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
        ]);
        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => true],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $preservedConfig = json_decode((string) DB::table('provider_mappings')
            ->where('product_package_id', $package->id)->value('fulfillment_config'), true);
        $this->assertSame('{{user_id}}{{zone_id}}', $preservedConfig['customer_no_template']);

        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'customer_no_template' => null,
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => true],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $clearedConfig = json_decode((string) DB::table('provider_mappings')
            ->where('product_package_id', $package->id)->value('fulfillment_config'), true);
        $this->assertArrayNotHasKey('customer_no_template', $clearedConfig);

        $product->update(['is_active' => true]);
        $payload['is_active'] = true;
        $payload['customer_no_template'] = '{{user_id}}';
        $this->put('/admin/catalog/products/'.$product->id, $payload)
            ->assertSessionHasErrors('customer_no_template');

        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'customer_no_template' => '{{user_id}}{{zone_id}}{{region_id}}',
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'region_id', 'label' => 'Region ID', 'type' => 'text', 'is_required' => true],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('{{user_id}}{{zone_id}}{{region_id}}', $product->fresh()->customer_no_template);
        $auditAfter = json_decode((string) DB::table('audit_logs')->where('action', 'catalog.fields.updated')
            ->where('target_id', (string) $product->id)->latest('id')->value('after'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('{{user_id}}{{zone_id}}{{region_id}}', $auditAfter['customer_no_template']);
        $this->assertCount(3, $auditAfter['fields']);

        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'customer_no_template' => '{{user_id}}',
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'region_id', 'label' => 'Region ID', 'type' => 'text', 'is_required' => true],
            ],
        ])->assertSessionHasErrors('customer_no_template');
        $this->assertSame('{{user_id}}{{zone_id}}{{region_id}}', $product->fresh()->customer_no_template);
    }

    public function test_active_digiflazz_shared_template_rejects_missing_required_fields_and_literals(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $category = Category::where('slug', 'game')->firstOrFail();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Template Validation Game', 'slug' => 'template-validation-game',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER', 'is_active' => true,
            'customer_no_template' => '{{user_id}}',
        ]);
        $product->fields()->createMany([
            ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 0],
            ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => false, 'sort_order' => 1],
        ]);
        $package = $product->packages()->create(['code' => 'VALIDATE1', 'name' => 'Paket']);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id, 'provider_id' => $providerId, 'external_sku' => 'VALIDATE-SKU',
            'cost_idr' => 10000, 'max_price_idr' => 12000, 'fulfillment_config' => json_encode([]),
            'priority' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['fixed-account-id', '{{zone_id}}'] as $invalidTemplate) {
            $this->put('/admin/catalog/products/'.$product->id.'/fields', [
                'customer_no_template' => $invalidTemplate,
                'fields' => [
                    ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                    ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => false],
                ],
            ])->assertSessionHasErrors('customer_no_template');
        }
        $this->put('/admin/catalog/products/'.$product->id.'/fields', [
            'customer_no_template' => '{{zone_id}}',
            'fields' => [
                ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true],
                ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => false],
            ],
        ])->assertSessionHasErrors('customer_no_template');
        $this->assertSame('{{user_id}}', $product->fresh()->customer_no_template);
    }

    public function test_digiflazz_mapping_cannot_be_activated_without_valid_shared_template(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $category = Category::where('slug', 'game')->firstOrFail();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Mapping Activation Game', 'slug' => 'mapping-activation-game',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER', 'is_active' => true,
        ]);
        $product->fields()->createMany([
            ['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 0],
            ['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 1],
        ]);
        $package = $product->packages()->create(['code' => 'ACTIVATE1', 'name' => 'Paket']);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id, 'provider_id' => $providerId, 'external_sku' => 'ACTIVATE-SKU',
            'cost_idr' => 10000, 'max_price_idr' => 12000, 'fulfillment_config' => json_encode([]),
            'priority' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->put('/admin/catalog/mappings/'.$mappingId, [
            'priority' => 1, 'is_active' => true, 'is_manual_price' => false,
        ])->assertSessionHasErrors('is_active');
        $this->assertDatabaseHas('provider_mappings', ['id' => $mappingId, 'is_active' => false]);
        $product->update(['customer_no_template' => '{{user_id}}{{zone_id}}']);
        $this->put('/admin/catalog/mappings/'.$mappingId, [
            'priority' => 1, 'is_active' => true, 'is_manual_price' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('provider_mappings', ['id' => $mappingId, 'is_active' => true]);
    }

    public function test_shared_template_migration_rollback_restores_legacy_mapping_templates(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Rollback Template Game', 'slug' => 'rollback-template-game',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER', 'customer_no_template' => '{{user_id}}{{zone_id}}',
        ]);
        $package = $product->packages()->create(['code' => 'ROLLBACK1', 'name' => 'Paket']);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id, 'provider_id' => $providerId, 'external_sku' => 'ROLLBACK-SKU',
            'cost_idr' => 10000, 'max_price_idr' => 12000, 'fulfillment_config' => json_encode(['customer_no_template' => '{{stale}}']),
            'priority' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product->update(['customer_no_template' => '{{latest_user}}{{latest_zone}}']);
        (require database_path('migrations/2026_10_18_134621_backfill_product_customer_no_templates.php'))->down();
        $config = json_decode(DB::table('provider_mappings')->where('id', $mappingId)->value('fulfillment_config'), true);
        $this->assertSame('{{latest_user}}{{latest_zone}}', $config['customer_no_template']);
    }

    public function test_digiflazz_sku_only_enters_through_trusted_import_service(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Auto Game', 'slug' => 'auto-game',
            'margin_percent' => 10,
        ]);
        $package = $product->packages()->create(['code' => 'AUTO5', 'name' => '5 Unit']);
        $mapping = app(DigiflazzCatalogImport::class)->upsert($package, 'PROVIDER-SKU-5', 5000, 6000);
        $this->assertSame('PROVIDER-SKU-5', $mapping->external_sku);
        $this->assertFalse($mapping->is_active);

        $this->actingAs($this->admin(), 'admin');
        $this->post('/admin/catalog/products/'.$product->id.'/packages', [
            'code' => 'AUTO10', 'name' => '10 Unit', 'sort_order' => 1,
            'external_sku' => 'FORGED-SKU',
        ])->assertRedirect();
        $productMappingQuery = ProviderMapping::whereIn(
            'product_package_id',
            $product->packages()->pluck('id')
        );
        $this->assertSame(1, (clone $productMappingQuery)->count());
        $this->assertSame('PROVIDER-SKU-5', (clone $productMappingQuery)->firstOrFail()->external_sku);
        $this->assertFalse(ProviderMapping::where('external_sku', 'FORGED-SKU')->exists());

        $other = $product->packages()->create(['code' => 'AUTO20', 'name' => '20 Unit']);
        $this->expectException(InvalidArgumentException::class);
        app(DigiflazzCatalogImport::class)->upsert($other, 'PROVIDER-SKU-5', 5000);
    }

    public function test_brand_media_is_connected_to_customer_catalog(): void
    {
        Storage::fake('public');
        $assets = [];

        foreach (['logo', 'favicon', 'banner_desktop', 'banner_mobile'] as $key) {
            $asset = StoreAsset::where('key', $key)->firstOrFail();
            $asset->update(['is_active' => true]);
            $asset->addMedia(UploadedFile::fake()->image($key.'.png'))->toMediaCollection('image', 'public');
            $assets[$key] = $asset->getFirstMediaUrl('image');
        }

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Index')
            ->where('logoUrl', $assets['logo'])
            ->where('banners.0.desktop_url', $assets['banner_desktop'])
            ->where('banners.0.mobile_url', $assets['banner_mobile'])
            ->where('popups.0.title', 'Selamat datang di LFAMILIA STORE')
            ->where('faviconUrl', $assets['favicon'])
            ->etc());
    }

    public function test_admin_managed_banner_media_and_popup_are_connected_to_homepage(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'admin');

        $banner = HomeBanner::create([
            'title' => 'Promo Managed',
            'subtitle' => 'Banner test',
            'cta_href' => '/promo',
            'show_desktop' => true,
            'show_mobile' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->post('/admin/catalog/media/banner/'.$banner->id, [
            'collection' => 'desktop',
            'image' => UploadedFile::fake()->image('desktop.png', 1200, 400),
        ])->assertRedirect();
        $this->post('/admin/catalog/media/banner/'.$banner->id, [
            'collection' => 'mobile',
            'image' => UploadedFile::fake()->image('mobile.png', 900, 500),
        ])->assertRedirect();

        $this->post('/admin/content/popups', [
            'title' => 'Popup Managed',
            'body' => 'Isi popup yang bisa diedit Admin.',
            'dismiss_days' => 3,
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/admin/content/popups', [
            'title' => 'Popup Managed Updated',
            'body' => 'Tetap satu record popup.',
            'dismiss_days' => 5,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame(1, DB::table('site_popups')->count());

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Index')
            ->where('banners.0.title', 'Promo Managed')
            ->where('banners.0.desktop_url', $banner->fresh()->getFirstMediaUrl('desktop'))
            ->where('banners.0.mobile_url', $banner->fresh()->getFirstMediaUrl('mobile'))
            ->where('popups.0.title', 'Popup Managed Updated')
            ->where('popups.0.body', 'Tetap satu record popup.')
            ->where('popups.0.dismiss_days', 5)
            ->missing('popups.1')
            ->etc());
    }

    public function test_super_admin_can_upload_and_replace_media(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'admin');
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->firstOrFail()->id,
            'name' => 'Image Product', 'slug' => 'image-product', 'margin_percent' => 10,
        ]);

        $this->post('/admin/catalog/media/product/'.$product->id, [
            'collection' => 'image', 'image' => UploadedFile::fake()->image('first.png'),
        ])->assertRedirect();
        $this->assertCount(1, $product->getMedia('image'));

        $this->post('/admin/catalog/media/product/'.$product->id, [
            'collection' => 'image', 'image' => UploadedFile::fake()->image('second.png'),
        ])->assertRedirect();
        $this->assertCount(1, $product->fresh()->getMedia('image'));
        $this->assertSame('second.png', $product->fresh()->getFirstMedia('image')->file_name);
    }

    public function test_catalog_only_admin_can_upload_category_image_but_not_banner_image(): void
    {
        Storage::fake('public');
        $this->actingAs($this->createAdmin([
            'name' => 'Katalog Saja',
            'email' => 'katalog-saja@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => 'ADMIN',
            'permissions' => ['catalog.manage'],
            'is_active' => true,
        ]), 'admin');

        $category = Category::where('slug', 'game')->firstOrFail();
        $this->post('/admin/catalog/media/category/'.$category->id, [
            'collection' => 'image',
            'image' => UploadedFile::fake()->image('kategori.png', 512, 512),
        ])->assertRedirect();
        $this->assertNotNull($category->fresh()->getFirstMedia('image'));

        $banner = HomeBanner::create([
            'title' => 'Promo Katalog', 'subtitle' => 'Banner', 'cta_href' => '/promo',
            'show_desktop' => true, 'show_mobile' => true, 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->post('/admin/catalog/media/banner/'.$banner->id, [
            'collection' => 'desktop',
            'image' => UploadedFile::fake()->image('banner.png', 1200, 400),
        ])->assertForbidden();
    }

    public function test_uploaded_favicon_is_activated_and_shared_immediately(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'admin');

        $favicon = StoreAsset::where('key', 'favicon')->firstOrFail();
        $favicon->update(['is_active' => false]);

        $this->post('/admin/catalog/media/asset/'.$favicon->id, [
            'collection' => 'image',
            'image' => UploadedFile::fake()->image('favicon-new.png', 512, 512),
        ])->assertRedirect();

        $favicon = $favicon->fresh();
        $this->assertTrue($favicon->is_active);
        $this->assertSame('favicon-new.png', $favicon->getFirstMedia('image')->file_name);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.assets.favicon.url', $favicon->getFirstMediaUrl('image'))
            ->etc());
    }

    public function test_unavailable_nominal_cannot_be_preselected_from_deep_link(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Unavailable Deep Link',
            'slug' => 'unavailable-deep-link-'.bin2hex(random_bytes(3)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);

        $package = $product->packages()->create([
            'code' => 'LOCKED100',
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);

        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'LOCKED-SKU-'.bin2hex(random_bytes(3)),
            'cost_idr' => 10000,
            'max_price_idr' => 9000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // D11: nominal yang tidak bisa dibeli tidak tampil sama sekali
        // (bukan lagi label "Tidak tersedia"), jadi deep link tidak bisa
        // memilihnya — dan daftar paketnya kosong.
        $this->get('/catalog/'.$product->slug.'?package='.$package->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Show')
                ->where('initialPackageId', '')
                ->has('packages', 0)
                ->etc());
    }

    public function test_admin_creates_a_manual_product_with_many_nominal_rows_in_one_form(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'MANUAL')->update(['is_active' => true]);
        $this->actingAs($this->admin(), 'admin');

        $this->post('/admin/catalog/products', [
            'category_id' => $category->id,
            'name' => 'Manual Bundle',
            'fulfillment_mode' => 'MANUAL',
            'manual_instructions' => 'Cek pesanan di panel admin',
            'margin_percent' => 10,
            'sell_markup_percent' => 15,
            'sort_order' => 3,
            'packages' => [
                ['name' => '100 Koin', 'nominal_value' => 100, 'cost_idr' => 10000, 'sell_markup_percent' => 12],
                ['name' => '200 Koin', 'nominal_value' => 200, 'cost_idr' => 19000],
                ['name' => 'Kartu Koleksi', 'nominal_value' => null, 'cost_idr' => 5000, 'note' => 'Bonus'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product = Product::where('slug', 'manual-bundle')->firstOrFail();
        $this->assertFalse($product->is_active);
        $packages = ProductPackage::where('product_id', $product->id)->orderBy('sort_order')->get();
        $this->assertCount(3, $packages);
        $this->assertSame(['100 Koin', '200 Koin', 'Kartu Koleksi'], $packages->pluck('name')->all());
        $this->assertSame(3, $packages->pluck('code')->unique()->count(), 'Kode nominal dibuat otomatis dan unik.');
        $this->assertSame(['100', '200', null], $packages->map(fn (ProductPackage $pack) => $pack->nominal_value === null ? null : (string) $pack->nominal_value)->all());
        foreach ($packages as $package) {
            $this->assertFalse((bool) $package->is_active, 'Nominal manual baru menunggu aktivasi admin.');
        }

        // Satu formulir membuat baris nominal manual; tidak ada SKU provider asli
        // yang tertempel — pencatatan modal tetap lewat provider MANUAL.
        $mappings = ProviderMapping::whereIn('product_package_id', $packages->pluck('id'))->get();
        $this->assertCount(3, $mappings);
        foreach ($mappings as $mapping) {
            $this->assertNull($mapping->external_sku);
            $this->assertSame('MANUAL', DB::table('providers')->where('id', $mapping->provider_id)->value('code'));
            $this->assertFalse((bool) $mapping->is_active);
            $this->assertNull($mapping->seller_reference_price_idr);
        }

        $first = $packages->firstWhere('name', '100 Koin');
        $firstMapping = $mappings->firstWhere('product_package_id', $first->id);
        // Modal toko yang diketik admin tidak di-uplift: uplift hanya berlaku saat impor.
        $this->assertSame(10000, (int) $firstMapping->cost_idr);
        $this->assertSame(10000, (int) $firstMapping->store_cost_idr);
        $this->assertSame('12.0000', (string) $first->sell_markup_percent);

        DB::table('products')->where('id', $product->id)->update(['is_active' => true]);
        DB::table('product_packages')->whereIn('id', $packages->pluck('id'))->update(['is_active' => true]);
        DB::table('provider_mappings')->whereIn('product_package_id', $packages->pluck('id'))->update(['is_active' => true]);
        $quotes = app(CheckoutPricing::class)->forPackages($packages->pluck('id')->all());

        // Baris dengan markup sendiri memakai markup baris (10.000 × 12% = 11.200).
        $this->assertSame(11200, $quotes[$first->id]['subtotal_idr']);
        // Baris tanpa markup memakai markup produk dari formulir yang sama (19.000 × 15% = 21.850).
        $second = $packages->firstWhere('name', '200 Koin');
        $this->assertSame(21850, $quotes[$second->id]['subtotal_idr']);
        // Tanpa markup baris, berlaku markup produk dari formulir yang sama (5.000 × 15% = 5.750).
        $third = $packages->firstWhere('name', 'Kartu Koleksi');
        $this->assertSame(5750, $quotes[$third->id]['subtotal_idr']);
    }

    public function test_manual_nominal_rows_are_rejected_for_automatic_products(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $this->actingAs($this->admin(), 'admin');

        $this->post('/admin/catalog/products', [
            'category_id' => $category->id,
            'name' => 'Otomatis Dengan Baris',
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'sort_order' => 4,
            'packages' => [
                ['name' => '100 Koin', 'nominal_value' => 100, 'cost_idr' => 10000],
            ],
        ])->assertSessionHasErrors('packages');

        $this->assertDatabaseMissing('products', ['name' => 'Otomatis Dengan Baris']);
        $this->assertSame(0, ProductPackage::whereIn('name', ['100 Koin'])->whereHas('product', fn ($query) => $query->where('name', 'Otomatis Dengan Baris'))->count());
    }

    public function test_manual_nominal_rows_reject_duplicates_and_invalid_rows_without_partial_creation(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $this->actingAs($this->admin(), 'admin');
        $base = [
            'category_id' => $category->id,
            'fulfillment_mode' => 'MANUAL',
            'margin_percent' => 10,
            'sort_order' => 5,
        ];

        // Nominal kembar dalam satu formulir tidak boleh dibuat diam-diam.
        $this->post('/admin/catalog/products', $base + [
            'name' => 'Manual Kembar',
            'packages' => [
                ['name' => '100 Koin', 'nominal_value' => 100, 'cost_idr' => 10000],
                ['name' => '100 Koin Varian', 'nominal_value' => 100, 'cost_idr' => 11000],
            ],
        ])->assertSessionHasErrors('packages');
        $this->assertDatabaseMissing('products', ['name' => 'Manual Kembar']);

        // Baris cacat ditolak seluruhnya — tidak ada produk/nominal yang setengah jadi.
        $this->post('/admin/catalog/products', $base + [
            'name' => 'Manual Cacat',
            'packages' => [
                ['name' => '', 'nominal_value' => 50, 'cost_idr' => -1],
                ['name' => '250 Koin', 'nominal_value' => 250, 'cost_idr' => 25000],
            ],
        ])->assertSessionHasErrors(['packages.0.name', 'packages.0.cost_idr']);
        $this->assertDatabaseMissing('products', ['name' => 'Manual Cacat']);
        $this->assertSame(0, ProductPackage::where('name', '250 Koin')->count());
    }

    public function test_new_manual_nominal_keeps_activation_and_sell_price_from_the_form(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Manual Harga', 'slug' => 'manual-harga',
            'margin_percent' => 10, 'fulfillment_mode' => 'MANUAL',
        ]);
        $this->actingAs($this->admin(), 'admin');

        // Harga jual di bawah modal ditolak, bukan disimpan diam-diam.
        $this->post('/admin/catalog/products/'.$product->id.'/packages', [
            'code' => 'MH-100', 'name' => '100 Koin', 'sort_order' => 0, 'cost_idr' => 20000,
            'is_active' => true, 'pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 15000,
        ])->assertSessionHasErrors('sell_price_idr');
        $this->assertDatabaseMissing('product_packages', ['code' => 'MH-100']);

        $this->post('/admin/catalog/products/'.$product->id.'/packages', [
            'code' => 'MH-100', 'name' => '100 Koin', 'sort_order' => 0, 'cost_idr' => 20000,
            'is_active' => true, 'pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 25000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $package = ProductPackage::where('code', 'MH-100')->firstOrFail();
        $this->assertTrue($package->is_active);
        $this->assertSame('SELL_PRICE', $package->pricing_mode);
        $this->assertSame(25000, (int) $package->sell_price_idr);
        $mapping = $package->mappings()->firstOrFail();
        $this->assertSame(20000, (int) $mapping->cost_idr);
        $this->assertTrue($mapping->is_active);
    }

    public function test_editing_a_manual_nominal_persists_the_stored_cost(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Manual Modal', 'slug' => 'manual-modal',
            'margin_percent' => 10, 'fulfillment_mode' => 'MANUAL',
        ]);
        $package = $product->packages()->create(['code' => 'MM-100', 'name' => '100 Koin', 'is_active' => true]);
        $provider = Provider::where('code', 'MANUAL')->firstOrFail();
        $mapping = ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $provider->id,
            'external_sku' => null, 'cost_idr' => 10000, 'store_cost_idr' => 10000,
            'priority' => 0, 'is_active' => true,
        ]);
        $this->actingAs($this->admin(), 'admin');

        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'MM-100', 'name' => '100 Koin', 'sort_order' => 0,
            'is_active' => true, 'pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 15000, 'cost_idr' => 12000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(12000, (int) $mapping->fresh()->cost_idr);
        $this->assertSame(12000, (int) $mapping->fresh()->store_cost_idr);
        $this->assertSame(15000, (int) $package->fresh()->sell_price_idr);

        // Modal tidak berlaku untuk produk otomatis.
        $auto = Product::create([
            'category_id' => $category->id, 'name' => 'Otomatis Modal', 'slug' => 'otomatis-modal',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER',
        ]);
        $autoPackage = $auto->packages()->create(['code' => 'AM-100', 'name' => '100 Koin']);
        $this->put('/admin/catalog/packages/'.$autoPackage->id, [
            'code' => 'AM-100', 'name' => '100 Koin', 'sort_order' => 0, 'is_active' => false, 'cost_idr' => 12000,
        ])->assertSessionHasErrors('cost_idr');
    }

    public function test_activating_a_manual_nominal_also_activates_its_provider_mapping(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Manual Aktif', 'slug' => 'manual-aktif',
            'margin_percent' => 10, 'fulfillment_mode' => 'MANUAL',
        ]);
        $package = $product->packages()->create(['code' => 'MA-100', 'name' => '100 Koin', 'is_active' => false]);
        $provider = Provider::where('code', 'MANUAL')->firstOrFail();
        $mapping = ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $provider->id,
            'external_sku' => null, 'cost_idr' => 9000, 'store_cost_idr' => 9000,
            'priority' => 0, 'is_active' => false,
        ]);
        $this->actingAs($this->admin(), 'admin');

        // Nominal tanpa modal tidak boleh diaktifkan: CheckoutPricing tidak akan melihatnya.
        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'MA-100', 'name' => '100 Koin', 'sort_order' => 0, 'is_active' => true, 'cost_idr' => 0,
        ])->assertSessionHasErrors('cost_idr');
        $this->assertFalse($package->fresh()->is_active);
        $this->assertFalse($mapping->fresh()->is_active);

        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'MA-100', 'name' => '100 Koin', 'sort_order' => 0, 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($package->fresh()->is_active);
        $this->assertTrue($mapping->fresh()->is_active, 'Pemetaan manual harus ikut aktif agar nominal bisa dibeli.');

        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'MA-100', 'name' => '100 Koin', 'sort_order' => 0, 'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($mapping->fresh()->is_active, 'Menonaktifkan nominal harus ikut menonaktifkan pemetaan.');
    }

    public function test_editing_a_nominal_identity_keeps_stored_pricing_and_nominal_value(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Harga Tersimpan', 'slug' => 'harga-tersimpan',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER',
        ]);
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $rows = [
            'PRODUCT_MARGIN' => ['mode' => 'PRODUCT_MARGIN', 'margin_percent' => null, 'margin_fixed_idr' => null, 'sell_price_idr' => null, 'sell_markup_percent' => null],
            'PERCENT' => ['mode' => 'PERCENT', 'margin_percent' => 12.5, 'margin_fixed_idr' => null, 'sell_price_idr' => null, 'sell_markup_percent' => null],
            'FIXED' => ['mode' => 'FIXED', 'margin_percent' => null, 'margin_fixed_idr' => 3500, 'sell_price_idr' => null, 'sell_markup_percent' => null],
            'SELL_PRICE' => ['mode' => 'SELL_PRICE', 'margin_percent' => null, 'margin_fixed_idr' => null, 'sell_price_idr' => 21000, 'sell_markup_percent' => 18],
        ];
        $packages = [];
        foreach ($rows as $mode => $pricing) {
            $package = $product->packages()->create([
                'code' => 'HT-'.$mode, 'name' => 'Nominal '.$mode, 'nominal_value' => 50,
                'note' => 'Catatan toko', 'sort_order' => 1, 'is_active' => true,
                'pricing_mode' => $pricing['mode'],
                'margin_percent' => $pricing['margin_percent'],
                'margin_fixed_idr' => $pricing['margin_fixed_idr'],
                'sell_price_idr' => $pricing['sell_price_idr'],
                'sell_markup_percent' => $pricing['sell_markup_percent'],
            ]);
            ProviderMapping::create([
                'product_package_id' => $package->id, 'provider_id' => $provider->id,
                'external_sku' => 'sku-'.$mode, 'cost_idr' => 10000, 'store_cost_idr' => 10000,
                'max_price_idr' => 12000, 'priority' => 0, 'is_active' => true,
            ]);
            $packages[$mode] = $package->fresh();
        }
        $this->actingAs($this->admin(), 'admin');

        foreach ($packages as $mode => $before) {
            // Payload yang dikirim Catalog.vue setelah CR-01/CR-04: hanya field
            // identitas yang berubah, field harga tidak ikut dikirim.
            $this->put('/admin/catalog/packages/'.$before->id, [
                'code' => $before->code,
                'name' => $before->name.' baru',
                'group_name' => 'Promo',
                'sort_order' => 7,
                'is_active' => true,
            ])->assertRedirect()->assertSessionHasNoErrors();

            $after = $before->fresh();
            $this->assertSame('Nominal '.$mode.' baru', $after->name);
            $this->assertSame('Promo', $after->group_name);
            $this->assertSame(7, (int) $after->sort_order);
            $this->assertSame($before->pricing_mode, $after->pricing_mode, $mode.': mode harga tidak boleh berubah');
            $this->assertSame($before->sell_price_idr, $after->sell_price_idr, $mode.': harga tetap tidak boleh berubah');
            $this->assertSame($before->sell_markup_percent, $after->sell_markup_percent, $mode.': markup tidak boleh berubah');
            $this->assertSame($before->margin_fixed_idr, $after->margin_fixed_idr, $mode.': margin tetap tidak boleh berubah');
            $this->assertSame($before->margin_percent, $after->margin_percent, $mode.': margin persen tidak boleh berubah');
            $this->assertSame(50, (int) $after->nominal_value, $mode.': nominal_value tidak boleh ditimpa null');
            $this->assertSame('Catatan toko', $after->note, $mode.': catatan nominal tidak boleh terhapus');
        }

        // Server ikut menjaga: request langsung yang mengirim nominal_value null
        // tidak mengosongkan nominal yang sudah tersimpan.
        $broken = $packages['PRODUCT_MARGIN'];
        $this->put('/admin/catalog/packages/'.$broken->id, [
            'code' => $broken->code, 'name' => $broken->name, 'sort_order' => 1, 'is_active' => true,
            'nominal_value' => null, 'pricing_mode' => 'PERCENT', 'sell_price_idr' => null,
            'sell_markup_percent' => null, 'margin_percent' => 0,
        ])->assertRedirect();
        $this->assertSame(50, (int) $broken->fresh()->nominal_value);
        $this->assertSame('PERCENT', $broken->fresh()->pricing_mode);
    }

    public function test_product_paginator_sends_only_pagination_metadata(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->get('/admin/catalog?per_page=10&page=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('productPage.current_page', 1)
                ->where('productPage.per_page', 10)
                ->missing('productPage.data')
                ->missing('productPage.first_page_url')
                ->missing('productPage.links'));
    }

    public function test_catalog_source_tab_routes_stay_available_for_the_panel(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Sumber Panel', 'slug' => 'sumber-panel',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER', 'is_active' => true,
        ]);
        $package = $product->packages()->create(['code' => 'SP-10', 'name' => '10 Koin', 'is_active' => true]);
        $mapping = ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => Provider::where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'sumber-panel-sku', 'cost_idr' => 9000, 'store_cost_idr' => 9000,
            'max_price_idr' => 9500, 'priority' => 3, 'is_active' => true,
        ]);

        // Panel memakai route yang sudah ada: prioritas, aktif/nonaktif, sinkron.
        $this->put('/admin/catalog/mappings/'.$mapping->id, ['priority' => 1, 'is_active' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $mapping->fresh()->priority);

        $this->put('/admin/catalog/mappings/'.$mapping->id, ['priority' => 1, 'is_active' => false])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($mapping->fresh()->is_active);

        // Kunci harga manual tidak boleh terlepas hanya karena panel mengirim
        // prioritas/status.
        $mapping->update(['is_manual_price' => true, 'max_price_idr' => 9000, 'is_active' => true]);
        $this->put('/admin/catalog/mappings/'.$mapping->id, [
            'priority' => 2, 'is_active' => true, 'is_manual_price' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($mapping->fresh()->is_manual_price);
        $this->assertSame(2, (int) $mapping->fresh()->priority);

        // Route sinkron per mapping dan per produk tetap ada (bukan 404/405).
        $this->assertSame(200, $this->get('/admin/catalog')->status());
        $this->assertTrue(Route::has('admin.catalog.mappings.update'));
        $this->assertTrue(Route::has('admin.catalog.mappings.sync'));
        $this->assertTrue(Route::has('admin.catalog.products.sync'));
    }

    public function test_activating_an_automatic_nominal_requires_an_active_provider_mapping(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Otomatis Tanpa Sumber', 'slug' => 'otomatis-tanpa-sumber',
            'margin_percent' => 10, 'fulfillment_mode' => 'AUTO_PROVIDER',
        ]);
        $package = $product->packages()->create(['code' => 'OT-100', 'name' => '100 Koin', 'is_active' => false]);
        $this->actingAs($this->admin(), 'admin');

        $this->put('/admin/catalog/packages/'.$package->id, [
            'code' => 'OT-100', 'name' => '100 Koin', 'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasErrors('is_active');
        $this->assertFalse($package->fresh()->is_active);
    }
}
