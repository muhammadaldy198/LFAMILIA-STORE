<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Services\AdminAuditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityM10Test extends TestCase
{
    use DatabaseTransactions;

    public function test_security_headers_and_correlation_id_are_global(): void
    {
        $response = $this->withHeader('X-Correlation-ID', 'request-security-1234')->get('/');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID', 'request-security-1234')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    public function test_invalid_correlation_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Correlation-ID', '<script>bad</script>')->get('/');

        $value = (string) $response->headers->get('X-Correlation-ID');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $value);
    }

    public function test_turnstile_is_required_for_registration_when_enabled(): void
    {
        IntegrationCredential::create([
            'code' => 'turnstile',
            'config_ciphertext' => ['site_key' => 'site-test', 'secret_key' => 'secret-test'],
            'is_active' => true,
        ]);

        $this->post('/register', [
            'name' => 'Security User',
            'email' => 'security-register@example.test',
            'phone' => '081234567890',
            'password' => 'VeryStrongPassword123!',
            'password_confirmation' => 'VeryStrongPassword123!',
        ])->assertSessionHasErrors('turnstile_token');

        $this->assertDatabaseMissing('users', ['email' => 'security-register@example.test']);
    }

    public function test_valid_turnstile_allows_registration_without_exposing_secret(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true]),
        ]);
        IntegrationCredential::create([
            'code' => 'turnstile',
            'config_ciphertext' => ['site_key' => 'site-public', 'secret_key' => 'secret-private'],
            'is_active' => true,
        ]);

        $this->post('/register', [
            'name' => 'Verified User',
            'email' => 'verified-register@example.test',
            'phone' => '081234567891',
            'password' => 'VeryStrongPassword123!',
            'password_confirmation' => 'VeryStrongPassword123!',
            'turnstile_token' => 'verified-token',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'verified-register@example.test']);

        $page = $this->get('/')->getContent();
        $this->assertStringContainsString('site-public', $page);
        $this->assertStringNotContainsString('secret-private', $page);
    }

    public function test_forgot_password_is_rate_limited_per_identity_and_ip(): void
    {
        IntegrationCredential::where('code', 'turnstile')->delete();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'limited@example.test']);
        }

        $response = $this->post('/forgot-password', ['email' => 'limited@example.test'])
            ->assertStatus(429);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_suspicious_login_requires_turnstile_after_failed_attempts(): void
    {
        IntegrationCredential::create([
            'code' => 'turnstile',
            'config_ciphertext' => ['site_key' => 'site-test', 'secret_key' => 'secret-test'],
            'is_active' => true,
        ]);

        $key = 'suspicious@example.test|127.0.0.1';
        RateLimiter::hit($key, 60);
        RateLimiter::hit($key, 60);
        RateLimiter::hit($key, 60);

        $this->post('/login', [
            'email' => 'suspicious@example.test',
            'password' => 'VeryStrongPassword123!',
        ])->assertSessionHasErrors('turnstile_token');
    }

    public function test_sensitive_admin_routes_are_rate_limited(): void
    {
        $admin = AdminUser::create([
            'name' => 'Security Super',
            'email' => 'security-super@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'SUPER_ADMIN',
            'permissions' => null,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        for ($i = 0; $i < 6; $i++) {
            $this->post('/admin/integrations/turnstile/reveal/secret_key');
        }

        $this->post('/admin/integrations/turnstile/reveal/secret_key')->assertStatus(429);
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
}
