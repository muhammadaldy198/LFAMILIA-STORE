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

    /** @return array{environment:string,username:string,apiKey:string,webhookSecret:string,transactionApiUrl:string,priceListUrl:string} */
    public function digiflazzRuntime(): array
    {
        $environment = $this->setting('digiflazz_environment')
            ?: trim((string) config('lfamilia.integrations.digiflazz.environment'))
            ?: 'development';
        if (!in_array($environment, ['development', 'production'], true)) {
            $environment = 'development';
        }

        $profile = [];
        try {
            $profile = $this->profile('digiflazz', 'direct', $environment);
        } catch (Throwable) {
            $profile = [];
        }

        $production = $environment === 'production';
        return [
            'environment' => $environment,
            'username' => trim((string) ($profile['username'] ?? config('lfamilia.integrations.digiflazz.username'))),
            'apiKey' => trim((string) ($profile['apiKey'] ?? config(
                'lfamilia.integrations.digiflazz.'.($production ? 'production_api_key' : 'development_api_key'),
            ))),
            'webhookSecret' => trim((string) ($profile['webhookSecret'] ?? config('lfamilia.integrations.digiflazz.webhook_secret'))),
            'transactionApiUrl' => trim((string) ($profile['transactionApiUrl'] ?? config(
                'lfamilia.integrations.digiflazz.'.($production ? 'production_transaction_url' : 'development_transaction_url'),
            ))),
            'priceListUrl' => trim((string) ($profile['priceListUrl'] ?? config(
                'lfamilia.integrations.digiflazz.'.($production ? 'production_price_list_url' : 'development_price_list_url'),
            ))),
        ];
    }

    /** @return array{apiKey:string,fromEmail:string,apiUrl:string,deliveryChannel:string} */
    public function resendRuntime(): array
    {
        $profile = [];
        try {
            $profile = $this->profile('resend', 'service', 'global');
        } catch (Throwable) {
            $profile = [];
        }

        return [
            'apiKey' => trim((string) ($profile['apiKey'] ?? config('lfamilia.integrations.resend.api_key'))),
            'fromEmail' => trim((string) ($profile['fromEmail'] ?? config('lfamilia.integrations.resend.from'))),
            'apiUrl' => trim((string) ($profile['apiUrl'] ?? config('lfamilia.integrations.resend.api_url'))),
            'deliveryChannel' => strtolower(trim((string) ($profile['deliveryChannel'] ?? 'website'))) ?: 'website',
        ];
    }

    public function googleClientId(): ?string
    {
        $profile = [];
        try {
            $profile = $this->profile('google', 'service', 'global');
        } catch (Throwable) {
            $profile = [];
        }
        $value = trim((string) ($profile['clientId'] ?? config('lfamilia.integrations.google.client_id')));
        return $value !== '' ? $value : null;
    }

    public function voucherEncryptionKey(): ?string
    {
        $profile = [];
        try {
            $profile = $this->profile('security', 'service', 'global');
        } catch (Throwable) {
            $profile = [];
        }
        $value = trim((string) ($profile['voucherEncryptionKey'] ?? config('lfamilia.integrations.voucher.encryption_key')));
        return strlen($value) >= 32 ? $value : null;
    }

    public function voucherDeliveryChannel(): string
    {
        $resend = $this->resendRuntime();
        return in_array($resend['deliveryChannel'], ['website', 'email'], true)
            ? $resend['deliveryChannel']
            : 'website';
    }

    /** @return array<string,mixed> */
    public function integrationOverview(): array
    {
        $scopes = [
            ['digiflazz', 'direct', 'development'],
            ['digiflazz', 'direct', 'production'],
            ['kokinpay', 'service', 'global'],
            ['google', 'service', 'global'],
            ['resend', 'service', 'global'],
            ['relay', 'service', 'global'],
            ['security', 'service', 'global'],
        ];

        $profiles = [];
        foreach ($scopes as [$provider, $mode, $environment]) {
            $configuredFields = [];
            $decryptionError = false;
            try {
                $values = $this->profile($provider, $mode, $environment);
                $configuredFields = array_values(array_keys(array_filter(
                    $values,
                    static fn ($value) => is_string($value) && trim($value) !== '',
                )));
            } catch (Throwable) {
                $decryptionError = DB::table('integration_profiles')
                    ->where('provider', $provider)
                    ->where('mode', $mode)
                    ->where('environment', $environment)
                    ->exists();
            }

            $profiles[] = [
                'provider' => $provider,
                'mode' => $mode,
                'environment' => $environment,
                'configured' => $configuredFields !== [],
                'configuredFields' => $configuredFields,
                'decryptionError' => $decryptionError,
            ];
        }

        $digiflazzEnvironment = $this->setting('digiflazz_environment')
            ?: trim((string) config('lfamilia.integrations.digiflazz.environment'))
            ?: 'development';
        if (!in_array($digiflazzEnvironment, ['development', 'production'], true)) {
            $digiflazzEnvironment = 'development';
        }

        $base = rtrim(trim((string) config('lfamilia.public_base_url')), '/');

        return [
            'encryptionReady' => strlen(trim((string) config('lfamilia.integration_encryption_key'))) >= 32,
            'encryptionHint' => 'Kredensial sensitif disimpan terenkripsi AES-256-GCM.',
            'selections' => ['digiflazzEnvironment' => $digiflazzEnvironment],
            'profiles' => $profiles,
            'callbacks' => [
                [
                    'id' => 'digiflazz',
                    'label' => 'Digiflazz Webhook URL',
                    'description' => 'Callback fulfillment DigiFlazz.',
                    'url' => $base !== '' ? $base.'/api/fulfillment/digiflazz/callback' : null,
                ],
            ],
        ];
    }

    /** @param array<string,string> $values @param list<string> $clearFields */
    public function saveIntegrationProfile(
        string $provider,
        string $mode,
        string $environment,
        array $values,
        array $clearFields = [],
    ): void {
        $allowedScopes = [
            'digiflazz:direct:development' => ['username','apiKey','webhookSecret','transactionApiUrl','priceListUrl'],
            'digiflazz:direct:production' => ['username','apiKey','webhookSecret','transactionApiUrl','priceListUrl'],
            'kokinpay:service:global' => ['apiKey'],
            'google:service:global' => ['clientId'],
            'resend:service:global' => ['apiKey','fromEmail','apiUrl','deliveryChannel'],
            'relay:service:global' => ['digiflazzOrigin','hosts','token'],
            'security:service:global' => ['voucherEncryptionKey'],
        ];
        $scope = $provider.':'.$mode.':'.$environment;
        $allowed = $allowedScopes[$scope] ?? null;
        if ($allowed === null) {
            throw new RuntimeException('Scope integrasi tidak valid.');
        }

        $existing = [];
        try {
            $existing = $this->profile($provider, $mode, $environment);
        } catch (Throwable) {
            $existing = [];
        }

        foreach ($values as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new RuntimeException('Field kredensial tidak diizinkan.');
            }
            $clean = trim((string) $value);
            if ($clean !== '') {
                $existing[$key] = $clean;
            }
        }
        foreach ($clearFields as $key) {
            if (is_string($key) && in_array($key, $allowed, true)) {
                unset($existing[$key]);
            }
        }

        $secret = trim((string) config('lfamilia.integration_encryption_key'));
        if (strlen($secret) < 32) {
            throw new RuntimeException('INTEGRATION_ENCRYPTION_KEY belum siap.');
        }

        $encrypted = $this->encrypt($existing, $secret);
        $query = DB::table('integration_profiles')
            ->where('provider', $provider)
            ->where('mode', $mode)
            ->where('environment', $environment);

        if ($query->exists()) {
            $query->update(['encrypted_config' => $encrypted, 'updated_at' => now()]);
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
