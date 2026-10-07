<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Services\AccountValidationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_trust_fields_are_not_mass_assignable(): void
    {
        $user = new User();

        $this->assertFalse($user->isFillable('email_verified_at'));
        $this->assertFalse($user->isFillable('google_sub'));
    }

    public function test_admin_privilege_fields_are_not_mass_assignable(): void
    {
        $admin = new AdminUser();

        $this->assertFalse($admin->isFillable('role'));
        $this->assertFalse($admin->isFillable('permissions'));
        $this->assertFalse($admin->isFillable('is_active'));
    }

    public function test_account_validation_rejects_local_and_private_endpoints(): void
    {
        foreach (['https://localhost', 'https://127.0.0.1', 'https://10.0.0.1', 'https://169.254.169.254'] as $url) {
            IntegrationCredential::updateOrCreate(
                ['code' => 'kokinpay'],
                [
                    'is_active' => true,
                    'config_ciphertext' => [
                        'api_key' => 'test-secret',
                        'base_url' => $url,
                        'nickname_path' => '/nickname',
                        'region_path' => '/region',
                        'pln_path' => '/pln',
                    ],
                ]
            );

            $this->assertNull(app(AccountValidationConfig::class)->active(), $url.' must be rejected');
        }
    }

    public function test_account_validation_accepts_normal_https_hostname(): void
    {
        IntegrationCredential::create([
            'code' => 'kokinpay',
            'is_active' => true,
            'config_ciphertext' => [
                'api_key' => 'test-secret',
                'base_url' => 'https://validation.example.test',
                'nickname_path' => '/nickname',
                'region_path' => '/region',
                'pln_path' => '/pln',
            ],
        ]);

        $this->assertNotNull(app(AccountValidationConfig::class)->active());
    }
}
