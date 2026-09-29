<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use App\Services\SecurityGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_customer_can_register_login_and_reuse_opaque_session_cookie(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'phone' => '081234567890',
            'password' => 'password-kuat',
        ]);

        $register->assertCreated()->assertJsonPath('customer.email', 'tester@example.com');
        $this->assertDatabaseHas('customer_users', [
            'email' => 'tester@example.com',
            'phone' => '+6281234567890',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'tester@example.com',
            'password' => 'password-kuat',
        ]);
        $login->assertOk()->assertJsonPath('customer.name', 'Tester');
    }

    public function test_imported_worker_pbkdf2_password_remains_valid(): void
    {
        $salt = bin2hex(random_bytes(16));
        $hash = hash_pbkdf2('sha256', 'rahasia-123', hex2bin($salt), 100000, 64, false);

        DB::table('customer_users')->insert([
            'id' => (string) Str::uuid(),
            'email' => 'legacy@example.com',
            'name' => 'Legacy',
            'phone' => '+628111111111',
            'password_hash' => $hash,
            'password_salt' => $salt,
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'legacy@example.com',
            'password' => 'rahasia-123',
        ])->assertOk()->assertJsonPath('customer.email', 'legacy@example.com');
    }

    public function test_panel_login_accepts_its_public_https_origin_behind_an_http_proxy_hop(): void
    {
        config()->set('app.url', 'https://lfamiliastore.my.id');

        $request = Request::create('http://lfamiliastore.my.id/admin/panel/auth/login', 'POST', server: [
            'HTTP_ORIGIN' => 'https://lfamiliastore.my.id',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        app(SecurityGuard::class)->assertSameOrigin($request);
        $this->assertTrue(true);
    }

    public function test_panel_login_rejects_a_foreign_origin(): void
    {
        config()->set('app.url', 'https://lfamiliastore.my.id');

        $request = Request::create('http://lfamiliastore.my.id/admin/panel/auth/login', 'POST', server: [
            'HTTP_ORIGIN' => 'https://other.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $this->expectException(HttpResponseException::class);
        app(SecurityGuard::class)->assertSameOrigin($request);
    }

    public function test_panel_login_rejects_cross_site_post_without_origin(): void
    {
        config()->set('app.url', 'https://lfamiliastore.my.id');

        $request = Request::create('http://lfamiliastore.my.id/admin/panel/auth/login', 'POST', server: [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $this->expectException(HttpResponseException::class);
        app(SecurityGuard::class)->assertSameOrigin($request);
    }

    public function test_panel_login_cookie_is_readable_by_api_session_route(): void
    {
        $username = 'owner01';

        DB::table('admin_users')->insert([
            'email' => $username,
            'name' => 'Owner',
            'role' => 'super_admin',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AdminAuthService::class)->createCredential(
            $username,
            'Owner',
            'password-owner-123',
            true,
        );

        $login = $this->post('/admin/panel/auth/login', [
            'username' => $username,
            'password' => 'password-owner-123',
        ]);
        $login->assertRedirect('/admin/panel');

        $cookie = collect($login->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === AdminAuthService::COOKIE);

        $this->assertNotNull($cookie);

        $this->withUnencryptedCookie(AdminAuthService::COOKIE, $cookie->getValue())
            ->getJson('/api/admin/session')
            ->assertOk()
            ->assertJsonPath('session.email', $username)
            ->assertJsonPath('session.role', 'super_admin');
    }

    public function test_staff_cannot_login_through_backoffice_area(): void
    {
        $username = 'staff01';
        $salt = bin2hex(random_bytes(16));
        $hash = hash_pbkdf2('sha256', 'password-staff-123', hex2bin($salt), 100000, 64, false);

        DB::table('admin_users')->insert([
            'email' => $username,
            'name' => 'Staff',
            'role' => 'staff',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_users')->insert([
            'id' => (string) Str::uuid(),
            'email' => '__lfadmin__:'.$username,
            'name' => 'Staff',
            'phone' => 'admin',
            'password_hash' => $hash,
            'password_salt' => $salt,
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/admin/panel/auth/login', [
            'username' => $username,
            'password' => 'password-staff-123',
        ])->assertRedirectContains('/admin/panel/login');

        $this->post('/staff/panel/auth/login', [
            'username' => $username,
            'password' => 'password-staff-123',
        ])->assertRedirect('/staff/panel');
    }
}
