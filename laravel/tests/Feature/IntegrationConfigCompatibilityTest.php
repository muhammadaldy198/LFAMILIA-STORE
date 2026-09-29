<?php

namespace Tests\Feature;

use App\Services\IntegrationConfigService;
use App\Services\DigiflazzEndpoint;
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

    public function test_historical_relay_profile_is_preserved_but_hidden_and_not_writable(): void
    {
        DB::table('integration_profiles')->insert([
            'provider' => 'relay', 'mode' => 'service', 'environment' => 'global',
            'encrypted_config' => 'historical-value', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(IntegrationConfigService::class);
        $this->assertFalse(collect($service->integrationOverview()['profiles'])
            ->contains(fn ($item) => $item['provider'] === 'relay'));
        try {
            $service->saveIntegrationProfile('relay', 'service', 'global', ['token' => 'new']);
            $this->fail('Profil relay historis tidak boleh diaktifkan kembali.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Scope integrasi tidak valid.', $error->getMessage());
        }
        $this->assertDatabaseHas('integration_profiles', [
            'provider' => 'relay', 'encrypted_config' => 'historical-value',
        ]);
    }

    public function test_digiflazz_endpoints_reject_relay_and_redirect_targets(): void
    {
        $this->assertSame('https://api.digiflazz.com/v1/transaction',
            DigiflazzEndpoint::requireOfficial('https://api.digiflazz.com/v1/transaction', '/v1/transaction'));
        foreach (['https://digiflazz-relay.lfamiliastore.my.id/v1/transaction',
            'https://api.digiflazz.com:443/v1/transaction',
            'https://api.digiflazz.com/v1/transaction?target=relay'] as $url) {
            try {
                DigiflazzEndpoint::requireOfficial($url, '/v1/transaction');
                $this->fail('URL nonresmi diterima: '.$url);
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('api.digiflazz.com', $error->getMessage());
            }
        }
    }
}
