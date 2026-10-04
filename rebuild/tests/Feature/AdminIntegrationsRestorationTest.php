<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Services\Fulfillment\DigiflazzClient;
use App\Services\IntegrationRuntimeConfig;
use App\Services\Payment\MidtransGateway;
use App\Services\PaymentRoutingService;
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

    public function test_workspace_exposes_environment_readiness_but_never_secret_values(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'midtrans',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => [
                        'server_key' => 'sandbox-server-secret',
                        'client_key' => 'sandbox-client-secret',
                    ],
                    'production' => [
                        'server_key' => 'production-server-secret',
                    ],
                ],
            ],
        ]);

        $response = $this->get('/admin/integrations')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations')
            ->has('integrations', 9)
            ->where('integrations', function ($integrations): bool {
                $midtrans = collect($integrations)->firstWhere('code', 'midtrans');
                $sandbox = collect($midtrans['environment_profiles']['sandbox']['fields'] ?? [])
                    ->firstWhere('key', 'server_key');
                $production = collect($midtrans['environment_profiles']['production']['fields'] ?? [])
                    ->firstWhere('key', 'server_key');

                return $midtrans['environment'] === 'sandbox'
                    && $midtrans['environment_label'] === 'SANDBOX / TEST'
                    && $midtrans['credential_scope'] === 'per_environment'
                    && $sandbox['configured'] === true
                    && $sandbox['value'] === null
                    && $production['configured'] === true
                    && $production['value'] === null;
            })
            ->where('callbackUrls.midtrans', rtrim((string) config('app.url'), '/').'/api/payments/midtrans/notification')
            ->where('callbackUrls.doku', rtrim((string) config('app.url'), '/').'/api/payments/doku/notification')
            ->where('callbackUrls.digiflazz', rtrim((string) config('app.url'), '/').'/api/fulfillment/digiflazz/webhook')
            ->where('callbackUrls.google', rtrim((string) config('app.url'), '/').'/auth/google/callback'));

        $body = $response->getContent();
        $this->assertStringNotContainsString('sandbox-server-secret', $body);
        $this->assertStringNotContainsString('sandbox-client-secret', $body);
        $this->assertStringNotContainsString('production-server-secret', $body);
    }

    public function test_selected_environment_never_falls_back_to_other_midtrans_profile(): void
    {
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'midtrans',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'production',
                'profiles' => [
                    'sandbox' => ['server_key' => 'sandbox-only-secret'],
                ],
            ],
        ]);

        $resolved = app(IntegrationRuntimeConfig::class)->resolve('midtrans');
        $this->assertSame('production', $resolved['environment']);
        $this->assertSame([], $resolved['config']);
        $this->assertFalse(app(PaymentRoutingService::class)->gatewayReady('MIDTRANS'));

        $credential = IntegrationCredential::where('code', 'midtrans')->firstOrFail();
        $credential->config_ciphertext = [
            'environment' => 'sandbox',
            'profiles' => [
                'production' => ['server_key' => 'production-only-secret'],
            ],
        ];
        $credential->save();

        $resolved = app(IntegrationRuntimeConfig::class)->resolve('midtrans');
        $this->assertSame('sandbox', $resolved['environment']);
        $this->assertSame([], $resolved['config']);
        $this->assertFalse(app(PaymentRoutingService::class)->gatewayReady('MIDTRANS'));
    }

    public function test_midtrans_backend_uses_endpoint_and_credential_from_selected_environment(): void
    {
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'midtrans',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => ['server_key' => 'sandbox-key'],
                    'production' => ['server_key' => 'production-key'],
                ],
            ],
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'ORDER-ENV-1',
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'settlement',
            ]),
        ]);

        app(MidtransGateway::class)->status('ORDER-ENV-1');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.sandbox.midtrans.com/')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sandbox-key:')));
    }

    public function test_blank_secret_preserves_selected_profile_and_explicit_clear_removes_it(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'midtrans',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => [
                        'server_key' => 'preserve-me',
                        'client_key' => 'optional-client',
                    ],
                ],
            ],
        ]);

        $this->put('/admin/integrations/midtrans', [
            'is_active' => true,
            'environment' => 'sandbox',
            'config' => ['server_key' => '', 'client_key' => ''],
            'clear_secrets' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $config = IntegrationCredential::where('code', 'midtrans')->firstOrFail()->config_ciphertext;
        $this->assertSame('preserve-me', $config['profiles']['sandbox']['server_key']);
        $this->assertSame('optional-client', $config['profiles']['sandbox']['client_key']);

        $this->put('/admin/integrations/midtrans', [
            'is_active' => false,
            'environment' => 'sandbox',
            'config' => ['server_key' => '', 'client_key' => ''],
            'clear_secrets' => ['server_key'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $config = IntegrationCredential::where('code', 'midtrans')->firstOrFail()->config_ciphertext;
        $this->assertArrayNotHasKey('server_key', $config['profiles']['sandbox']);
        $this->assertSame('optional-client', $config['profiles']['sandbox']['client_key']);
    }

    public function test_switch_to_unconfigured_production_fails_closed_while_active(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'doku',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => ['client_id' => 'sandbox-client', 'secret_key' => 'sandbox-secret'],
                ],
            ],
        ]);

        $this->put('/admin/integrations/doku', [
            'is_active' => true,
            'environment' => 'production',
            'config' => ['client_id' => '', 'secret_key' => ''],
            'clear_secrets' => [],
        ])->assertSessionHasErrors('config.client_id');

        $stored = IntegrationCredential::where('code', 'doku')->firstOrFail()->config_ciphertext;
        $this->assertSame('sandbox', $stored['environment']);
        $this->assertArrayNotHasKey('production', $stored['profiles']);
    }

    public function test_digiflazz_test_mode_changes_request_without_editable_endpoint_or_callback(): void
    {
        $this->clearIntegrationState();
        config(['app.url' => 'https://lfamilia.example.test']);

        IntegrationCredential::create([
            'code' => 'digiflazz',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
                'webhook_secret' => 'hook-secret',
                'base_url' => 'https://digiflazz.fixture.test',
            ],
        ]);

        Http::fake([
            'https://digiflazz.fixture.test/v1/transaction' => Http::response([
                'data' => ['ref_id' => 'REF-TEST-1', 'status' => 'Pending'],
            ]),
        ]);

        app(DigiflazzClient::class)->transact([
            'buyer_sku_code' => 'SKU-1',
            'customer_no' => '12345',
            'ref_id' => 'REF-TEST-1',
            'max_price' => 10000,
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://digiflazz.fixture.test/v1/transaction'
            && $request['testing'] === true
            && $request['cb_url'] === 'https://lfamilia.example.test/api/fulfillment/digiflazz/webhook');
    }

    public function test_regular_admin_cannot_read_or_mutate_integration_credentials(): void
    {
        $admin = AdminUser::create([
            'name' => 'Operational Admin',
            'email' => 'ops-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'ADMIN',
            'permissions' => ['settings.manage', 'payments.manage', 'providers.manage'],
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        $this->get('/admin/integrations')->assertForbidden();
        $this->put('/admin/integrations/midtrans', [
            'is_active' => false,
            'environment' => 'sandbox',
            'config' => [],
        ])->assertForbidden();
        $this->postJson('/admin/integrations/midtrans/test')->assertForbidden();
        $this->postJson('/admin/integrations/midtrans/reveal/server_key', [
            'password' => 'VeryStrongPassword123!',
        ])->assertNotFound();
    }

    public function test_telegram_test_environment_is_separate_and_never_falls_back(): void
    {
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => [
                    'test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat'],
                ],
            ],
        ]);

        $resolved = app(IntegrationRuntimeConfig::class)->resolve('telegram');
        $this->assertSame('test', $resolved['environment']);
        $this->assertSame('111:test-token', $resolved['config']['bot_token']);
        $this->assertStringEndsWith('/test', app(IntegrationRuntimeConfig::class)
            ->telegramBotBase('test', '111:test-token'));

        $credential = IntegrationCredential::where('code', 'telegram')->firstOrFail();
        $credential->config_ciphertext = [
            'environment' => 'production',
            'profiles' => [
                'test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat'],
            ],
        ];
        $credential->save();

        $resolved = app(IntegrationRuntimeConfig::class)->resolve('telegram');
        $this->assertSame('production', $resolved['environment']);
        $this->assertSame([], $resolved['config']);
    }

    public function test_turnstile_profiles_are_isolated_and_storage_is_encrypted(): void
    {
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'turnstile',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => [
                    'test' => ['site_key' => 'test-site', 'secret_key' => 'test-secret'],
                    'production' => ['site_key' => 'prod-site', 'secret_key' => 'prod-secret'],
                ],
            ],
        ]);

        $resolved = app(IntegrationRuntimeConfig::class)->resolve('turnstile');
        $this->assertSame('test-site', $resolved['config']['site_key']);
        $this->assertSame('test-secret', $resolved['config']['secret_key']);

        $raw = (string) DB::table('integration_credentials')
            ->where('code', 'turnstile')->value('config_ciphertext');
        $this->assertStringNotContainsString('test-secret', $raw);
        $this->assertStringNotContainsString('prod-secret', $raw);
    }
}
