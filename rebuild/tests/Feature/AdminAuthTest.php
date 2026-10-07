<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_admin_uses_a_separate_guard(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Operator',
            'email' => 'admin@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'secure-password-123',
        ])->assertRedirect(route('admin.panel'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
        $this->get('/admin/panel')->assertOk();
        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_inactive_or_unsupported_role_is_rejected(): void
    {
        $this->createAdmin([
            'name' => 'Inactive',
            'email' => 'inactive@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => 'ADMIN',
            'is_active' => false,
        ]);
        $this->createAdmin([
            'name' => 'Unsupported',
            'email' => 'staff@example.test',
            'password' => Hash::make('secure-password-123'),
            'role' => 'STAFF',
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => 'inactive@example.test',
            'password' => 'secure-password-123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('admin');

        $this->post('/admin/login', [
            'email' => 'staff@example.test',
            'password' => 'secure-password-123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }
}
