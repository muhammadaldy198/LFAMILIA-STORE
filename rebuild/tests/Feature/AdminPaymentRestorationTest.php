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
        $admin = $this->createAdmin([
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
                    return collect($routes)->every(fn ($route): bool => ! array_key_exists('configuration', $route)
                        && ! array_key_exists('provider_channel', $route)
                    );
                }));
    }

    public function test_payment_workspace_loads_gateway_and_channel_route_details_in_one_query(): void
    {
        $this->login();

        $routeQueries = [];
        DB::listen(function ($query) use (&$routeQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from `payment_routes` as `routes`')
                && str_contains($sql, 'join `payment_gateways` as `gateways`')
                && ! str_contains($sql, 'join `payment_channels` as `channels`')) {
                $routeQueries[] = $sql;
            }
        });

        $this->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Payments')
                ->has('gateways')
                ->has('channels')
                ->has('routes'));

        // Additional gateways or channels must not multiply this lookup.
        $this->assertCount(1, $routeQueries);
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

        $route = DB::table('payment_routes')->firstOrFail();
        $this->put('/admin/payments/routes/'.$route->id, [
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
            'fee_flat_idr' => 0,
            'fee_percent_bps' => 0,
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

    public function test_super_admin_can_configure_route_specific_fees_without_exposing_gateway_secrets(): void
    {
        $this->login('SUPER_ADMIN');
        $channel = DB::table('payment_channels')->where('code', 'virtual_account')->firstOrFail();
        DB::table('payment_channels')->where('id', $channel->id)
            ->update(['supports_wallet_topup' => true, 'supports_order' => true]);
        $route = DB::table('payment_routes')
            ->where('payment_channel_id', $channel->id)->firstOrFail();

        $this->put('/admin/payments/routes/'.$route->id, [
            'priority' => 10,
            'fee_flat_idr' => 4440,
            'fee_percent_bps' => 25,
            'supports_order' => true,
            'supports_wallet_topup' => true,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('payment_routes', [
            'id' => $route->id, 'fee_flat_idr' => 4440, 'fee_percent_bps' => 25,
        ]);
        $this->get('/admin/payments')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Payments')
                ->where('routes', fn ($routes): bool => collect($routes)
                    ->contains(fn ($row): bool => (int) $row['id'] === (int) $route->id
                        && $row['fee_flat_idr'] === 4440
                        && $row['fee_percent_bps'] === 25
                        && ! array_key_exists('configuration', $row))));
    }

    public function test_order_and_wallet_topup_can_route_same_method_to_different_gateways(): void
    {
        DB::table('payment_channels')->where('code', 'virtual_account')->update([
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

        $channelId = DB::table('payment_channels')->where('code', 'virtual_account')->value('id');
        $midtransId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        $dokuId = DB::table('payment_gateways')->where('code', 'DOKU')->value('id');

        DB::table('payment_routes')
            ->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', $midtransId)
            ->update([
                'priority' => 0,
                'supports_order' => true,
                'supports_wallet_topup' => false,
                'is_active' => true,
                'updated_at' => now(),
            ]);
        DB::table('payment_routes')
            ->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', $dokuId)
            ->update([
                'priority' => 0,
                'supports_order' => false,
                'supports_wallet_topup' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);

        $routing = app(PaymentRoutingService::class);

        $this->assertSame('MIDTRANS', $routing->resolve('virtual_account', false, 'order')['gateway_code']);
        $this->assertSame('DOKU', $routing->resolve('virtual_account', false, 'topup')['gateway_code']);

        $publicOrder = collect($routing->publicOrderChannels(null))->firstWhere('code', 'virtual_account');
        $publicTopup = collect($routing->publicTopupChannels())->firstWhere('code', 'virtual_account');

        $this->assertIsArray($publicOrder);
        $this->assertIsArray($publicTopup);
        $this->assertArrayNotHasKey('gateway_code', $publicOrder);
        $this->assertArrayNotHasKey('gateway_code', $publicTopup);
    }

    public function test_admin_payment_topup_readiness_requires_gateway_credentials(): void
    {
        $this->login('SUPER_ADMIN');

        DB::table('payment_channels')->where('code', 'virtual_account')->update([
            'is_active' => true,
            'supports_wallet_topup' => true,
            'updated_at' => now(),
        ]);
        $gateway = DB::table('payment_gateways')->where('code', 'DOKU')->firstOrFail();
        DB::table('payment_gateways')->where('id', $gateway->id)->update([
            'is_active' => true,
            'is_maintenance' => false,
            'updated_at' => now(),
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'config_ciphertext' => [],
            'is_active' => true,
        ]);

        $channelId = DB::table('payment_channels')->where('code', 'virtual_account')->value('id');
        DB::table('payment_routes')
            ->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', $gateway->id)
            ->update([
                'supports_wallet_topup' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);

        $this->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('channels', function ($channels): bool {
                    $channel = collect($channels)->firstWhere('code', 'virtual_account');

                    return is_array($channel) && $channel['available_topup'] === false;
                }));
    }

    public function test_sync_restores_repo_owned_route_protocol_without_overwriting_operational_state(): void
    {
        $this->login('SUPER_ADMIN');

        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $gatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        $route = DB::table('payment_routes')
            ->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', $gatewayId)
            ->firstOrFail();

        DB::table('payment_routes')->where('id', $route->id)->update([
            'provider_channel' => 'tampered',
            'configuration' => json_encode(['enabled_payments' => ['credit_card']], JSON_THROW_ON_ERROR),
            'priority' => 77,
            'supports_order' => false,
            'supports_wallet_topup' => true,
            'is_active' => true,
            'updated_at' => now(),
        ]);

        $this->post('/admin/payments/channels/sync')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $saved = DB::table('payment_routes')->where('id', $route->id)->firstOrFail();
        $this->assertNull($saved->provider_channel);
        $this->assertSame(
            ['enabled_payments' => ['other_qris']],
            json_decode((string) $saved->configuration, true, 512, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(77, (int) $saved->priority);
        $this->assertFalse((bool) $saved->supports_order);
        $this->assertTrue((bool) $saved->supports_wallet_topup);
        $this->assertTrue((bool) $saved->is_active);
    }

    public function test_super_admin_cannot_edit_payment_protocol_fields_from_panel(): void
    {
        $this->login('SUPER_ADMIN');

        $route = DB::table('payment_routes')->firstOrFail();
        DB::table('payment_routes')->where('id', $route->id)->update([
            'provider_channel' => 'repo-owned-channel',
            'configuration' => json_encode(['repo_owned' => true], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $this->from('/admin/payments')->put('/admin/payments/routes/'.$route->id, [
            'provider_channel' => 'admin-overwrite-attempt',
            'configuration' => json_encode(['client_secret' => 'must-never-be-written'], JSON_THROW_ON_ERROR),
            'priority' => 7,
            'supports_order' => true,
            'supports_wallet_topup' => false,
            'is_active' => true,
        ])->assertRedirect('/admin/payments')
            ->assertSessionHasNoErrors();

        $saved = DB::table('payment_routes')->where('id', $route->id)->firstOrFail();
        $this->assertSame('repo-owned-channel', $saved->provider_channel);
        $this->assertSame(['repo_owned' => true], json_decode((string) $saved->configuration, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(7, (int) $saved->priority);

        $this->post('/admin/payments/routes', [
            'payment_channel_id' => $route->payment_channel_id,
            'payment_gateway_id' => $route->payment_gateway_id,
        ])->assertNotFound();

        $this->delete('/admin/payments/routes/'.$route->id)->assertStatus(405);
    }

    public function test_gateway_cannot_be_enabled_with_incomplete_credentials(): void
    {
        $this->login('SUPER_ADMIN');

        $gateway = DB::table('payment_gateways')->where('code', 'MIDTRANS')->firstOrFail();
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => [],
            'is_active' => true,
        ]);

        $this->from('/admin/payments')->put('/admin/payments/gateways/'.$gateway->id, [
            'internal_name' => $gateway->internal_name,
            'sort_order' => (int) $gateway->sort_order,
            'is_active' => true,
            'is_maintenance' => false,
        ])->assertRedirect('/admin/payments')
            ->assertSessionHasErrors(['is_active']);

        $this->assertFalse((bool) DB::table('payment_gateways')->where('id', $gateway->id)->value('is_active'));

        $credential = IntegrationCredential::where('code', 'midtrans')->firstOrFail();
        $credential->forceFill([
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ])->save();

        $this->from('/admin/payments')->put('/admin/payments/gateways/'.$gateway->id, [
            'internal_name' => $gateway->internal_name,
            'sort_order' => (int) $gateway->sort_order,
            'is_active' => true,
            'is_maintenance' => false,
        ])->assertRedirect('/admin/payments')
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) DB::table('payment_gateways')->where('id', $gateway->id)->value('is_active'));
    }

    public function test_corrupt_gateway_ciphertext_fails_closed_instead_of_crashing(): void
    {
        $this->login('SUPER_ADMIN');

        DB::table('integration_credentials')->updateOrInsert(
            ['code' => 'midtrans'],
            [
                'config_ciphertext' => 'not-a-valid-encrypted-payload',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $gateway = DB::table('payment_gateways')->where('code', 'MIDTRANS')->firstOrFail();
        $this->from('/admin/payments')->put('/admin/payments/gateways/'.$gateway->id, [
            'internal_name' => $gateway->internal_name,
            'sort_order' => (int) $gateway->sort_order,
            'is_active' => true,
            'is_maintenance' => false,
        ])->assertRedirect('/admin/payments')
            ->assertSessionHasErrors(['is_active']);

        $this->assertFalse((bool) DB::table('payment_gateways')->where('id', $gateway->id)->value('is_active'));
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
