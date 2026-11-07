<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminSessionRevocationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function admin(string $email, string $role = 'SUPER_ADMIN'): AdminUser
    {
        return $this->createAdmin([
            'name' => 'Security Admin',
            'email' => $email,
            'password' => Hash::make('old-admin-password-123'),
            'role' => $role,
            'permissions' => $role === 'ADMIN' ? ['dashboard.view'] : null,
            'is_active' => true,
        ]);
    }

    private function login(AdminUser $admin, string $password = 'old-admin-password-123'): void
    {
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => $password,
        ])->assertRedirect(route('admin.panel'));
        $this->get('/admin/panel')->assertOk();
    }

    private function changePassword(AdminUser $target, string $password): void
    {
        $this->withSession(['admin.password_confirmed_at' => time()])
            ->put('/admin/access/'.$target->id, [
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
                'permissions' => $target->permissions ?? [],
                'is_active' => true,
                'password' => $password,
            ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_password_change_by_another_admin_revokes_target_session_but_not_actor(): void
    {
        $target = $this->admin('revoked-admin@example.test', 'ADMIN');
        $actor = $this->admin('actor-admin@example.test');

        $this->login($target);
        $loginKey = Auth::guard('admin')->getName();
        $targetLogin = session($loginKey);
        $targetFingerprint = session('security.admin_auth_fingerprint');
        $this->assertIsString($targetFingerprint);

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->login($actor);
        $this->changePassword($target, 'changed-admin-password-123');

        $this->get('/admin/panel')->assertOk();
        $this->assertAuthenticatedAs($actor, 'admin');

        // Simulate the other device with its old login ID and credential stamp.
        $this->withSession([
            $loginKey => $targetLogin,
            'security.admin_auth_fingerprint' => $targetFingerprint,
        ]);
        Auth::forgetGuards();
        $this->get('/admin/panel')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_admin_changing_own_password_keeps_current_session_and_revokes_second_device(): void
    {
        $admin = $this->admin('self-admin@example.test');
        $this->login($admin);
        $originalFingerprint = session('security.admin_auth_fingerprint');

        $this->changePassword($admin, 'changed-admin-password-123');
        $this->get('/admin/panel')->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotSame($originalFingerprint, session('security.admin_auth_fingerprint'));

        // Second browser has an older fingerprint although it is the same admin.
        $this->withSession(['security.admin_auth_fingerprint' => $originalFingerprint]);
        Auth::forgetGuards();
        $this->get('/admin/panel')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_admin_session_revocation_does_not_log_out_customer_on_same_browser(): void
    {
        $customer = User::create([
            'name' => 'Shared Browser Customer',
            'email' => 'shared-browser-security@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('customer-password-123'),
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();
        $this->post('/login', [
            'email' => $customer->email,
            'password' => 'customer-password-123',
        ])->assertRedirect();
        $this->get('/account')->assertOk();

        $admin = $this->admin('shared-admin-security@example.test');
        $this->login($admin);
        $this->withSession([
            'security.admin_auth_fingerprint' => 'invalid-admin-fingerprint',
            'admin.password_confirmed_at' => time(),
        ]);
        $this->get('/admin/panel')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($customer, 'web');
        $this->get('/account')->assertOk();
    }

    public function test_in_flight_admin_request_cannot_adopt_password_rotated_by_other_device(): void
    {
        $admin = $this->admin('race-admin-security@example.test');
        $this->login($admin);
        $previousFingerprint = session('security.admin_auth_fingerprint');

        // Password rotation happens after the middleware's initial check,
        // while this otherwise ordinary authenticated request is executing.
        Route::middleware(['web', 'auth:admin'])->get('/admin/security/credential-race', function () use ($admin) {
            $admin->forceFill(['password' => Hash::make('concurrent-new-password-123')])->save();

            return response('ok');
        });
        $this->get('/admin/security/credential-race')->assertOk();

        $this->assertSame($previousFingerprint, session('security.admin_auth_fingerprint'));
        Auth::forgetGuards();
        $this->get('/admin/panel')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_admin_broker_reset_revokes_old_sessions_without_auto_login_and_token_reuse(): void
    {
        $admin = $this->admin('broker-reset-security@example.test');
        $this->login($admin);
        $loginKey = Auth::guard('admin')->getName();
        $oldLogin = session($loginKey);
        $oldFingerprint = session('security.admin_auth_fingerprint');

        // Reset occurs from an unauthenticated browser; the original session
        // is restored later to check the credential revocation.
        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $token = Password::broker('admins')->createToken($admin);
        $payload = [
            'email' => $admin->email,
            'token' => $token,
            'password' => 'reset-admin-password-123',
            'password_confirmation' => 'reset-admin-password-123',
        ];
        $this->post('/admin/reset-password', $payload)->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->post('/admin/reset-password', $payload)->assertSessionHasErrors('token');

        $this->withSession([
            $loginKey => $oldLogin,
            'security.admin_auth_fingerprint' => $oldFingerprint,
        ]);
        Auth::forgetGuards();
        $this->get('/admin/panel')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');

        $this->post('/admin/login', [
            'email' => $admin->email, 'password' => 'old-admin-password-123',
        ])->assertSessionHasErrors('email');
        $this->post('/admin/login', [
            'email' => $admin->email, 'password' => 'reset-admin-password-123',
        ])->assertRedirect(route('admin.panel'));
        $this->assertAuthenticated('admin');
    }
}
