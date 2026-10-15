<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\HomeBanner;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProviderMapping;
use App\Models\StoreAsset;
use App\Services\DigiflazzCatalogImport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
}
