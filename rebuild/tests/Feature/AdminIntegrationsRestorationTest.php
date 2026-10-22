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
        $admin = $this->createAdmin([
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
                    && $midtrans['environment_label'] === 'Masa uji'
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
        $admin = $this->createAdmin([
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

    public function test_safe_probe_success_persists_verified_status_without_exposing_secret(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'midtrans',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => ['server_key' => 'sandbox-midtrans-secret'],
                ],
            ],
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/*' => Http::response([
                'status_code' => '404',
                'status_message' => 'Transaction does not exist.',
            ], 404),
        ]);

        $response = $this->postJson('/admin/integrations/midtrans/test')
            ->assertOk()
            ->assertJson([
                'status' => 'HEALTHY',
                'reason' => 'VERIFIED_SAFE_PROBE',
                'verified' => true,
                'environment' => 'sandbox',
            ]);

        $this->assertStringNotContainsString('sandbox-midtrans-secret', $response->getContent());

        $saved = json_decode((string) DB::table('system_settings')
            ->where('key', 'integration.health.midtrans')->value('value'), true);

        $this->assertSame('HEALTHY', $saved['status']);
        $this->assertTrue($saved['verified']);
        $this->assertSame('VERIFIED_SAFE_PROBE', $saved['reason']);
        $this->assertArrayNotHasKey('server_key', $saved);
    }

    public function test_invalid_credential_and_provider_unavailable_are_distinct(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'midtrans',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => ['server_key' => 'invalid-midtrans-secret'],
                ],
            ],
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/*' => Http::sequence()
                ->push([], 401)
                ->push([], 503),
        ]);

        $this->postJson('/admin/integrations/midtrans/test')
            ->assertOk()
            ->assertJson([
                'status' => 'DOWN',
                'message' => 'Credential tidak valid.',
                'reason' => 'INVALID_CREDENTIAL',
                'verified' => false,
            ]);

        $this->postJson('/admin/integrations/midtrans/test')
            ->assertOk()
            ->assertJson([
                'status' => 'DOWN',
                'reason' => 'PROVIDER_UNAVAILABLE',
                'verified' => false,
            ]);
    }

    public function test_transport_timeout_is_reported_as_provider_unavailable_without_raw_exception(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'resend',
            'is_active' => true,
            'config_ciphertext' => [
                'api_key' => 'resend-timeout-secret',
                'from_email' => 'owner@example.test',
            ],
        ]);

        Http::fake([
            'https://api.resend.com/*' => Http::failedConnection('sensitive transport detail'),
        ]);

        $response = $this->postJson('/admin/integrations/resend/test')
            ->assertOk()
            ->assertJson([
                'status' => 'DOWN',
                'reason' => 'PROVIDER_UNAVAILABLE',
                'verified' => false,
            ]);

        $this->assertStringNotContainsString('sensitive transport detail', $response->getContent());
        $this->assertStringNotContainsString('resend-timeout-secret', $response->getContent());
    }

    public function test_integrations_without_safe_probe_never_claim_connection_verified(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'doku',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => [
                        'client_id' => 'sandbox-client-id',
                        'secret_key' => 'sandbox-doku-secret',
                    ],
                ],
            ],
        ]);

        $this->postJson('/admin/integrations/doku/test')
            ->assertOk()
            ->assertJson([
                'status' => 'DEGRADED',
                'reason' => 'SAFE_PROBE_UNAVAILABLE',
                'verified' => false,
                'environment' => 'sandbox',
            ]);

        Http::assertNothingSent();
    }

    public function test_unavailable_safe_probe_is_configuration_not_connection_failure(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();
        config(['app.url' => 'https://lfamilia.example.test']);
        IntegrationCredential::create([
            'code' => 'doku',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'sandbox',
                'profiles' => [
                    'sandbox' => ['client_id' => 'fixture-client', 'secret_key' => 'fixture-secret'],
                ],
            ],
        ]);
        $this->postJson('/admin/integrations/doku/test')->assertOk();
        $this->get('/admin/integrations')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('integrations', function ($rows): bool {
                $doku = collect($rows)->firstWhere('code', 'doku');

                return $doku['connection']['status'] === 'CONFIGURED'
                    && $doku['health']['verified'] === false
                    && $doku['e2e']['status'] === 'NOT_RECORDED';
            })->where('summary.attention', 0)
        );
        Http::assertNothingSent();
    }

    public function test_callback_readiness_requires_https_route_and_verification_credential(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();
        config(['app.url' => 'https://lfamilia.example.test']);

        IntegrationCredential::create([
            'code' => 'digiflazz',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
            ],
        ]);

        $this->get('/admin/integrations')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('integrations', function ($integrations): bool {
                $digiflazz = collect($integrations)->firstWhere('code', 'digiflazz');

                return $digiflazz['callback']['required'] === true
                    && $digiflazz['callback']['ready'] === false
                    && $digiflazz['callback']['status'] === 'NOT_READY'
                    && $digiflazz['e2e']['status'] === 'NOT_RECORDED';
            })
        );

        $credential = IntegrationCredential::where('code', 'digiflazz')->firstOrFail();
        $config = $credential->config_ciphertext;
        $config['webhook_secret'] = 'callback-secret';
        $credential->config_ciphertext = $config;
        $credential->save();

        $response = $this->get('/admin/integrations')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->where('integrations', function ($integrations): bool {
            $digiflazz = collect($integrations)->firstWhere('code', 'digiflazz');

            return $digiflazz['callback']['ready'] === true
                && $digiflazz['callback']['status'] === 'READY'
                && $digiflazz['callback']['url'] === 'https://lfamilia.example.test/api/fulfillment/digiflazz/webhook';
        }));

        $this->assertStringNotContainsString('callback-secret', $response->getContent());
    }

    public function test_connection_verified_is_false_until_safe_probe_succeeds(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();
        config(['app.url' => 'https://lfamilia.example.test']);

        IntegrationCredential::create([
            'code' => 'resend',
            'is_active' => true,
            'config_ciphertext' => [
                'api_key' => 'resend-secret',
                'from_email' => 'owner@example.test',
            ],
        ]);

        DB::table('system_settings')->insert([
            'key' => 'integration.health.resend',
            'value' => json_encode([
                'status' => 'HEALTHY',
                'message' => 'legacy healthy',
                'tested_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $this->get('/admin/integrations')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('integrations', function ($integrations): bool {
                $resend = collect($integrations)->firstWhere('code', 'resend');

                return $resend['health']['status'] === 'HEALTHY'
                    && $resend['connection']['status'] === 'UNVERIFIED'
                    && $resend['health']['verified'] === false;
            })
        );
    }

    public function test_digiflazz_credentials_are_isolated_between_test_and_production(): void
    {
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'digiflazz',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'production',
                'profiles' => [
                    'test' => [
                        'username' => 'buyer-test',
                        'api_key' => 'development-key-only',
                    ],
                ],
            ],
        ]);

        $resolved = app(IntegrationRuntimeConfig::class)->resolve('digiflazz');
        $this->assertSame('production', $resolved['environment']);
        $this->assertSame([], $resolved['config']);
    }

    public function test_turnstile_test_mode_uses_safe_siteverify_dummy_token(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'turnstile',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => [
                    'test' => [
                        'site_key' => 'test-site-key',
                        'secret_key' => 'test-secret-key',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'localhost',
            ]),
        ]);

        $this->postJson('/admin/integrations/turnstile/test')
            ->assertOk()
            ->assertJson([
                'status' => 'HEALTHY',
                'reason' => 'VERIFIED_SAFE_PROBE',
                'verified' => true,
                'environment' => 'test',
            ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request['secret'] === 'test-secret-key'
                && $request['response'] === 'XXXX.DUMMY.TOKEN.XXXX';
        });
    }

    public function test_turnstile_production_connection_test_never_uses_dummy_token(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'turnstile',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'production',
                'profiles' => [
                    'production' => [
                        'site_key' => 'production-site-key',
                        'secret_key' => 'production-secret-key',
                    ],
                ],
            ],
        ]);

        $this->postJson('/admin/integrations/turnstile/test')
            ->assertOk()
            ->assertJson([
                'status' => 'DEGRADED',
                'reason' => 'SAFE_PROBE_UNAVAILABLE',
                'verified' => false,
                'environment' => 'production',
            ]);

        Http::assertNothingSent();
    }

    public function test_digiflazz_ip_whitelist_failure_is_manual_action_not_invalid_credential(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'digiflazz',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => [
                    'test' => [
                        'username' => 'buyer-test',
                        'api_key' => 'development-key',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://api.digiflazz.com/v1/cek-saldo' => Http::response([
                'data' => [
                    'rc' => '45',
                    'message' => 'IP Anda tidak kami kenali',
                ],
            ]),
        ]);

        $this->postJson('/admin/integrations/digiflazz/test')
            ->assertOk()
            ->assertJson([
                'status' => 'DEGRADED',
                'reason' => 'IP_WHITELIST_REQUIRED',
                'verified' => false,
                'environment' => 'test',
            ]);
    }

    public function test_resend_sending_only_scope_is_not_mislabeled_invalid(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'resend',
            'is_active' => true,
            'config_ciphertext' => [
                'api_key' => 'sending-only-key',
                'from_email' => 'owner@example.test',
            ],
        ]);

        Http::fake([
            'https://api.resend.com/domains*' => Http::response([], 403),
        ]);

        $this->postJson('/admin/integrations/resend/test')
            ->assertOk()
            ->assertJson([
                'status' => 'DEGRADED',
                'reason' => 'PERMISSION_LIMITED',
                'verified' => false,
            ]);

        $this->get('/admin/integrations')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->where('summary.active', 1)
                ->where('summary.attention', 0)
                ->where('summary.awaiting_verification', 1)
        );

        $credential = IntegrationCredential::where('code', 'resend')->firstOrFail();
        $credential->config_ciphertext = ['api_key' => 'sending-only-key'];
        $credential->save();

        $this->get('/admin/integrations')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->where('summary.active', 1)
                ->where('summary.attention', 1)
                ->where('summary.awaiting_verification', 0)
        );
    }

    public function test_discord_per_channel_webhook_must_be_valid_discord_url_on_save(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'discord',
            'is_active' => true,
            'config_ciphertext' => ['webhook_url' => 'https://discord.com/api/webhooks/111/aaa'],
        ]);

        // Bukan-HTTPS / bukan-Discord di field per-channel ditolak —
        // sebelumnya lolos karena applyFields hanya memvalidasi key
        // persis 'webhook_url', lalu delivery diam-diam fallback ke default.
        $this->put('/admin/integrations/discord', [
            'is_active' => true,
            'config' => [
                'webhook_url' => 'https://discord.com/api/webhooks/111/aaa',
                'webhook_url_order' => 'http://not-discord.example/hook',
            ],
            'clear_secrets' => [],
        ])->assertSessionHasErrors('config.webhook_url_order');

        $stored = IntegrationCredential::where('code', 'discord')->firstOrFail()->config_ciphertext;
        $this->assertArrayNotHasKey('webhook_url_order', $stored);

        $this->put('/admin/integrations/discord', [
            'is_active' => true,
            'config' => [
                'webhook_url' => 'https://discord.com/api/webhooks/111/aaa',
                'webhook_url_order' => 'https://discord.com/api/webhooks/222/bbb',
            ],
            'clear_secrets' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $stored = IntegrationCredential::where('code', 'discord')->firstOrFail()->config_ciphertext;
        $this->assertSame('https://discord.com/api/webhooks/222/bbb', $stored['webhook_url_order']);
    }

    public function test_discord_connection_test_probes_every_declared_channel_webhook(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'discord',
            'is_active' => true,
            'config_ciphertext' => [
                'webhook_url' => 'https://discord.com/api/webhooks/111/aaa',
                'webhook_url_order' => 'https://discord.com/api/webhooks/222/bbb',
                'webhook_url_qris' => 'https://discord.com/api/webhooks/111/aaa',
            ],
        ]);

        // Default + QRIS (URL sama, di-probe sekali) OK; ORDER 404
        // (webhook dihapus di Discord) — sebelumnya tes koneksi hanya
        // mengetes default dan melaporkan HEALTHY.
        Http::fake([
            'https://discord.com/api/webhooks/111/aaa' => Http::response(['id' => '111'], 200),
            'https://discord.com/api/webhooks/222/bbb' => Http::response([], 404),
        ]);

        $this->postJson('/admin/integrations/discord/test')
            ->assertOk()
            ->assertJson([
                'verified' => false,
                'status' => 'DEGRADED',
            ])
            ->assertJsonPath('channels.0.channel', 'default')
            ->assertJsonPath('channels.0.verified', true)
            ->assertJsonPath('channels.1.channel', 'order')
            ->assertJsonPath('channels.1.verified', false)
            ->assertJsonPath('channels.2.channel', 'qris')
            ->assertJsonPath('channels.2.verified', true);

        $saved = json_decode((string) DB::table('system_settings')
            ->where('key', 'integration.health.discord')->value('value'), true);
        $this->assertFalse($saved['verified']);
        $this->assertStringContainsString('order', $saved['message']);
    }

    public function test_discord_connection_test_does_not_promise_fallback_when_default_is_broken(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'discord',
            'is_active' => true,
            'config_ciphertext' => [
                'webhook_url' => 'https://discord.com/api/webhooks/111/aaa',
                'webhook_url_order' => 'https://discord.com/api/webhooks/222/bbb',
            ],
        ]);

        Http::fake([
            'https://discord.com/api/webhooks/111/aaa' => Http::response([], 404),
            'https://discord.com/api/webhooks/222/bbb' => Http::response(['id' => '222'], 200),
        ]);

        $response = $this->postJson('/admin/integrations/discord/test')
            ->assertOk()
            ->assertJson(['verified' => false, 'status' => 'DEGRADED'])
            ->assertJsonPath('channels.0.channel', 'default')
            ->assertJsonPath('channels.0.verified', false)
            ->assertJsonPath('channels.1.channel', 'order')
            ->assertJsonPath('channels.1.verified', true);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('tidak dapat dikirim', $message);
        $this->assertStringNotContainsString('jatuh ke webhook default', $message);
    }

    public function test_discord_connection_test_is_bounded_when_webhooks_stall(): void
    {
        $this->loginSuperAdmin();
        $this->clearIntegrationState();

        IntegrationCredential::create([
            'code' => 'discord',
            'is_active' => true,
            'config_ciphertext' => [
                'webhook_url' => 'https://discord.com/api/webhooks/111/aaa',
                'webhook_url_order' => 'https://discord.com/api/webhooks/222/bbb',
            ],
        ]);

        // Kedua endpoint stall 8 detik (simulasi timeout per-probe).
        // TANPA deadline keseluruhan: 2 x 8 = 16 detik. DENGAN
        // deadline 7 detik: probe pertama jalan sampai habis (8 dtk),
        // probe kedua dilewati — total ~8 detik, dan channel kedua
        // TETAP dilaporkan (DEGRADED, PROBE_DEADLINE_EXCEEDED),
        // bukan hilang dari diagnosis.
        Http::fake([
            'https://discord.com/api/webhooks/111/aaa' => function () {
                sleep(8);

                return Http::response(['id' => '111'], 200);
            },
            'https://discord.com/api/webhooks/222/bbb' => function () {
                sleep(8);

                return Http::response(['id' => '222'], 200);
            },
        ]);

        $started = microtime(true);
        $this->postJson('/admin/integrations/discord/test')
            ->assertOk()
            ->assertJson([
                'verified' => false,
                'status' => 'DEGRADED',
            ])
            ->assertJsonPath('channels.0.channel', 'default')
            ->assertJsonPath('channels.0.verified', true)
            ->assertJsonPath('channels.1.channel', 'order')
            ->assertJsonPath('channels.1.verified', false)
            ->assertJsonPath('channels.1.message', 'Webhook Discord tidak sempat di-probe — batas waktu tes koneksi tercapai.');
        $elapsed = microtime(true) - $started;

        // Probe pertama benar-benar jalan (tidur 8 detik), tapi total
        // tetap bounded — jauh di bawah 16 detik sekuensial tanpa
        // deadline, dan di bawah batas ~15 detik (deadline 7 + satu
        // timeout probe 8).
        $this->assertGreaterThanOrEqual(7.0, $elapsed);
        $this->assertLessThan(12.0, $elapsed);
    }
}
