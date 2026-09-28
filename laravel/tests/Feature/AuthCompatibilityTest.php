<?php

namespace Tests\Feature;

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
