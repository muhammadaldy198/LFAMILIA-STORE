<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class IntegrationConfigService
{
    /** @return array<string,string> */
    public function profile(string $provider, string $mode, string $environment): array
    {
        $row = DB::table('integration_profiles')
            ->where('provider', $provider)
            ->where('mode', $mode)
            ->where('environment', $environment)
            ->first(['encrypted_config']);

        if (!$row) {
            return [];
        }

        $secret = trim((string) config('lfamilia.integration_encryption_key'));
        if (strlen($secret) < 32) {
            throw new RuntimeException('INTEGRATION_ENCRYPTION_KEY belum siap.');
        }

        return $this->decrypt((string) $row->encrypted_config, $secret);
    }

    public function setting(string $key): ?string
    {
        $value = DB::table('integration_settings')->where('setting_key', $key)->value('value');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function kokinpayApiKey(): ?string
    {
        $profile = $this->profile('kokinpay', 'service', 'global');
        if (isset($profile['apiKey']) && trim($profile['apiKey']) !== '') {
            return trim($profile['apiKey']);
        }

        $value = trim((string) config('lfamilia.integrations.kokinpay.api_key'));

        return $value !== '' ? $value : null;
    }

    /** @return array<string,string> */
    public function paymentProfile(string $provider, string $environment): array
    {
        $mode = $provider === 'doku' ? 'checkout' : ($provider === 'midtrans' ? 'snap' : '');
        if ($mode === '') {
            return [];
        }

        return $this->profile($provider, $mode, $environment);
    }

    /** @return array<string,string> */
    private function decrypt(string $encryptedValue, string $secret): array
    {
        try {
            $payload = json_decode($encryptedValue, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || ($payload['v'] ?? null) !== 1) {
                throw new RuntimeException('Format kredensial terenkripsi tidak valid.');
            }

            $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
            $combined = base64_decode((string) ($payload['data'] ?? ''), true);

            if ($iv === false || strlen($iv) !== 12 || $combined === false || strlen($combined) <= 16) {
                throw new RuntimeException('Format kredensial terenkripsi tidak valid.');
            }

            $tag = substr($combined, -16);
            $ciphertext = substr($combined, 0, -16);
            $key = hash('sha256', $secret, true);

            $plain = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
            );

            if ($plain === false) {
                throw new RuntimeException('Kredensial lama tidak dapat dibuka.');
            }

            $decoded = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Kredensial terenkripsi tidak valid.');
            }

            $result = [];
            foreach ($decoded as $keyName => $value) {
                if (is_string($keyName) && is_string($value)) {
                    $result[$keyName] = $value;
                }
            }

            return $result;
        } catch (RuntimeException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new RuntimeException('Kredensial terenkripsi tidak valid.', 0, $error);
        }
    }
}
