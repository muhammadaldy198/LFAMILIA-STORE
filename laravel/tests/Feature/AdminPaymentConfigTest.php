<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPaymentConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        config()->set('lfamilia.integration_encryption_key', str_repeat('k', 32));
        config()->set('lfamilia.public_base_url', 'https://lfamilia.example');
    }

    public function test_admin_can_choose_wallet_gateway_but_only_owner_can_store_credentials(): void
    {
        $admin = $this->panelToken('pay-admin', 'Pay Admin', 'admin', 'admin-password-123');
        $owner = $this->panelToken('pay-owner', 'Pay Owner', 'super_admin', 'owner-password-123');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->putJson('/api/admin/payment-routing', [
                'action' => 'save_wallet_topup_gateway',
                'walletTopupGateway' => 'midtrans',
            ])
            ->assertOk()
            ->assertJsonPath('overview.walletTopupGateway', 'midtrans');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->putJson('/api/admin/payment-routing', [
                'action' => 'save_profile',
                'provider' => 'midtrans',
                'mode' => 'snap',
                'environment' => 'production',
                'values' => [
                    'serverKey' => 'server-secret',
                    'clientKey' => 'client-key',
                ],
            ])
            ->assertForbidden();

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->putJson('/api/admin/payment-routing', [
                'action' => 'save_profile',
                'provider' => 'midtrans',
                'mode' => 'snap',
                'environment' => 'production',
                'values' => [
                    'serverKey' => 'server-secret',
                    'clientKey' => 'client-key',
                ],
            ])
            ->assertOk();

        $stored = (string) DB::table('integration_profiles')
            ->where('provider', 'midtrans')
            ->where('mode', 'snap')
            ->where('environment', 'production')
            ->value('encrypted_config');

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('server-secret', $stored);
        $this->assertStringNotContainsString('client-key', $stored);
    }

    public function test_admin_can_manage_payment_channel_without_exposing_gateway_secret(): void
    {
        $admin = $this->panelToken('method-admin', 'Method Admin', 'admin', 'admin-password-123');

        DB::table('payment_gateway_settings')->insert([
            'gateway' => 'doku',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/payment-methods', [
                'method' => 'qris',
                'channel' => 'mpm',
                'name' => 'QRIS',
                'description' => 'Scan QRIS',
                'imageUrl' => '',
                'isActive' => true,
                'sortOrder' => 1,
                'gateway' => 'doku',
                'gatewayConfig' => [
                    'customerFeeEnabled' => 'true',
                    'customerFeeBps' => '70',
                    'customerFeeFixed' => '0',
                ],
            ])
            ->assertOk();

        $response = $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->getJson('/api/admin/payment-methods')
            ->assertOk();

        $this->assertSame('qris', $response->json('channels.0.method'));
        $this->assertArrayNotHasKey('secretKey', $response->json('channels.0.gatewayConfig'));
        $this->assertArrayNotHasKey('serverKey', $response->json('channels.0.gatewayConfig'));
    }

    private function panelToken(string $username, string $name, string $role, string $password): string
    {
        $auth = app(AdminAuthService::class);
        $auth->createCredential($username, $name, $password, true);
        DB::table('admin_users')->insert([
            'email' => $username,
            'name' => $name,
            'role' => $role,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $auth->login(
            $username,
            $password,
            $role === 'staff' ? 'staff' : 'backoffice',
        )['token'];
    }
}
