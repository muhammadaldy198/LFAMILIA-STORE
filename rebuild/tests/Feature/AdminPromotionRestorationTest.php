<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPromotionRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(array $permissions = ['vouchers.manage'], string $role = 'ADMIN'): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Promo Test',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => Hash::make('promo-restoration-only'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /**
     * @return array{product:Product,package_id:int}
     */
    private function catalog(): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update(['is_active' => true]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Promo Game '.bin2hex(random_bytes(3)),
            'slug' => 'promo-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $package = $product->packages()->create([
            'code' => 'PROMO'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'PROMO-SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product' => $product, 'package_id' => $package->id];
    }

    private function guestPayload(int $packageId, string $key, string $email, string $phone, ?string $voucher = null): array
    {
        return [
            'package_id' => $packageId,
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456'],
            'voucher_code' => $voucher,
            'guest_email' => $email,
            'guest_phone' => $phone,
            'idempotency_key' => $key,
        ];
    }

    public function test_promo_workspace_has_dedicated_component_filters_and_summary(): void
    {
        $this->login();
        DB::table('vouchers')->insert([
            'code' => 'HEMAT10',
            'name' => 'Hemat Sepuluh',
            'description' => 'Promo checkout',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'max_discount_idr' => 5000,
            'minimum_total_idr' => 10000,
            'total_quota' => 10,
            'per_customer_limit' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vouchers')->insert([
            'code' => 'OFFPROMO',
            'name' => 'Promo Mati',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/vouchers?q=HEMAT&status=active&discount_type=PERCENT')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Promotions')
                ->has('vouchers.data', 1)
                ->where('vouchers.data.0.code', 'HEMAT10')
                ->where('vouchers.data.0.name', 'Hemat Sepuluh')
                ->where('vouchers.data.0.status', 'active')
                ->where('vouchers.data.0.max_discount_idr', 5000)
                ->where('summary.total_vouchers', 2)
                ->where('summary.active_vouchers', 1)
                ->has('categories')
                ->has('scopeProducts')
                ->has('popularProducts'));
    }

    public function test_admin_can_create_update_and_delete_unused_voucher_with_scope_and_audit(): void
    {
        $admin = $this->login();
        $catalog = $this->catalog();
        $categoryId = $catalog['product']->category_id;

        $payload = [
            'code' => ' scoped_20 ',
            'name' => 'Scope Dua Puluh',
            'description' => 'Khusus game terpilih',
            'discount_type' => 'PERCENT',
            'discount_value' => 20,
            'max_discount_idr' => 3000,
            'minimum_total_idr' => 10000,
            'total_quota' => 50,
            'per_customer_limit' => 2,
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'product_ids' => [$catalog['product']->id],
            'category_ids' => [$categoryId],
            'is_active' => true,
        ];

        $this->post('/admin/vouchers', $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $voucher = DB::table('vouchers')->where('code', 'SCOPED_20')->firstOrFail();
        $this->assertSame('Scope Dua Puluh', $voucher->name);
        $this->assertSame(3000, (int) $voucher->max_discount_idr);
        $this->assertDatabaseHas('voucher_products', [
            'voucher_id' => $voucher->id,
            'product_id' => $catalog['product']->id,
        ]);
        $this->assertDatabaseHas('voucher_categories', [
            'voucher_id' => $voucher->id,
            'category_id' => $categoryId,
        ]);

        $payload['code'] = 'SCOPED20';
        $payload['name'] = 'Scope Diperbarui';
        $payload['product_ids'] = [];
        $payload['category_ids'] = [];
        $payload['is_active'] = false;

        $this->put('/admin/vouchers/'.$voucher->id, $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'code' => 'SCOPED20',
            'name' => 'Scope Diperbarui',
            'is_active' => false,
        ]);
        $this->assertSame(0, DB::table('voucher_products')->where('voucher_id', $voucher->id)->count());
        $this->assertSame(0, DB::table('voucher_categories')->where('voucher_id', $voucher->id)->count());

        $this->delete('/admin/vouchers/'.$voucher->id)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('vouchers', ['id' => $voucher->id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'action' => 'voucher.deleted',
            'target_id' => (string) $voucher->id,
        ]);
    }

    public function test_percentage_validation_and_period_validation_are_server_side(): void
    {
        $this->login();

        $base = [
            'code' => 'BADPERCENT',
            'name' => 'Bad Percent',
            'description' => null,
            'discount_type' => 'PERCENT',
            'discount_value' => 101,
            'max_discount_idr' => null,
            'minimum_total_idr' => 0,
            'total_quota' => null,
            'per_customer_limit' => null,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->format('Y-m-d H:i:s'),
            'product_ids' => [],
            'category_ids' => [],
            'is_active' => true,
        ];

        $this->post('/admin/vouchers', $base)
            ->assertSessionHasErrors(['discount_value']);

        $base['discount_value'] = 10;
        $this->post('/admin/vouchers', $base)
            ->assertSessionHasErrors(['ends_at']);

        $this->assertDatabaseMissing('vouchers', ['code' => 'BADPERCENT']);
    }

    public function test_reserved_capacity_cannot_be_invalidated_and_used_voucher_cannot_be_deleted(): void
    {
        $catalog = $this->catalog();

        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => 'CAPACITY2',
            'name' => 'Capacity Two',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'total_quota' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/checkout/orders', $this->guestPayload(
            $catalog['package_id'],
            'promo-capacity-0001',
            'first@example.test',
            '081111111111',
            'CAPACITY2'
        ))->assertCreated();
        $this->postJson('/checkout/orders', $this->guestPayload(
            $catalog['package_id'],
            'promo-capacity-0002',
            'second@example.test',
            '082222222222',
            'CAPACITY2'
        ))->assertCreated();

        $this->login();

        $payload = [
            'code' => 'CAPACITY2',
            'name' => 'Capacity Two',
            'description' => null,
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'max_discount_idr' => null,
            'minimum_total_idr' => 0,
            'total_quota' => 1,
            'per_customer_limit' => null,
            'starts_at' => null,
            'ends_at' => null,
            'product_ids' => [],
            'category_ids' => [],
            'is_active' => true,
        ];

        $this->put('/admin/vouchers/'.$voucherId, $payload)
            ->assertSessionHasErrors(['total_quota']);

        $this->delete('/admin/vouchers/'.$voucherId)
            ->assertSessionHasErrors(['voucher']);

        $this->assertDatabaseHas('vouchers', ['id' => $voucherId, 'total_quota' => 2]);

        $this->get('/admin/vouchers?status=exhausted')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Promotions')
                ->has('vouchers.data', 1)
                ->where('vouchers.data.0.id', $voucherId)
                ->where('vouchers.data.0.status', 'exhausted')
                ->where('vouchers.data.0.reserved_count', 2)
                ->where('vouchers.data.0.remaining_quota', 0));
    }

    public function test_checkout_honors_maximum_voucher_discount_and_snapshots_it(): void
    {
        $catalog = $this->catalog();
        DB::table('vouchers')->insert([
            'code' => 'CAP2000',
            'name' => 'Diskon Besar Dibatasi',
            'discount_type' => 'PERCENT',
            'discount_value' => 50,
            'max_discount_idr' => 2000,
            'minimum_total_idr' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/checkout/orders', $this->guestPayload(
            $catalog['package_id'],
            'promo-max-discount-0001',
            'max@example.test',
            '083333333333',
            'CAP2000'
        ))->assertCreated();

        $this->assertSame(9000, $response->json('total_idr'));

        $snapshot = json_decode(
            DB::table('orders')->where('idempotency_key', 'promo-max-discount-0001')->value('snapshot'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('Diskon Besar Dibatasi', $snapshot['voucher']['name']);
        $this->assertSame(2000, $snapshot['voucher']['max_discount_idr']);
    }

    public function test_popular_priority_is_managed_here_without_changing_product_active_state(): void
    {
        $this->login();
        $catalog = $this->catalog();
        $this->assertFalse((bool) $catalog['product']->popular);

        $this->put('/admin/vouchers/popular/'.$catalog['product']->id, [
            'popular' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $catalog['product']->refresh();
        $this->assertTrue((bool) $catalog['product']->popular);
        $this->assertTrue((bool) $catalog['product']->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'promotion.popular.updated',
            'target_type' => 'product',
            'target_id' => (string) $catalog['product']->id,
        ]);
    }

    public function test_promo_routes_remain_permission_gated(): void
    {
        $this->login(['dashboard.view']);

        $this->get('/admin/vouchers')->assertForbidden();
        $this->post('/admin/vouchers', [])->assertForbidden();
    }
}
