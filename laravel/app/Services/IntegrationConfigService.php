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

    public function saveSetting(string $key, string $value): void
    {
        DB::table('integration_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['value' => $value, 'updated_at' => now()],
        );
    }

    /** @return array<string,mixed> */
    public function paymentOverview(): array
    {
        $dokuEnvironment = $this->setting('doku_environment')
            ?: trim((string) config('lfamilia.integrations.doku.environment'))
            ?: 'sandbox';
        $midtransEnvironment = $this->setting('midtrans_environment')
            ?: trim((string) config('lfamilia.integrations.midtrans.environment'))
            ?: 'sandbox';

        $dokuEnvironment = in_array($dokuEnvironment, ['sandbox', 'production'], true)
            ? $dokuEnvironment : 'sandbox';
        $midtransEnvironment = in_array($midtransEnvironment, ['sandbox', 'production'], true)
            ? $midtransEnvironment : 'sandbox';

        $configured = [
            'doku' => [
                'sandbox' => $this->paymentProfileReady('doku', 'sandbox'),
                'production' => $this->paymentProfileReady('doku', 'production'),
            ],
            'midtrans' => [
                'sandbox' => $this->paymentProfileReady('midtrans', 'sandbox'),
                'production' => $this->paymentProfileReady('midtrans', 'production'),
            ],
        ];

        $base = rtrim(trim((string) config('lfamilia.public_base_url')), '/');

        return [
            'dokuEnvironment' => $dokuEnvironment,
            'midtransEnvironment' => $midtransEnvironment,
            'walletTopupGateway' => $this->setting('wallet_topup_gateway'),
            'dokuMode' => 'checkout',
            'midtransMode' => 'snap',
            'dokuCheckoutConfigured' => $configured['doku'][$dokuEnvironment],
            'midtransSnapConfigured' => $configured['midtrans'][$midtransEnvironment],
            'configured' => $configured,
            'callbacks' => [
                'dokuNotification' => $base !== '' ? $base.'/api/payments/doku/callback' : null,
                'midtransSnapNotification' => $base !== '' ? $base.'/api/payments/midtrans/snap/notification' : null,
                'paymentReturn' => $base !== '' ? $base.'/payment' : null,
            ],
        ];
    }

    /** @param array<string,string> $values */
    public function savePaymentProfile(
        string $provider,
        string $environment,
        array $values,
    ): void {
        if (!in_array($provider, ['doku', 'midtrans'], true)
            || !in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('Scope kredensial pembayaran tidak valid.');
        }

        $mode = $provider === 'doku' ? 'checkout' : 'snap';
        $allowed = $provider === 'doku'
            ? ['clientId', 'secretKey', 'apiUrl']
            : ['serverKey', 'clientKey'];

        $existing = [];
        try {
            $existing = $this->profile($provider, $mode, $environment);
        } catch (Throwable) {
            $existing = [];
        }

        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new RuntimeException('Field kredensial '.$key.' tidak diizinkan.');
            }
            $value = trim($value);
            if ($value !== '') {
                $existing[$key] = $value;
            }
        }

        if ($provider === 'doku') {
            if (!isset($existing['apiUrl']) || trim($existing['apiUrl']) === '') {
                $existing['apiUrl'] = $environment === 'production'
                    ? 'https://api.doku.com'
                    : 'https://api-sandbox.doku.com';
            }
            if (!$this->validHttpsOrigin((string) $existing['apiUrl'])) {
                throw new RuntimeException('URL API DOKU Checkout harus HTTPS dan valid.');
            }
        }

        $secret = trim((string) config('lfamilia.integration_encryption_key'));
        if (strlen($secret) < 32) {
            throw new RuntimeException('INTEGRATION_ENCRYPTION_KEY belum siap.');
        }

        $encrypted = $this->encrypt($existing, $secret);

        $scope = DB::table('integration_profiles')
            ->where('provider', $provider)
            ->where('mode', $mode)
            ->where('environment', $environment);

        if ($scope->exists()) {
            $scope->update([
                'encrypted_config' => $encrypted,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('integration_profiles')->insert([
                'provider' => $provider,
                'mode' => $mode,
                'environment' => $environment,
                'encrypted_config' => $encrypted,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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

    private function paymentProfileReady(string $provider, string $environment): bool
    {
        try {
            $profile = $this->paymentProfile($provider, $environment);
        } catch (Throwable) {
            return false;
        }

        if ($provider === 'midtrans') {
            return trim((string) ($profile['serverKey'] ?? '')) !== ''
                && trim((string) ($profile['clientKey'] ?? '')) !== '';
        }

        return trim((string) ($profile['clientId'] ?? '')) !== ''
            && trim((string) ($profile['secretKey'] ?? '')) !== ''
            && $this->validHttpsOrigin((string) ($profile['apiUrl'] ?? ''));
    }

    private function validHttpsOrigin(string $value): bool
    {
        $parts = parse_url(trim($value));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
    }

    /** @param array<string,string> $values */
    private function encrypt(array $values, string $secret): string
    {
        $iv = random_bytes(12);
        $key = hash('sha256', $secret, true);
        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Kredensial gagal dienkripsi.');
        }

        return json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'data' => base64_encode($ciphertext.$tag),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
