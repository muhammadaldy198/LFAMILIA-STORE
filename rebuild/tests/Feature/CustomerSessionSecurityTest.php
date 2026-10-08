<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerSessionSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Session Security Customer',
            'email' => 'session-security@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('old-password-123'),
        ]);
    }

    private function logIn(): void
    {
        $this->post('/login', [
            'email' => 'session-security@example.test',
            'password' => 'old-password-123',
        ])->assertRedirect();
    }

    public function test_password_change_keeps_current_browser_but_rejects_stale_sessions_and_revokes_api_tokens(): void
    {
        $user = $this->customer();
        $token = $user->createToken('previous-android-login');

        $this->logIn();
        $this->get('/account')->assertOk();
        $previousFingerprint = session('security.customer_auth_fingerprint');
        $this->assertIsString($previousFingerprint);

        $this->put('/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $currentFingerprint = session('security.customer_auth_fingerprint');
        $this->assertIsString($currentFingerprint);
        $this->assertNotSame($previousFingerprint, $currentFingerprint);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->get('/account')->assertOk();

        // Simulate a second device presenting its previously valid session stamp.
        $this->withSession(['security.customer_auth_fingerprint' => $previousFingerprint]);
        $this->get('/account')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_password_reset_expires_previously_authenticated_browser_and_api_tokens(): void
    {
        $user = $this->customer();
        $token = $user->createToken('lost-android-device');

        $this->logIn();
        $this->get('/account')->assertOk();

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'reset-password-123',
            'password_confirmation' => 'reset-password-123',
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertTrue(Hash::check('reset-password-123', $user->fresh()->password));

        // Requests on other devices reload their user from persistent storage.
        Auth::forgetGuards();
        $this->get('/account')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_admin_session_is_unaffected_by_customer_session_stamp(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Session Security Admin',
            'email' => 'session-admin@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'secure-password-123',
        ])->assertRedirect(route('admin.panel'));

        $this->withSession(['security.customer_auth_fingerprint' => 'invalid-customer-stamp']);
        $this->get('/admin/panel')->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
    }

    public function test_expiring_customer_session_does_not_interrupt_admin_logged_into_same_browser(): void
    {
        $customer = $this->customer();
        $this->logIn();
        $this->get('/account')->assertOk();

        $admin = $this->createAdmin([
            'name' => 'Shared Browser Admin',
            'email' => 'shared-browser-admin@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'secure-password-123',
        ])->assertRedirect(route('admin.panel'));

        $this->assertAuthenticatedAs($customer, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');

        // Another device rotated the customer credential in the meantime.
        $this->withSession(['security.customer_auth_fingerprint' => 'stale-customer-stamp']);
        $this->get('/admin/panel')->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');

        // Customer guard is invalidated without destroying the admin guard.
        $this->get('/account')->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->get('/admin/panel')->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_legacy_browser_session_without_a_stamp_must_reauthenticate(): void
    {
        $this->customer();
        $this->logIn();
        $this->get('/account')->assertOk();

        $this->withSession(['security.customer_auth_fingerprint' => null]);
        $this->get('/account')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }
}
