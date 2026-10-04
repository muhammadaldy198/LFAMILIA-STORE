<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\AdminPermissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminM9Test extends TestCase
{
    use DatabaseTransactions;

    private function admin(array $permissions = []): AdminUser
    {
        return AdminUser::create([
            'name' => 'Admin Test',
            'email' => 'admin-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'ADMIN',
            'permissions' => $permissions,
            'is_active' => true,
        ]);
    }

    private function superAdmin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Super Test',
            'email' => 'super-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'SUPER_ADMIN',
            'permissions' => null,
            'is_active' => true,
        ]);
    }

    public function test_admin_menu_includes_account_validation_and_no_staff_role(): void
    {
        $super = $this->superAdmin();
        $menu = app(AdminPermissionService::class)->menu($super);

        $this->assertSame([
            'Dashboard', 'Pesanan', 'Produk', 'Manual', 'Banner & Konten', 'Digiflazz',
            'Validasi Akun', 'Provider', 'Pembayaran', 'Pelanggan', 'Promo', 'Layanan Pelanggan', 'Laporan',
            'Admin & Akses', 'Pengaturan', 'Integrasi', 'System Health', 'Audit Log',
        ], collect($menu)->pluck('label')->all());
        $this->assertCount(18, $menu);

        $unsupported = AdminUser::create([
            'name' => 'Unsupported Staff',
            'email' => 'unsupported-staff-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'STAFF',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);
        $this->assertFalse(app(AdminPermissionService::class)->allows($unsupported, 'dashboard.view'));
    }

    public function test_admin_routes_are_permission_gated_and_super_admin_bypasses_permissions(): void
    {
        $admin = $this->admin(['dashboard.view', 'orders.view']);
        $this->actingAs($admin, 'admin');

        $this->get('/admin/panel')->assertOk();
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/providers')->assertForbidden();
        $this->get('/admin/nickname-tools')->assertForbidden();
        $this->get('/admin/integrations')->assertForbidden();

        auth('admin')->logout();
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');

        $this->get('/admin/integrations')->assertOk();
        $this->get('/admin/nickname-tools')->assertOk();
        $this->get('/admin/health')->assertOk();
        $this->get('/admin/audit')->assertOk();
    }

    public function test_super_admin_can_store_integration_without_secret_in_response_or_audit(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');

        $this->put('/admin/integrations/digiflazz', [
            'is_active' => true,
            'environment' => 'test',
            'config' => [
                'username' => 'buyer-test',
                'api_key' => 'secret-api-key',
                'webhook_secret' => 'secret-hook',
            ],
            'clear_secrets' => [],
        ])->assertRedirect();

        $credential = IntegrationCredential::where('code', 'digiflazz')->firstOrFail();
        $this->assertTrue($credential->is_active);
        $this->assertSame('test', $credential->config_ciphertext['environment']);
        $this->assertSame('secret-api-key', $credential->config_ciphertext['profiles']['test']['api_key']);

        $audit = DB::table('audit_logs')->where('action', 'integration.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('secret-api-key', (string) $audit->after);
        $this->assertStringContainsString('[REDACTED]', (string) $audit->after);

        $response = $this->get('/admin/integrations')->assertOk();
        $this->assertStringNotContainsString('secret-api-key', $response->getContent());

        $this->postJson('/admin/integrations/digiflazz/reveal/api_key', [
            'password' => 'VeryStrongPassword123!',
        ])->assertNotFound();
    }

    public function test_blank_secret_update_preserves_selected_environment_secret(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');
        IntegrationCredential::create([
            'code' => 'midtrans',
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => [
                        'server_key' => 'server-secret',
                        'client_key' => 'client-secret',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $this->put('/admin/integrations/midtrans', [
            'is_active' => true,
            'environment' => 'sandbox',
            'config' => [
                'server_key' => '',
                'client_key' => '',
            ],
            'clear_secrets' => [],
        ])->assertRedirect();

        $config = IntegrationCredential::where('code', 'midtrans')->firstOrFail()->config_ciphertext;
        $this->assertSame('sandbox', $config['environment']);
        $this->assertSame('server-secret', $config['profiles']['sandbox']['server_key']);
        $this->assertSame('client-secret', $config['profiles']['sandbox']['client_key']);
    }

    public function test_wallet_adjustment_is_super_admin_only_atomic_and_idempotent(): void
    {
        $user = User::create([
            'name' => 'Wallet User',
            'email' => 'wallet-'.bin2hex(random_bytes(4)).'@example.test',
            'email_verified_at' => now(),
            'phone' => '081234567890',
            'password' => Hash::make('VeryStrongPassword123!'),
            'membership_tier_code' => 'BASIC',
        ]);

        $admin = $this->admin(['dashboard.view', 'customers.view', 'customers.wallet']);
        $this->actingAs($admin, 'admin');
        $this->post('/admin/customers/'.$user->id.'/wallet', [
            'amount_idr' => 5000,
            'reason' => 'Test',
            'idempotency_key' => 'admin-wallet-denied-0001',
        ])->assertForbidden();

        auth('admin')->logout();
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');

        $payload = [
            'amount_idr' => 5000,
            'reason' => 'Koreksi saldo',
            'idempotency_key' => 'admin-wallet-idempotent-0001',
        ];
        $this->post('/admin/customers/'.$user->id.'/wallet', $payload)->assertRedirect();
        $this->post('/admin/customers/'.$user->id.'/wallet', $payload)->assertRedirect();

        $this->assertSame(5000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
        $this->assertSame(1, DB::table('wallet_ledger')
            ->where('idempotency_key', 'admin-wallet-idempotent-0001')->count());

        $this->post('/admin/customers/'.$user->id.'/wallet', [
            'amount_idr' => -6000,
            'reason' => 'Tidak boleh negatif',
            'idempotency_key' => 'admin-wallet-negative-0001',
        ])->assertSessionHasErrors('amount_idr');

        $this->assertSame(5000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
    }

    public function test_notification_read_state_is_per_admin(): void
    {
        Queue::fake();
        $first = $this->admin(['dashboard.view', 'notifications.view']);
        $second = $this->admin(['dashboard.view', 'notifications.view']);

        $notificationId = app(AdminNotificationService::class)->record(
            'test.event',
            'Test notification',
            'Notification body'
        );

        $this->actingAs($first, 'admin');
        $this->post('/admin/notifications/'.$notificationId.'/read')->assertRedirect();

        $this->assertDatabaseHas('admin_notification_reads', [
            'admin_notification_id' => $notificationId,
            'admin_user_id' => $first->id,
        ]);
        $this->assertDatabaseMissing('admin_notification_reads', [
            'admin_notification_id' => $notificationId,
            'admin_user_id' => $second->id,
        ]);
    }

    public function test_last_active_super_admin_cannot_be_disabled(): void
    {
        AdminUser::where('role', 'SUPER_ADMIN')->update(['is_active' => false]);
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');

        $this->put('/admin/access/'.$super->id, [
            'name' => $super->name,
            'email' => $super->email,
            'password' => null,
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => false,
        ])->assertSessionHasErrors('role');

        $super->refresh();
        $this->assertSame('SUPER_ADMIN', $super->role);
        $this->assertTrue($super->is_active);
    }

    public function test_membership_configuration_and_manual_tier_change_are_audited(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');

        $this->put('/admin/settings/membership/SILVER', [
            'is_active' => true,
            'requirements' => '{"minimum_spend_idr":100000}',
            'benefits' => '{"discount_bps":100}',
        ])->assertRedirect();

        $tier = DB::table('membership_tiers')->where('code', 'SILVER')->first();
        $this->assertStringContainsString('minimum_spend_idr', (string) $tier->requirements);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'membership_tier.updated')->where('target_id', 'SILVER')->count());

        $user = User::create([
            'name' => 'Member User',
            'email' => 'member-'.bin2hex(random_bytes(4)).'@example.test',
            'email_verified_at' => now(),
            'phone' => '081234567899',
            'password' => Hash::make('VeryStrongPassword123!'),
            'membership_tier_code' => 'BASIC',
        ]);
        $this->put('/admin/customers/'.$user->id.'/membership', [
            'membership_tier_code' => 'SILVER',
        ])->assertRedirect();

        $this->assertSame('SILVER', $user->fresh()->membership_tier_code);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'customer.membership.updated')->where('target_id', (string) $user->id)->count());
    }

    public function test_auth_email_notifications_are_queued_for_resend_delivery(): void
    {
        Queue::fake();
        $user = User::create([
            'name' => 'Mail User',
            'email' => 'mail-'.bin2hex(random_bytes(4)).'@example.test',
            'phone' => '081234567811',
            'password' => Hash::make('VeryStrongPassword123!'),
            'membership_tier_code' => 'BASIC',
        ]);

        $user->sendEmailVerificationNotification();
        $user->sendPasswordResetNotification('reset-token-test');

        Queue::assertPushed(SendTransactionalEmailJob::class, 2);
    }
}
