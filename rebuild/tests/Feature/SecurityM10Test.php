<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\LoginRiskService;
use App\Services\Payment\DokuSignature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityM10Test extends TestCase
{
    use DatabaseTransactions;

    private function enableTurnstile(array $extra = []): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'turnstile'], [
            'config_ciphertext' => [
                'site_key' => 'site-key-test',
                'secret_key' => 'turnstile-secret-test',
                ...$extra,
            ],
            'is_active' => true,
        ]);
    }

    public function test_security_headers_correlation_id_and_sensitive_cache_policy_are_applied(): void
    {
        $response = $this->withHeader('X-Request-ID', 'security-test-request-001')->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Request-ID', 'security-test-request-001');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $unsafe = $this->withHeader('X-Request-ID', "bad\nheader")->get('/login');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/',
            (string) $unsafe->headers->get('X-Request-ID')
        );
    }

    public function test_trusted_host_guard_rejects_unconfigured_host(): void
    {
        config(['lfamilia.trusted_hosts' => ['lfamiliastore.my.id']]);

        $this->withServerVariables(['HTTP_HOST' => 'evil.example'])
            ->get('/login')
            ->assertBadRequest();
    }

    public function test_session_security_defaults_keep_24_hour_lifetime_and_encryption(): void
    {
        $this->assertSame(1440, config('session.lifetime'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_turnstile_secret_is_encrypted_and_never_shared_to_register_page(): void
    {
        $this->enableTurnstile(['allowed_hostnames' => ['localhost']]);

        $raw = (string) DB::table('integration_credentials')
            ->where('code', 'turnstile')->value('config_ciphertext');
        $this->assertStringNotContainsString('turnstile-secret-test', $raw);

        $response = $this->get('/register')->assertOk();
        $response->assertSee('site-key-test');
        $response->assertDontSee('turnstile-secret-test');
    }

    public function test_register_requires_server_verified_turnstile_when_enabled(): void
    {
        Queue::fake();
        $this->enableTurnstile();

        $payload = [
            'name' => 'Secure User',
            'email' => 'secure-register@example.test',
            'phone' => '081234567890',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ];

        $this->post('/register', $payload)
            ->assertSessionHasErrors('turnstile_token');
        $this->assertDatabaseMissing('users', ['email' => 'secure-register@example.test']);

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'action' => 'register',
                'hostname' => 'localhost',
            ]),
        ]);

        $this->post('/register', [
            ...$payload,
            'turnstile_token' => 'valid-turnstile-token',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'secure-register@example.test']);
        Http::assertSent(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'turnstile-secret-test'
            && $request['response'] === 'valid-turnstile-token');
    }

    public function test_turnstile_rejects_wrong_action_and_hostname(): void
    {
        $this->enableTurnstile(['allowed_hostnames' => ['lfamiliastore.my.id']]);

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'action' => 'forgot_password',
                'hostname' => 'evil.example',
            ]),
        ]);

        $this->post('/register', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.test',
            'phone' => '081234567890',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'turnstile_token' => 'wrong-context-token',
        ])->assertSessionHasErrors('turnstile_token');

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
    }

    public function test_suspicious_customer_login_requires_turnstile_after_three_failures(): void
    {
        $this->enableTurnstile();
        User::create([
            'name' => 'Login User',
            'email' => 'login-risk@example.test',
            'phone' => '081234567891',
            'password' => Hash::make('CorrectPassword123!'),
            'membership_tier_code' => 'BASIC',
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/login', [
                'email' => 'login-risk@example.test',
                'password' => 'WrongPassword123!',
            ])->assertSessionHasErrors('email');
        }

        $this->assertTrue(app(LoginRiskService::class)->requiresChallenge(
            'customer',
            '127.0.0.1',
            'login-risk@example.test'
        ));

        $this->post('/login', [
            'email' => 'login-risk@example.test',
            'password' => 'CorrectPassword123!',
        ])->assertSessionHasErrors('turnstile_token');

        $this->assertGuest();
        app(LoginRiskService::class)->clear('customer', '127.0.0.1', 'login-risk@example.test');
    }

    public function test_register_rate_limit_is_recorded_even_when_turnstile_is_missing(): void
    {
        $this->enableTurnstile();
        $email = 'rate-register@example.test';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/register', [
                'name' => 'Rate User',
                'email' => $email,
                'phone' => '081234567892',
                'password' => 'SecurePassword123!',
                'password_confirmation' => 'SecurePassword123!',
            ]);
        }

        $key = 'public-abuse:register:'.hash('sha256', '127.0.0.1|'.$email);
        $this->assertTrue(RateLimiter::tooManyAttempts($key, 5));
    }

    public function test_sensitive_admin_audit_uses_request_correlation_id(): void
    {
        $admin = tap(AdminUser::create([
            'name' => 'Correlation Super',
            'email' => 'correlation-super@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
        ]), fn ($admin) => $admin->forceFill([
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ])->save());
        $this->actingAs($admin, 'admin');

        $this->withHeader('X-Request-ID', 'm10-correlation-request-001')
            ->put('/admin/integrations/discord', [
                'is_active' => false,
                'config' => ['webhook_url' => ''],
            ])->assertRedirect();

        $this->assertSame(
            'm10-correlation-request-001',
            DB::table('audit_logs')->where('action', 'integration.updated')
                ->latest('id')->value('correlation_id')
        );
    }

    public function test_secret_reveal_endpoint_is_not_exposed(): void
    {
        $admin = tap(AdminUser::create([
            'name' => 'Super Security',
            'email' => 'super-security@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
        ]), fn ($admin) => $admin->forceFill([
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ])->save());
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'midtrans-secret-test'],
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        $this->postJson('/admin/integrations/midtrans/reveal/server_key', [
            'password' => 'wrong-password',
        ])->assertNotFound();

        $this->postJson('/admin/integrations/midtrans/reveal/server_key', [
            'password' => 'VeryStrongPassword123!',
        ])->assertNotFound();
    }

    public function test_audit_redacts_nested_sensitive_fields(): void
    {
        $redacted = app(AdminAuditService::class)->redact([
            'password_confirmation' => 'never-log-this',
            'authorization' => 'Bearer abc',
            'nested' => [
                'payment_signature' => 'signature-value',
                'credential_blob' => 'credential-value',
                'safe' => 'visible',
            ],
        ]);

        $this->assertSame('[REDACTED]', $redacted['password_confirmation']);
        $this->assertSame('[REDACTED]', $redacted['authorization']);
        $this->assertSame('[REDACTED]', $redacted['nested']['payment_signature']);
        $this->assertSame('[REDACTED]', $redacted['nested']['credential_blob']);
        $this->assertSame('visible', $redacted['nested']['safe']);
    }

    public function test_doku_rejects_stale_signed_callback_before_state_lookup(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'config_ciphertext' => [
                'client_id' => 'MCH-SECURITY',
                'secret_key' => 'doku-security-secret',
            ],
            'is_active' => true,
        ]);

        $payload = [
            'transaction' => ['status' => 'SUCCESS'],
            'order' => ['invoice_number' => 'stale-order', 'amount' => 10000],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = now('UTC')->subMinutes(10)->format('Y-m-d\TH:i:s\Z');
        $requestId = 'stale-doku-request-001';
        $signature = app(DokuSignature::class)->sign(
            'MCH-SECURITY',
            $requestId,
            $timestamp,
            '/api/payments/doku/notification',
            $raw,
            'doku-security-secret'
        );

        $this->call('POST', '/api/payments/doku/notification', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CLIENT_ID' => 'MCH-SECURITY',
            'HTTP_REQUEST_ID' => $requestId,
            'HTTP_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_SIGNATURE' => $signature,
        ], $raw)->assertUnauthorized();

        $this->assertSame(0, DB::table('payment_callbacks')->count());
    }
}
