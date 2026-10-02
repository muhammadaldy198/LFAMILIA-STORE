<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminIntegrationsRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function loginSuperAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Integration Owner',
            'email' => 'integration-owner-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'SUPER_ADMIN',
            'permissions' => null,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function clearIntegrationState(): void
    {
        IntegrationCredential::query()->delete();
        DB::table('system_settings')->where('key', 'like', 'integration.health.%')->delete();
    }

    public function test_workspace_shows_saved_health_and_never_exposes_secret_values(): void
    {
        $admin = $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'digiflazz',
            'is_active' => true,
            'config_ciphertext' => [
                'username' => 'buyer-menu16',
                'api_key' => 'menu16-secret-api-key',
                'base_url' => 'https://digiflazz.test',
            ],
        ]);

        DB::table('system_settings')->insert([
            'key' => 'integration.health.digiflazz',
            'value' => json_encode([
                'status' => 'HEALTHY',
                'message' => 'Koneksi terverifikasi.',
                'tested_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'version' => 1,
            'updated_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/admin/integrations')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations')
            ->has('integrations', 9)
            ->where('summary.total', 9)
            ->where('summary.active', 1)
            ->where('summary.healthy', 1)
            ->where('summary.attention', 0)
            ->where('integrations.0.code', 'digiflazz')
            ->where('integrations.0.group', 'Provider')
            ->where('integrations.0.required_complete', true)
            ->where('integrations.0.health.status', 'HEALTHY')
            ->where('integrations.0.fields.1.key', 'api_key')
            ->where('integrations.0.fields.1.value', null)
            ->where('integrations.0.fields.1.configured', true)
            ->where('callbackUrls.midtrans', config('app.url').'/api/payments/midtrans/notification')
            ->where('callbackUrls.doku', config('app.url').'/api/payments/doku/notification')
            ->where('callbackUrls.digiflazz', config('app.url').'/api/fulfillment/digiflazz/webhook')
            ->where('callbackUrls.google', config('app.url').'/auth/google/callback'));

        $this->assertStringNotContainsString('menu16-secret-api-key', $response->getContent());
    }

    public function test_update_preserves_blank_secrets_and_unknown_legacy_metadata(): void
    {
        $admin = $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'midtrans',
            'is_active' => true,
            'config_ciphertext' => [
                'server_key' => 'server-secret-menu16',
                'client_key' => 'client-secret-menu16',
                'is_production' => false,
                'legacy_environment' => 'sandbox-v1',
                'legacy_secret_token' => 'legacy-secret-menu16',
            ],
        ]);

        $this->put('/admin/integrations/midtrans', [
            'is_active' => true,
            'config' => [
                'server_key' => '',
                'client_key' => '',
                'is_production' => true,
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $config = IntegrationCredential::where('code', 'midtrans')->firstOrFail()->config_ciphertext;

        $this->assertSame('server-secret-menu16', $config['server_key']);
        $this->assertSame('client-secret-menu16', $config['client_key']);
        $this->assertTrue($config['is_production']);
        $this->assertSame('sandbox-v1', $config['legacy_environment']);
        $this->assertSame('legacy-secret-menu16', $config['legacy_secret_token']);

        $audit = DB::table('audit_logs')
            ->where('actor_id', (string) $admin->id)
            ->where('action', 'integration.updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('server-secret-menu16', (string) $audit->after);
        $this->assertStringNotContainsString('client-secret-menu16', (string) $audit->after);
        $this->assertStringNotContainsString('legacy-secret-menu16', (string) $audit->after);
    }

    public function test_activation_requires_declared_fields_and_validates_operator_inputs(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        $this->put('/admin/integrations/kokinpay', [
            'is_active' => true,
            'config' => [
                'api_key' => 'nickname-secret',
                'base_url' => '',
                'nickname_path' => '',
                'region_path' => '',
                'pln_path' => '',
            ],
        ])->assertSessionHasErrors([
            'config.base_url',
        ]);

        $this->put('/admin/integrations/discord', [
            'is_active' => true,
            'config' => [
                'webhook_url' => 'http://discord.example/webhook',
            ],
        ])->assertSessionHasErrors('config.webhook_url');

        $this->put('/admin/integrations/turnstile', [
            'is_active' => false,
            'config' => [
                'site_key' => 'site-key',
                'secret_key' => 'secret-key',
                'allowed_hostnames' => 'https://lfamiliastore.my.id/path',
            ],
        ])->assertSessionHasErrors('config.allowed_hostnames');

        $this->put('/admin/integrations/turnstile', [
            'is_active' => false,
            'config' => [
                'site_key' => 'site-key',
                'secret_key' => 'secret-key',
                'allowed_hostnames' => '*.lfamiliastore.my.id',
            ],
        ])->assertSessionHasErrors('config.allowed_hostnames');
    }

    public function test_connection_check_returns_timestamp_and_persists_health_without_secret_data(): void
    {
        $admin = $this->loginSuperAdmin();
        $this->clearIntegrationState();

        Http::fake([
            'https://digiflazz.menu16/v1/cek-saldo' => Http::response([
                'data' => ['deposit' => 500000],
            ]),
        ]);

        IntegrationCredential::create([
            'code' => 'digiflazz',
            'is_active' => true,
            'config_ciphertext' => [
                'username' => 'buyer-menu16',
                'api_key' => 'test-connection-secret',
                'base_url' => 'https://digiflazz.menu16',
            ],
        ]);

        $response = $this->postJson('/admin/integrations/digiflazz/test')
            ->assertOk()
            ->assertJsonPath('status', 'HEALTHY');

        $this->assertNotEmpty($response->json('tested_at'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('test-connection-secret', $response->getContent());

        $health = json_decode((string) DB::table('system_settings')
            ->where('key', 'integration.health.digiflazz')
            ->value('value'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('HEALTHY', $health['status']);
        $this->assertNotEmpty($health['tested_at']);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => (string) $admin->id,
            'action' => 'integration.connection.tested',
            'target_type' => 'integration_credential',
            'target_id' => 'digiflazz',
        ]);
    }

    public function test_connection_check_reports_not_configured_before_calling_provider(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();
        Http::fake();

        IntegrationCredential::create([
            'code' => 'doku',
            'is_active' => false,
            'config_ciphertext' => [
                'client_id' => 'merchant-only',
            ],
        ]);

        $this->postJson('/admin/integrations/doku/test')
            ->assertOk()
            ->assertJsonPath('status', 'NOT_CONFIGURED');

        Http::assertNothingSent();
    }
}
