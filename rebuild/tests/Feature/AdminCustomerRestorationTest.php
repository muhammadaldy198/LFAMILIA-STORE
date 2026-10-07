<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCustomerRestorationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function login(string $role = 'ADMIN', array $permissions = ['customers.view']): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Customer restoration',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => Hash::make('customer-restoration-only'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function customer(array $overrides = []): User
    {
        $tier = $overrides['membership_tier_code'] ?? 'BASIC';
        unset($overrides['membership_tier_code']);

        $user = User::create(array_replace([
            'name' => 'Customer Test',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'email_verified_at' => now(),
            'phone' => '081234567890',
            'password' => Hash::make('VeryStrongCustomer123!'),
        ], $overrides));

        $trusted = array_intersect_key($overrides, array_flip(['email_verified_at', 'google_sub', 'last_active_at']));
        if ($trusted !== []) {
            $user->forceFill($trusted);
        }
        $user->forceFill(['membership_tier_code' => $tier])->saveQuietly();

        return $user;
    }

    public function test_customer_workspace_has_real_filters_summary_and_no_google_identifier_leak(): void
    {
        $this->login();

        $target = $this->customer([
            'name' => 'Alya Gold',
            'email' => 'alya-gold@example.test',
            'phone' => '081299991111',
            'membership_tier_code' => 'GOLD',
            'google_sub' => 'google-secret-subject-never-render',
            'last_active_at' => now(),
        ]);
        $this->customer([
            'name' => 'Other Customer',
            'email_verified_at' => null,
            'membership_tier_code' => 'BASIC',
        ]);

        DB::table('wallets')->where('user_id', $target->id)->update(['balance_idr' => 25000]);

        $this->get('/admin/customers?q=081299991111&tier=GOLD&verification=verified&activity=active_30d')
            ->assertOk()
            ->assertDontSee('google-secret-subject-never-render')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers')
                ->where('isSuperAdmin', false)
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $target->id)
                ->where('customers.data.0.name', 'Alya Gold')
                ->where('customers.data.0.google_linked', true)
                ->where('customers.data.0.email_verified', true)
                ->where('customers.data.0.balance_idr', 25000)
                ->where('customers.data.0.membership_tier_code', 'GOLD')
                ->has('summary')
                ->has('membershipTiers'));
    }

    public function test_customer_detail_combines_operational_history_without_exposing_saved_account_input(): void
    {
        $this->login();

        $user = $this->customer([
            'name' => 'History Customer',
            'google_sub' => 'google-detail-secret-never-render',
        ]);
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'History Product',
            'slug' => 'history-customer-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'MANUAL',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $package = ProductPackage::create([
            'product_id' => $product->id,
            'code' => 'HISTORY10',
            'name' => 'History 10',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        DB::table('orders')->insert([
            'order_number' => 'CUSTOMER-HISTORY-'.bin2hex(random_bytes(4)),
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_package_id' => $package->id,
            'status' => 'SUCCESS',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
            'snapshot' => '{}',
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => bin2hex(random_bytes(20)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallet_topups')->insert([
            'wallet_id' => $user->wallet->id,
            'user_id' => $user->id,
            'amount_idr' => 10000,
            'fee_idr' => 0,
            'total_idr' => 10000,
            'status' => 'PAID',
            'idempotency_key' => bin2hex(random_bytes(20)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('support_tickets')->insert([
            'user_id' => $user->id,
            'subject' => 'Bantuan transaksi',
            'message' => 'Mohon diperiksa.',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_game_accounts')->insert([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'label' => 'Akun Utama',
            'customer_input' => json_encode([
                'user_id' => '123456',
                'password' => 'saved-account-secret-never-render',
            ], JSON_THROW_ON_ERROR),
            'nickname' => 'HistoryNick',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/customers/'.$user->id)
            ->assertOk()
            ->assertDontSee('google-detail-secret-never-render')
            ->assertDontSee('saved-account-secret-never-render')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CustomerDetail')
                ->where('customer.id', $user->id)
                ->where('customer.google_linked', true)
                ->has('orders.data', 1)
                ->has('topups', 1)
                ->has('tickets', 1)
                ->has('savedAccounts', 1)
                ->where('savedAccounts.0.label', 'Akun Utama')
                ->where('savedAccounts.0.nickname', 'HistoryNick')
                ->where('summary.orders', 1)
                ->where('summary.topups', 1)
                ->where('summary.tickets', 1)
                ->where('summary.saved_accounts', 1));
    }

    public function test_customer_mutations_are_super_admin_only(): void
    {
        $user = $this->customer();
        $this->login('ADMIN', ['customers.view']);

        $this->get('/admin/customers')->assertOk();

        $this->withSession(['admin.password_confirmed_at' => time()])->post('/admin/customers/'.$user->id.'/wallet', [
            'amount_idr' => 5000,
            'reason' => 'Koreksi saldo',
            'idempotency_key' => 'customer-denied-wallet-0001',
        ])->assertForbidden();

        $this->put('/admin/customers/'.$user->id.'/membership', [
            'membership_tier_code' => 'SILVER',
        ])->assertForbidden();

        $this->delete('/admin/customers/'.$user->id)->assertForbidden();
        $this->post('/admin/customers/cleanup/run')->assertForbidden();
    }

    public function test_super_admin_wallet_adjustment_is_atomic_idempotent_and_audited(): void
    {
        $admin = $this->login('SUPER_ADMIN');
        $user = $this->customer();

        $payload = [
            'amount_idr' => 15000,
            'reason' => 'Koreksi saldo pelanggan',
            'idempotency_key' => 'customer-wallet-idempotent-0001',
        ];

        $this->withSession(['admin.password_confirmed_at' => time()])->post('/admin/customers/'.$user->id.'/wallet', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->withSession(['admin.password_confirmed_at' => time()])->post('/admin/customers/'.$user->id.'/wallet', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(15000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
        $this->assertSame(1, DB::table('wallet_ledger')
            ->where('idempotency_key', 'customer-wallet-idempotent-0001')->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'action' => 'customer.wallet.adjusted',
            'target_id' => (string) $user->id,
        ]);

        $this->withSession(['admin.password_confirmed_at' => time()])->post('/admin/customers/'.$user->id.'/wallet', [
            'amount_idr' => -20000,
            'reason' => 'Tidak boleh negatif',
            'idempotency_key' => 'customer-wallet-negative-0001',
        ])->assertSessionHasErrors(['amount_idr']);

        $this->assertSame(15000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
    }

    public function test_super_admin_membership_can_switch_manual_and_back_to_automatic(): void
    {
        $this->login('SUPER_ADMIN');
        $user = $this->customer();

        $this->put('/admin/customers/'.$user->id.'/membership', [
            'membership_tier_code' => 'SILVER',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('MANUAL', $user->membership_mode);
        $this->assertSame('SILVER', $user->membership_tier_code);
        $this->assertSame('SILVER', $user->membership_override_code);

        $this->put('/admin/customers/'.$user->id.'/membership', [
            'membership_tier_code' => 'AUTO',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('AUTO', $user->membership_mode);
        $this->assertNull($user->membership_override_code);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('action', 'customer.membership.updated')
            ->where('target_id', (string) $user->id)->count());
    }

    public function test_empty_account_deletion_purges_saved_game_account_personal_data(): void
    {
        $this->login('SUPER_ADMIN');
        $user = $this->customer();
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Deletion Product',
            'slug' => 'deletion-product-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'MANUAL',
            'margin_percent' => 10,
            'is_active' => true,
        ]);

        DB::table('saved_game_accounts')->insert([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'label' => 'Sensitive Account',
            'customer_input' => json_encode(['password' => 'must-be-purged'], JSON_THROW_ON_ERROR),
            'nickname' => 'DeleteMe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->delete('/admin/customers/'.$user->id)
            ->assertRedirect('/admin/customers')
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSame(0, DB::table('saved_game_accounts')->where('user_id', $user->id)->count());

        $deleted = User::withTrashed()->findOrFail($user->id);
        $this->assertNull($deleted->email);
        $this->assertNull($deleted->phone);
        $this->assertNull($deleted->google_sub);
    }

    public function test_customer_list_permission_remains_server_side(): void
    {
        $this->login('ADMIN', ['dashboard.view']);

        $this->get('/admin/customers')->assertForbidden();
        $this->get('/admin/customers/1')->assertForbidden();
    }
}
