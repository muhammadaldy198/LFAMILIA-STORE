<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://digiflazz.test/v1/price-list' => Http::response(['data' => $rows])]);
    }

    public function test_sync_import_and_resync_preserve_baseline_and_inactive_import(): void
    {
        $this->login(); $this->fakeCatalog([$this->row()]);
        $this->post('/admin/digiflazz/sync')->assertRedirect()->assertSessionHasNoErrors();
        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->first();
        $product = $this->product();
        $this->post('/admin/catalog/products/'.$product->id.'/import', ['item_ids' => [$item->id], 'margin_percent' => 12])->assertRedirect()->assertSessionHasNoErrors();
        $package = $product->packages()->firstOrFail(); $mapping = $package->mappings()->firstOrFail();
        $this->assertFalse($package->is_active); $this->assertFalse($mapping->is_active);
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
        $this->login(); $this->fakeCatalog([$this->row()]); app(DigiflazzCatalogService::class)->sync();
        $this->fakeCatalog([$this->row(['price' => 15000]), $this->row(['buyer_sku_code' => 'bad', 'price' => 0])]);
        $this->post('/admin/digiflazz/sync')->assertSessionHasErrors('sync');
        $this->assertSame(10000, (int) DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'restore-sku')->value('price_idr'));
    }

    public function test_unavailable_and_stale_skus_cannot_be_imported(): void
    {
        $this->login(); $this->fakeCatalog([$this->row(['seller_product_status' => false])]); app(DigiflazzCatalogService::class)->sync();
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
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail(); $provider->forceFill(['is_active' => true])->save();
        ProviderMapping::create(['product_package_id' => $package->id, 'provider_id' => $provider->id, 'external_sku' => 'restore-sku', 'cost_idr' => 10000, 'max_price_idr' => 10000, 'is_active' => true]);
        $pricing = app(CheckoutPricing::class);
        $this->assertSame(11000, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'PERCENT', 'margin_percent' => 5]);
        $this->assertSame(10500, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'FIXED', 'margin_fixed_idr' => 123]);
        $this->assertSame(10123, $pricing->forPackage($package->id)['subtotal_idr']);
        $package->update(['pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => 15000]);
        $this->assertSame(15000, $pricing->forPackage($package->id)['subtotal_idr']);
        $this->fakeCatalog([$this->row(['seller_product_status' => false])]); app(DigiflazzCatalogService::class)->sync();
        $this->expectException(ValidationException::class); $pricing->forPackage($package->id);
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
        $this->login(); $product = $this->product(); $other = $this->product();
        $a = ProductPackage::create(['product_id' => $product->id, 'code' => 'A', 'name' => 'A', 'sort_order' => 0]);
        $b = ProductPackage::create(['product_id' => $product->id, 'code' => 'B', 'name' => 'B', 'sort_order' => 1]);
        $x = ProductPackage::create(['product_id' => $other->id, 'code' => 'X', 'name' => 'X']);
        $this->put('/admin/catalog/products/'.$product->id.'/packages/reorder', ['ids' => [$a->id, $x->id]])->assertSessionHasErrors('ids');
        $this->put('/admin/catalog/products/'.$product->id.'/packages/reorder', ['ids' => [$b->id, $a->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$b->id, $a->id], $product->packages()->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_fields_save_order_and_protect_nickname_references(): void
    {
        $this->login(); $product = $this->product();
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

    public function test_new_operations_are_permission_gated(): void
    {
        $this->login(['dashboard.view'], 'ADMIN');
        $this->post('/admin/digiflazz/sync')->assertForbidden();
        $this->put('/admin/catalog/margin', ['margin_percent' => 10])->assertForbidden();
        $this->put('/admin/support/quick-replies', ['replies' => ['Hello']])->assertForbidden();
    }

    public function test_admin_activation_is_single_use_and_login_redirects_to_panel(): void
    {
        $admin = AdminUser::create(['name' => 'Activation', 'email' => 'activation-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => bcrypt('before-password-123'), 'role' => 'SUPER_ADMIN', 'is_active' => true]);
        $token = bin2hex(random_bytes(32));
        $key = 'admin_activation:'.hash('sha256', $token);
        \Illuminate\Support\Facades\Cache::put($key, ['id' => $admin->id, 'password_fingerprint' => hash('sha256', $admin->password)], now()->addMinutes(30));
        $this->get('/admin/activate?token='.$token)->assertOk();
        $this->post('/admin/activate', ['token' => $token, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertRedirect('/admin/login')->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password-123', $admin->fresh()->password));
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
}
