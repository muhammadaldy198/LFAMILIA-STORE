<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminMultiSourceFulfillmentTest extends TestCase
{
    use DatabaseTransactions;

    private function login(): void
    {
        $admin = AdminUser::create([
            'name' => 'Multi source admin',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('multi-source-test-only'),
            'role' => 'ADMIN',
            'permissions' => ['catalog.manage'],
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');
    }

    private function catalogItem(string $sku, string $seller, int $price): int
    {
        return DB::table('digiflazz_catalog_items')->insertGetId([
            'buyer_sku_code' => $sku,
            'product_name' => '86 Diamonds',
            'category' => 'Games',
            'brand' => 'Mobile Legends',
            'type' => 'Diamonds',
            'seller_name' => $seller,
            'price_idr' => $price,
            'baseline_price_idr' => $price,
            'buyer_active' => true,
            'seller_active' => true,
            'unlimited_stock' => true,
            'stock' => 0,
            'start_cut_off' => '00:00',
            'end_cut_off' => '00:00',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function package(): ProductPackage
    {
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->value('id'),
            'name' => 'Multi Source '.bin2hex(random_bytes(3)),
            'slug' => 'multi-source-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        return ProductPackage::create([
            'product_id' => $product->id,
            'code' => 'MS'.bin2hex(random_bytes(3)),
            'name' => '86 Diamonds',
            'nominal_value' => 86,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_attach_multiple_digiflazz_skus_to_one_nominal_and_remove_unused_backup(): void
    {
        $this->login();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true, 'updated_at' => now()]);
        $providerId = Provider::where('code', 'DIGIFLAZZ')->value('id');
        $package = $this->package();

        $primaryItem = $this->catalogItem('MS-PRIMARY', 'Seller A', 10000);
        $backupItem = $this->catalogItem('MS-BACKUP', 'Seller B', 10200);

        $this->post('/admin/catalog/packages/'.$package->id.'/digiflazz-mappings', [
            'item_id' => $primaryItem,
            'priority' => 0,
            'is_active' => true,
            'customer_no_template' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post('/admin/catalog/packages/'.$package->id.'/digiflazz-mappings', [
            'item_id' => $backupItem,
            'priority' => 10,
            'is_active' => true,
            'customer_no_template' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $routes = DB::table('provider_mappings')
            ->where('product_package_id', $package->id)
            ->where('provider_id', $providerId)
            ->orderBy('priority')
            ->get();

        $this->assertCount(2, $routes);
        $this->assertSame('MS-PRIMARY', $routes[0]->external_sku);
        $this->assertSame(0, (int) $routes[0]->priority);
        $this->assertSame('MS-BACKUP', $routes[1]->external_sku);
        $this->assertSame(10, (int) $routes[1]->priority);

        $backup = $routes[1];
        $this->put('/admin/catalog/mappings/'.$backup->id, [
            'priority' => 10,
            'is_active' => false,
            'customer_no_template' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->delete('/admin/catalog/mappings/'.$backup->id)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('provider_mappings', ['id' => $backup->id]);
        $this->assertDatabaseHas('provider_mappings', ['id' => $routes[0]->id]);
    }

    public function test_same_digiflazz_sku_cannot_be_attached_to_two_nominals(): void
    {
        $this->login();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true, 'updated_at' => now()]);
        $first = $this->package();
        $second = $this->package();
        $itemId = $this->catalogItem('MS-UNIQUE', 'Seller Unique', 9900);

        $this->post('/admin/catalog/packages/'.$first->id.'/digiflazz-mappings', [
            'item_id' => $itemId,
            'priority' => 0,
            'is_active' => true,
            'customer_no_template' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post('/admin/catalog/packages/'.$second->id.'/digiflazz-mappings', [
            'item_id' => $itemId,
            'priority' => 0,
            'is_active' => true,
            'customer_no_template' => null,
        ])->assertRedirect()->assertSessionHasErrors(['item_id']);

        $this->assertSame(1, DB::table('provider_mappings')->where('external_sku', 'MS-UNIQUE')->count());
    }

    public function test_mapping_used_by_order_cannot_be_deleted(): void
    {
        $this->login();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true, 'updated_at' => now()]);
        $package = $this->package();
        $itemId = $this->catalogItem('MS-HISTORY', 'Seller History', 10000);

        $this->post('/admin/catalog/packages/'.$package->id.'/digiflazz-mappings', [
            'item_id' => $itemId,
            'priority' => 0,
            'is_active' => false,
            'customer_no_template' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $mapping = DB::table('provider_mappings')->where('external_sku', 'MS-HISTORY')->firstOrFail();
        DB::table('orders')->insert([
            'order_number' => 'MS-HISTORY-'.bin2hex(random_bytes(5)),
            'product_id' => $package->product_id,
            'product_package_id' => $package->id,
            'provider_mapping_id' => $mapping->id,
            'status' => 'FAILED',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'ms-history-'.bin2hex(random_bytes(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->delete('/admin/catalog/mappings/'.$mapping->id)
            ->assertRedirect()->assertSessionHasErrors(['mapping']);

        $this->assertDatabaseHas('provider_mappings', ['id' => $mapping->id]);
    }
}
