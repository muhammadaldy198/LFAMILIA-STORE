<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Services\PaymentRoutingService;
use App\Services\WalletTopupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPaymentRestorationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function login(string $role = 'ADMIN', array $permissions = ['payments.manage']): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Payment restoration',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('payment-restoration-only'),
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_payment_workspace_restores_registry_and_hides_legacy_sensitive_route_values(): void
    {
        $this->login();

        $routeId = DB::table('payment_routes')->value('id');
        $this->assertNotNull($routeId);

        DB::table('payment_routes')->where('id', $routeId)->update([
            'configuration' => json_encode([
                'public_value' => 'visible-setting',
                'nested' => [
                    'client_secret' => 'never-render-this-secret',
                    'safe_value' => 'safe-setting',
                ],
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $this->get('/admin/payments')
            ->assertOk()
            ->assertDontSee('never-render-this-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Payments')
                ->has('gateways')
                ->has('channels')
                ->has('routes')
                ->has('transactions.data')
                ->has('summary')
                ->where('walletSettings.topup_enabled', fn ($value) => is_bool($value))
                ->where('routes', function ($routes): bool {
                    return collect($routes)->every(function ($route): bool {
                        $json = json_encode($route['configuration'] ?? [], JSON_THROW_ON_ERROR);

                        return ! str_contains($json, 'client_secret')
                            && ! str_contains($json, 'never-render-this-secret');
                    });
                }));
    }

    public function test_payment_routing_and_channel_configuration_remain_super_admin_only(): void
    {
        $this->login('ADMIN');

        $channel = DB::table('payment_channels')->where('code', 'qris')->firstOrFail();
        $gateway = DB::table('payment_gateways')->where('code', 'MIDTRANS')->firstOrFail();

        $this->put('/admin/payments/channels/'.$channel->id, [
            'code' => 'qris',
            'method' => 'QRIS',
            'name' => 'QRIS',
            'description' => 'Tidak boleh diubah Admin biasa.',
            'fee_flat_idr' => 0,
            'fee_percent_bps' => 70,
            'supports_order' => true,
            'supports_wallet_topup' => true,
            'sort_order' => 10,
            'is_active' => true,
        ])->assertForbidden();

        $this->post('/admin/payments/routes', [
            'payment_channel_id' => $channel->id,
            'payment_gateway_id' => $gateway->id,
            'provider_channel' => 'qris',
            'configuration' => null,
            'priority' => 0,
            'supports_order' => true,
            'supports_wallet_topup' => false,
            'is_active' => true,
        ])->assertForbidden();

        $this->put('/admin/payments/settings', [
            'minimum_topup_idr' => 20000,
            'topup_enabled' => false,
        ])->assertForbidden();
    }

    public function test_super_admin_can_edit_customer_payment_method_and_change_is_audited(): void
    {
        $admin = $this->login('SUPER_ADMIN');
        $channel = DB::table('payment_channels')->where('code', 'qris')->firstOrFail();

        $this->put('/admin/payments/channels/'.$channel->id, [
            'code' => 'qris',
            'method' => 'QRIS',
            'name' => 'QRIS Utama',
            'description' => 'Scan QR dari aplikasi pembayaran.',
            'fee_flat_idr' => 500,
            'fee_percent_bps' => 70,
            'supports_order' => true,
            'supports_wallet_topup' => true,
            'sort_order' => 7,
            'is_active' => true,
            'api_key' => 'must-not-enter-audit',
        ])->assertRedirect();

        $this->assertDatabaseHas('payment_channels', [
            'id' => $channel->id,
            'code' => 'qris',
            'name' => 'QRIS Utama',
            'description' => 'Scan QR dari aplikasi pembayaran.',
            'fee_flat_idr' => 500,
            'fee_percent_bps' => 70,
            'supports_order' => true,
            'supports_wallet_topup' => true,
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $audit = DB::table('audit_logs')
            ->where('actor_type', 'admin_user')
            ->where('actor_id', (string) $admin->id)
            ->where('action', 'payment.channel.updated')
            ->where('target_id', (string) $channel->id)
            ->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('must-not-enter-audit', (string) $audit->after);
    }

    public function test_order_and_wallet_topup_can_route_same_method_to_different_gateways(): void
    {
        DB::table('payment_channels')->where('code', 'qris')->update([
            'is_active' => true,
            'supports_order' => true,
            'supports_wallet_topup' => true,
            'updated_at' => now(),
        ]);
        DB::table('payment_gateways')->whereIn('code', ['MIDTRANS', 'DOKU'])->update([
            'is_active' => true,
            'is_maintenance' => false,
            'updated_at' => now(),
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'config_ciphertext' => ['client_id' => 'client-test', 'secret_key' => 'secret-test'],
            'is_active' => true,
        ]);

        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $midtransId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        $dokuId = DB::table('payment_gateways')->where('code', 'DOKU')->value('id');

        DB::table('payment_routes')->insert([
            [
                'payment_channel_id' => $channelId,
                'payment_gateway_id' => $midtransId,
                'provider_channel' => 'qris',
                'configuration' => null,
                'priority' => 0,
                'supports_order' => true,
                'supports_wallet_topup' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_channel_id' => $channelId,
                'payment_gateway_id' => $dokuId,
                'provider_channel' => 'qris',
                'configuration' => null,
                'priority' => 0,
                'supports_order' => false,
                'supports_wallet_topup' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $routing = app(PaymentRoutingService::class);

        $this->assertSame('MIDTRANS', $routing->resolve('qris', false, 'order')['gateway_code']);
        $this->assertSame('DOKU', $routing->resolve('qris', false, 'topup')['gateway_code']);

        $publicOrder = collect($routing->publicOrderChannels(null))->firstWhere('code', 'qris');
        $publicTopup = collect($routing->publicTopupChannels())->firstWhere('code', 'qris');

        $this->assertIsArray($publicOrder);
        $this->assertIsArray($publicTopup);
        $this->assertArrayNotHasKey('gateway_code', $publicOrder);
        $this->assertArrayNotHasKey('gateway_code', $publicTopup);
    }

    public function test_sensitive_route_configuration_is_rejected(): void
    {
        $this->login('SUPER_ADMIN');

        $channel = DB::table('payment_channels')->where('code', 'qris')->firstOrFail();
        $gateway = DB::table('payment_gateways')->where('code', 'MIDTRANS')->firstOrFail();

        $this->from('/admin/payments')->post('/admin/payments/routes', [
            'payment_channel_id' => $channel->id,
            'payment_gateway_id' => $gateway->id,
            'provider_channel' => 'qris',
            'configuration' => json_encode([
                'nested' => ['client_secret' => 'do-not-store'],
            ], JSON_THROW_ON_ERROR),
            'priority' => 0,
            'supports_order' => true,
            'supports_wallet_topup' => false,
            'is_active' => true,
        ])->assertRedirect('/admin/payments')
            ->assertSessionHasErrors(['configuration']);

        $this->assertSame(0, DB::table('payment_routes')
            ->where('payment_channel_id', $channel->id)
            ->where('payment_gateway_id', $gateway->id)
            ->count());
    }

    public function test_wallet_topup_master_switch_is_enforced_by_backend(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'wallet.topup_enabled'],
            [
                'value' => json_encode(false),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->expectException(ValidationException::class);
        app(WalletTopupService::class)->quote(10000, 'qris');
    }
}
