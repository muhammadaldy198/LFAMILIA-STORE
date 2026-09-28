<?php

namespace Tests\Feature;

use App\Services\IntegrationConfigService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationConfigCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_laravel_can_decrypt_existing_webcrypto_aes_gcm_profile_format(): void
    {
        $secret = str_repeat('s', 32);
        config()->set('lfamilia.integration_encryption_key', $secret);

        $plain = json_encode(['apiKey' => 'legacy-key'], JSON_UNESCAPED_SLASHES);
        $key = hash('sha256', $secret, true);
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        $this->assertNotFalse($ciphertext);

        DB::table('integration_profiles')->insert([
            'provider' => 'kokinpay',
            'mode' => 'service',
            'environment' => 'global',
            'encrypted_config' => json_encode([
                'v' => 1,
                'iv' => base64_encode($iv),
                'data' => base64_encode($ciphertext.$tag),
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(IntegrationConfigService::class);
        $this->assertSame('legacy-key', $service->kokinpayApiKey());
    }
}
