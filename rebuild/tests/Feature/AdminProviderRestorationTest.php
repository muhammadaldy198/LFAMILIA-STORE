<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProviderRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(array $permissions = ['providers.manage', 'catalog.manage']): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Provider regression',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('provider-regression-only'),
            'role' => 'ADMIN',
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_provider_menu_restores_editable_registry_and_safe_mapping_inventory(): void
    {
        $this->login();

        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        DB::table('providers')->where('id', $provider->id)->update([
            'display_name' => 'Digiflazz Utama',
            'description' => 'Provider pengujian',
            'is_active' => true,
            'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Provider Test Product',
            'slug' => 'provider-test-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $package = ProductPackage::create([
            'product_id' => $product->id,
            'code' => 'PROVIDER-10',
            'name' => '10 Unit',
            'nominal_value' => 10,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $provider->id,
            'external_sku' => 'SAFE-SKU-10',
            'cost_idr' => 9000,
            'max_price_idr' => 9000,
            'fulfillment_config' => ['customer_no_template' => '{user_id}', 'private_test' => 'never-render-this'],
            'priority' => 2,
            'is_active' => true,
        ]);

        $this->get('/admin/providers?q=Provider%20Test')->assertOk()
            ->assertDontSee('never-render-this')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Providers')
                ->where('providers.0.code', fn ($value) => is_string($value))
                ->has('mappings.data', 1)
                ->where('mappings.data.0.product_name', 'Provider Test Product')
                ->where('mappings.data.0.provider_name', 'Digiflazz Utama')
                ->where('mappings.data.0.external_sku', 'SAFE-SKU-10')
                ->where('mappings.data.0.priority', 2)
                ->where('summary.mapping_active', fn ($value) => (int) $value >= 1));

        $this->get('/admin/catalog')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products', function ($products): bool {
                return collect($products)
                    ->flatMap(fn ($item) => $item['packages'] ?? [])
                    ->flatMap(fn ($package) => $package['mappings'] ?? [])
                    ->contains(fn ($mapping) => ($mapping['provider_name'] ?? null) === 'Digiflazz Utama');
            }));
    }

    public function test_provider_settings_are_editable_audited_and_do_not_accept_credentials(): void
    {
        $admin = $this->login();
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();

        $this->put('/admin/providers/'.$provider->id, [
            'display_name' => 'Penyedia Otomatis',
            'description' => 'Nama dan keterangan dapat diubah dari panel.',
            'sort_order' => 7,
            'is_active' => true,
            'api_key' => 'must-not-be-accepted',
        ])->assertRedirect();

        $this->assertDatabaseHas('providers', [
            'id' => $provider->id,
            'code' => 'DIGIFLAZZ',
            'display_name' => 'Penyedia Otomatis',
            'description' => 'Nama dan keterangan dapat diubah dari panel.',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $audit = DB::table('audit_logs')
            ->where('actor_type', 'admin_user')
            ->where('actor_id', (string) $admin->id)
            ->where('action', 'provider.updated')
            ->where('target_id', (string) $provider->id)
            ->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('must-not-be-accepted', (string) $audit->after);
        $this->assertSame('DIGIFLAZZ', DB::table('providers')->where('id', $provider->id)->value('code'));
    }

    public function test_provider_menu_permission_remains_server_side(): void
    {
        $this->login(['dashboard.view']);

        $this->get('/admin/providers')->assertForbidden();

        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $this->put('/admin/providers/'.$provider->id, [
            'display_name' => 'Tidak Boleh',
            'description' => null,
            'sort_order' => 0,
            'is_active' => false,
        ])->assertForbidden();
    }
}
