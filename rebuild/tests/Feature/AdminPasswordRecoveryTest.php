<?php

namespace Tests\Feature;

use App\Models\IntegrationCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPasswordRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_admin_recovery_routes_are_separate_from_customer_recovery(): void
    {
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Login'));
        $this->get('/admin/forgot-password')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/ForgotPassword'));
        $this->get('/admin/reset-password/example-token?email=admin%40example.test')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/ResetPassword')->where('email', 'admin@example.test')->where('token', 'example-token'));

        $this->assertSame(
            'admin_password_reset_tokens',
            config('auth.passwords.admins.table')
        );
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));
    }

    public function test_admin_password_can_be_reset_without_touching_customer_password(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Active Admin',
            'email' => 'shared-reset@example.test',
            'password' => Hash::make('old-admin-password-123'),
            'role' => 'ADMIN',
            'is_active' => true,
        ]);
        $customer = User::create([
            'name' => 'Customer',
            'email' => 'shared-reset@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('old-customer-password-123'),
        ]);

        $this->post('/admin/forgot-password', ['email' => $admin->email])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('admin_password_reset_tokens', ['email' => $admin->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);

        $token = Password::broker('admins')->createToken($admin);
        $this->post('/admin/reset-password', [
            'email' => $admin->email,
            'token' => $token,
            'password' => 'new-admin-password-123',
            'password_confirmation' => 'new-admin-password-123',
        ])->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('new-admin-password-123', $admin->fresh()->password));
        $this->assertFalse(Hash::check('old-admin-password-123', $admin->fresh()->password));
        $this->assertTrue(Hash::check('old-customer-password-123', $customer->fresh()->password));
        $this->assertDatabaseMissing('admin_password_reset_tokens', ['email' => $admin->email]);
    }

    public function test_customer_token_does_not_reset_admin_password_and_unknown_email_is_not_disclosed(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.41']);
        $admin = $this->createAdmin([
            'name' => 'Admin',
            'email' => 'isolation@example.test',
            'password' => Hash::make('old-admin-password-123'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $customer = User::create([
            'name' => 'Customer',
            'email' => 'isolation@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('old-customer-password-123'),
        ]);
        $customerToken = Password::broker('users')->createToken($customer);

        $this->post('/admin/reset-password', [
            'email' => $admin->email,
            'token' => $customerToken,
            'password' => 'attacker-password-123',
            'password_confirmation' => 'attacker-password-123',
        ])->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('old-admin-password-123', $admin->fresh()->password));

        $unknown = $this->post('/admin/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect();
        $known = $this->post('/admin/forgot-password', ['email' => $admin->email])->assertRedirect();
        $this->assertSame($unknown->headers->get('Location'), $known->headers->get('Location'));
        $this->assertDatabaseMissing('admin_password_reset_tokens', ['email' => 'unknown@example.test']);
    }

    public function test_admin_login_and_recovery_require_server_verified_turnstile_from_first_attempt(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42']);
        IntegrationCredential::updateOrCreate(['code' => 'turnstile'], [
            'config_ciphertext' => [
                'site_key' => 'test-site-key',
                'secret_key' => 'test-secret-key',
                'allowed_hostnames' => ['localhost'],
            ],
            'is_active' => true,
        ]);

        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Login')->where('security.turnstile_required', true)->where('security.turnstile_action', 'admin_login'));
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('security.turnstile_required', true)->where('security.turnstile_action', 'customer_login'));
        $this->get('/admin/forgot-password')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/ForgotPassword')->where('security.turnstile_required', true)->where('security.turnstile_action', 'admin_forgot_password'));

        $this->post('/admin/login', [
            'email' => 'test@example.test',
            'password' => 'wrong',
        ])->assertSessionHasErrors('turnstile_token');
        $this->post('/login', [
            'email' => 'test@example.test',
            'password' => 'wrong',
        ])->assertSessionHasErrors('turnstile_token');
        $this->post('/admin/forgot-password', [
            'email' => 'test@example.test',
        ])->assertSessionHasErrors('turnstile_token');

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'action' => 'admin_forgot_password',
                'hostname' => 'localhost',
            ]),
        ]);
        $this->post('/admin/forgot-password', [
            'email' => 'unknown@example.test',
            'turnstile_token' => 'verified-test-token',
        ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'test-secret-key'
            && $request['response'] === 'verified-test-token');
    }
}
