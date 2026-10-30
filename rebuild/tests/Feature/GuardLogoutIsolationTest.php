<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuardLogoutIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    /** @return array{User, AdminUser} */
    private function logInBoth(): array
    {
        $customer = User::create([
            'name' => 'Shared Browser Customer',
            'email' => 'guard-isolation-customer@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('customer-password-123'),
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $admin = $this->createAdmin([
            'name' => 'Shared Browser Admin',
            'email' => 'guard-isolation-admin@example.test',
            'password' => Hash::make('admin-password-123'),
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->post('/login', [
            'email' => $customer->email,
            'password' => 'customer-password-123',
        ])->assertRedirect('/account');
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'admin-password-123',
        ])->assertRedirect(route('admin.panel'));

        $this->assertAuthenticatedAs($customer, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertTrue(session()->has(Auth::guard('web')->getName()));
        $this->assertTrue(session()->has(Auth::guard('admin')->getName()));

        return [$customer, $admin];
    }

    public function test_logging_out_customer_keeps_admin_authenticated_in_same_browser(): void
    {
        [, $admin] = $this->logInBoth();
        $sessionId = session()->getId();
        $csrf = session()->token();

        $this->post('/logout')->assertRedirect();

        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->assertTrue(session()->has(Auth::guard('admin')->getName()));
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->get('/admin/panel')->assertOk();
    }

    public function test_logging_out_admin_keeps_customer_authenticated_in_same_browser(): void
    {
        [$customer] = $this->logInBoth();
        $this->get('/account')->assertOk();
        $sessionId = session()->getId();
        $csrf = session()->token();

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($customer, 'web');
        $this->assertFalse(session()->has(Auth::guard('admin')->getName()));
        $this->assertTrue(session()->has(Auth::guard('web')->getName()));
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->get('/account')->assertOk();
        $this->get('/admin/panel')->assertRedirect(route('admin.login'));
    }

    public function test_admin_confirmation_cannot_be_reused_after_another_admin_logs_in(): void
    {
        [, $firstAdmin] = $this->logInBoth();

        $this->post('/admin/password/confirm', [
            'password' => 'admin-password-123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($firstAdmin, 'admin');
        $this->assertTrue(session()->has('admin.password_confirmed_at'));

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->assertFalse(session()->has('admin.password_confirmed_at'));

        $secondAdmin = $this->createAdmin([
            'name' => 'Second Browser Admin',
            'email' => 'guard-isolation-second-admin@example.test',
            'password' => Hash::make('another-admin-password-123'),
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => $secondAdmin->email,
            'password' => 'another-admin-password-123',
        ])->assertRedirect(route('admin.panel'));

        $this->assertAuthenticatedAs($secondAdmin, 'admin');
        $this->assertFalse(session()->has('admin.password_confirmed_at'));
        $this->get('/admin/password/confirm')->assertOk();
    }

    public function test_customer_confirmation_cannot_be_reused_after_customer_logout(): void
    {
        [$firstCustomer, $admin] = $this->logInBoth();

        $this->post('/user/confirm-password', [
            'password' => 'customer-password-123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($firstCustomer, 'web');
        $this->assertTrue(session()->has('auth.password_confirmed_at'));
        $this->post('/logout')->assertRedirect();
        $this->assertFalse(session()->has('auth.password_confirmed_at'));
        $this->assertAuthenticatedAs($admin, 'admin');

        $secondCustomer = User::create([
            'name' => 'Second Browser Customer',
            'email' => 'guard-isolation-second-customer@example.test',
            'phone' => '081234567892',
            'password' => Hash::make('another-customer-password-123'),
        ]);
        $secondCustomer->forceFill(['email_verified_at' => now()])->save();

        $this->post('/login', [
            'email' => $secondCustomer->email,
            'password' => 'another-customer-password-123',
        ])->assertRedirect('/account');

        $this->assertAuthenticatedAs($secondCustomer, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get('/user/confirm-password')->assertOk();
    }

    public function test_customer_registration_does_not_end_an_existing_admin_session(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Registration Admin',
            'email' => 'registration-admin@example.test',
            'password' => Hash::make('admin-password-123'),
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'admin-password-123',
        ])->assertRedirect(route('admin.panel'));

        $this->post('/register', [
            'name' => 'New Customer',
            'email' => 'registration-customer@example.test',
            'phone' => '081234567891',
            'password' => 'customer-password-123',
            'password_confirmation' => 'customer-password-123',
        ])->assertRedirect(route('login'));

        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get('/admin/panel')->assertOk();
    }
}
