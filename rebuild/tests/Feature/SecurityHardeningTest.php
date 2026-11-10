<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_trust_fields_are_not_mass_assignable(): void
    {
        $user = new User;

        $this->assertFalse($user->isFillable('email_verified_at'));
        $this->assertFalse($user->isFillable('google_sub'));
    }

    public function test_admin_privilege_fields_are_not_mass_assignable(): void
    {
        $admin = new AdminUser;

        $this->assertFalse($admin->isFillable('role'));
        $this->assertFalse($admin->isFillable('permissions'));
        $this->assertFalse($admin->isFillable('is_active'));
    }

    // The SSRF endpoint guard that used to live in AccountValidationConfig
    // was deleted together with that class and the retired kokinpay
    // integration (owner's order, 11 Nov 2026): no code path resolves an
    // admin-entered validation endpoint any more.
}
