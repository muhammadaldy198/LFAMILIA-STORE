<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPanelRbacTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_only_super_admin_can_manage_team_and_last_owner_is_protected(): void
    {
        [$ownerToken, $ownerId] = $this->panelUser('owner', 'Owner', 'super_admin', 'owner-password-123');
        [$adminToken] = $this->panelUser('admin1', 'Admin', 'admin', 'admin-password-123');

        $this->withUnencryptedCookie(AdminAuthService::COOKIE, $adminToken)
            ->getJson('/api/admin/team')
            ->assertForbidden();

        $this->withUnencryptedCookie(AdminAuthService::COOKIE, $ownerToken)
            ->postJson('/api/admin/team', [
                'username' => 'staff1',
                'name' => 'Staff Satu',
                'role' => 'staff',
                'isActive' => true,
                'password' => 'staff-password-123',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('admin_users', [
            'email' => 'staff1',
            'role' => 'staff',
            'is_active' => 1,
        ]);

        $this->withUnencryptedCookie(AdminAuthService::COOKIE, $ownerToken)
            ->deleteJson('/api/admin/team?id='.$ownerId)
            ->assertStatus(400)
            ->assertJsonPath('error', 'Toko harus memiliki setidaknya satu Super Admin aktif.');
    }

    public function test_staff_dashboard_does_not_expose_finance(): void
    {
        [$staffToken] = $this->panelUser('staff1', 'Staff', 'staff', 'staff-password-123');

        DB::table('orders')->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'reference_id' => 'LF260928ADMIN0001',
            'product_slug' => 'manual',
            'product_name' => 'Manual',
            'package_sku' => 'M1',
            'package_label' => '1',
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'destination' => '123',
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '+6281234567890',
            'customer_inputs_json' => '[]',
            'quantity' => 1,
            'base_subtotal' => 10000,
            'subtotal' => 10000,
            'discount_amount' => 0,
            'supplier_cost_snapshot' => 9000,
            'admin_fee' => 0,
            'total' => 10000,
            'payment_method' => 'qris',
            'payment_channel' => 'mpm',
            'payment_status' => 'paid',
            'fulfillment_status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withUnencryptedCookie(AdminAuthService::COOKIE, $staffToken)
            ->getJson('/api/admin/summary?range=7d')
            ->assertOk()
            ->assertJsonPath('canViewFinance', false)
            ->assertJsonPath('metrics.paidRevenue', null)
            ->assertJsonPath('metrics.profit', null)
            ->assertJsonPath('recentOrders.0.total', null);
    }

    /** @return array{0:string,1:int} */
    private function panelUser(string $username, string $name, string $role, string $password): array
    {
        $auth = app(AdminAuthService::class);
        $auth->createCredential($username, $name, $password, true);
        $id = (int) DB::table('admin_users')->insertGetId([
            'email' => $username,
            'name' => $name,
            'role' => $role,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $auth->login($username, $password, $role === 'staff' ? 'staff' : 'backoffice');

        return [$session['token'], $id];
    }
}
