<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetAndGoogleAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_password_reset_email_is_generic_and_secret_is_not_stored_plaintext(): void
    {
        config()->set('lfamilia.public_base_url', 'https://lfamilia.example');
        config()->set('lfamilia.integrations.resend.api_key', 'resend-secret');
        config()->set('lfamilia.integrations.resend.from', 'LFAMILIA <no-reply@lfamilia.example>');
        config()->set('lfamilia.integrations.resend.api_url', 'https://resend.test.invalid/emails');

        $salt = bin2hex(random_bytes(16));
        DB::table('customer_users')->insert([
            'id' => 'customer-reset',
            'email' => 'reset@example.com',
            'name' => 'Reset User',
            'phone' => '+6281234567890',
            'password_hash' => hash_pbkdf2('sha256', 'password-old', hex2bin($salt), 100000, 64, false),
            'password_salt' => $salt,
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://resend.test.invalid/emails' => Http::response(['id' => 'email-1'], 200),
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'reset@example.com',
        ])->assertOk()->assertJson([
            'message' => 'Jika email terdaftar, link reset password akan dikirim ke email tersebut.',
        ]);

        $row = DB::table('customer_password_reset_tokens')->first();
        $this->assertNotNull($row);
        $this->assertSame(64, strlen((string) $row->token_hash));

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://resend.test.invalid/emails'
            && $request->hasHeader('Authorization', 'Bearer resend-secret')
        );
    }

    public function test_google_identity_requires_phone_then_links_customer(): void
    {
        config()->set('lfamilia.integrations.google.client_id', 'google-client-id');
        config()->set('lfamilia.integrations.google.tokeninfo_url', 'https://google.test.invalid/tokeninfo');

        Http::fake([
            'https://google.test.invalid/tokeninfo*' => Http::response([
                'iss' => 'https://accounts.google.com',
                'aud' => 'google-client-id',
                'sub' => 'google-subject-1',
                'email' => 'google@example.com',
                'email_verified' => 'true',
                'name' => 'Google User',
                'picture' => 'https://images.example/avatar.png',
                'exp' => time() + 3600,
            ], 200),
        ]);

        $credential = str_repeat('x', 120);

        $this->postJson('/api/auth/google', [
            'credential' => $credential,
        ])->assertStatus(400)->assertJsonPath('code', 'PHONE_REQUIRED');

        $this->postJson('/api/auth/google', [
            'credential' => $credential,
            'phone' => '081234567890',
        ])->assertOk()->assertJsonPath('customer.email', 'google@example.com');

        $this->assertDatabaseHas('customer_oauth_accounts', [
            'provider' => 'google',
            'provider_subject' => 'google-subject-1',
            'provider_email' => 'google@example.com',
        ]);
        $this->assertDatabaseHas('customer_users', [
            'email' => 'google@example.com',
            'phone' => '+6281234567890',
        ]);
    }
}
