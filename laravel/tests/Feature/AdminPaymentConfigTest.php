<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use App\Services\PaymentChannelService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_fee_uses_only_selected_mode_and_sync_preserves_admin_routing(): void
    {
        $fees = app(PaymentChannelService::class);
        $this->assertSame(5264, $fees->customerFee(100000, [
            'customerFeeEnabled' => 'true', 'customerFeeMode' => 'percent',
            'customerFeeBps' => '500', 'customerFeeFixed' => '9999',
        ]));
        $this->assertSame(9999, $fees->customerFee(100000, [
            'customerFeeEnabled' => 'true', 'customerFeeMode' => 'fixed',
            'customerFeeBps' => '500', 'customerFeeFixed' => '9999',
        ]));

        $admin = $this->panelToken('sync-admin', 'Sync Admin', 'admin', 'admin-password-123');
        DB::table('payment_channels')->insert([
            'method' => 'qris', 'channel' => 'mpm', 'name' => 'QRIS custom',
            'description' => 'Routing dipilih Admin', 'is_active' => 1,
            'sort_order' => 30, 'gateway' => 'midtrans',
            'gateway_config_json' => json_encode(['customerFeeMode' => 'fixed', 'customerFeeFixed' => '1500']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/payment-methods', [
                'action' => 'sync', 'gateways' => ['doku', 'midtrans'],
            ])
            ->assertOk()
            ->assertJsonPath('mappingIssues', []);
        $this->assertDatabaseHas('payment_channels', [
            'method' => 'qris', 'channel' => 'mpm', 'gateway' => 'midtrans',
            'name' => 'QRIS custom', 'is_active' => 1,
        ]);
    }

    public function test_midtrans_readiness_uses_integration_profile_and_official_environment_url(): void
    {
        $owner = $this->panelToken('ready-owner', 'Ready Owner', 'super_admin', 'owner-password-123');
        $cookie = AdminAuthService::COOKIE.'='.rawurlencode($owner);

        $this->withHeader('Cookie', $cookie)->putJson('/api/admin/payment-routing', [
            'action' => 'save_profile', 'provider' => 'midtrans',
            'mode' => 'snap', 'environment' => 'production',
            'values' => ['serverKey' => 'server-key', 'clientKey' => 'client-key'],
        ])->assertOk();
        $this->withHeader('Cookie', $cookie)->putJson('/api/admin/payment-routing', [
            'action' => 'save_modes', 'dokuEnvironment' => 'sandbox',
            'midtransEnvironment' => 'production',
        ])->assertOk();
        DB::table('payment_channels')->insert([
            'method' => 'qris', 'channel' => 'mpm', 'name' => 'QRIS',
            'description' => 'QRIS', 'is_active' => 1, 'sort_order' => 0,
            'gateway' => 'midtrans', 'gateway_config_json' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Cookie', $cookie)
            ->getJson('/api/admin/payment-methods')
            ->assertOk()
            ->assertJsonPath('gatewayReadiness.midtrans.ready', true)
            ->assertJsonPath('channels.0.readiness.ready', true);
    }

    public function test_wallet_topup_history_includes_customer_whatsapp_fee_and_total(): void
    {
        $owner = $this->panelToken('wallet-owner', 'Wallet Owner', 'super_admin', 'owner-password-123');
        $customerId = (string) Str::uuid();
        DB::table('customer_users')->insert([
            'id' => $customerId, 'email' => 'wallet@example.com',
            'name' => 'Wallet Customer', 'phone' => '+6281234567890',
            'password_hash' => str_repeat('a', 64), 'password_salt' => str_repeat('b', 32),
            'balance' => 0, 'leaderboard_opt_in' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('wallet_topups')->insert([
            'id' => (string) Str::uuid(), 'customer_id' => $customerId,
            'amount' => 100000, 'sender_name' => 'Wallet Customer',
            'payment_method' => 'qris', 'proof_url' => '',
            'source' => 'gateway', 'reference_id' => 'WALLET-REF-123',
            'payment_gateway' => 'midtrans', 'gateway_payment_name' => 'QRIS',
            'payment_fee' => 1500, 'payment_total' => 101500,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->getJson('/api/admin/wallet')
            ->assertOk()
            ->assertJsonPath('topups.0.reference_id', 'WALLET-REF-123')
            ->assertJsonPath('topups.0.customer_name', 'Wallet Customer')
            ->assertJsonPath('topups.0.customer_phone', '+6281234567890')
            ->assertJsonPath('topups.0.payment_gateway', 'midtrans')
            ->assertJsonPath('topups.0.amount', 100000)
            ->assertJsonPath('topups.0.payment_fee', 1500)
            ->assertJsonPath('topups.0.payment_total', 101500)
            ->assertJsonPath('topups.0.status', 'pending');
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
