<?php

namespace App\Services\Fulfillment;

use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DigiflazzClient
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function transact(array $request): array
    {
        $config = $this->config();
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.digiflazz.com'), '/');
        if (! str_starts_with(strtolower($baseUrl), 'https://')) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Konfigurasi endpoint provider tidak valid.',
            ]);
        }

        $payload = [
            'username' => (string) $config['username'],
            'buyer_sku_code' => (string) $request['buyer_sku_code'],
            'customer_no' => (string) $request['customer_no'],
            'ref_id' => (string) $request['ref_id'],
            'sign' => md5(
                (string) $config['username'].
                (string) $config['api_key'].
                (string) $request['ref_id']
            ),
            'max_price' => (int) $request['max_price'],
        ];

        if ((bool) ($config['testing'] ?? false)) {
            $payload['testing'] = true;
        }
        if (! empty($config['callback_url'])) {
            $payload['cb_url'] = (string) $config['callback_url'];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->post($baseUrl.'/v1/transaction', $payload);
        } catch (\Throwable $exception) {
            throw new RuntimeException('DIGIFLAZZ_REQUEST_UNCERTAIN', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('DIGIFLAZZ_REQUEST_UNCERTAIN');
        }

        $data = $response->json('data');
        if (! is_array($data)
            || ! isset($data['ref_id'], $data['status'])
            || ! is_scalar($data['ref_id'])
            || ! is_scalar($data['status'])) {
            throw new RuntimeException('DIGIFLAZZ_RESPONSE_UNCERTAIN');
        }

        return $data;
    }

    public function webhookSecret(): string
    {
        $config = $this->config();
        $secret = trim((string) ($config['webhook_secret'] ?? ''));
        if ($secret === '') {
            throw ValidationException::withMessages([
                'fulfillment' => 'Webhook provider belum dikonfigurasi.',
            ]);
        }

        return $secret;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $credential = IntegrationCredential::where('code', 'digiflazz')
            ->where('is_active', true)->first();
        $config = $credential?->config_ciphertext;

        if (! is_array($config)
            || trim((string) ($config['username'] ?? '')) === ''
            || trim((string) ($config['api_key'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'fulfillment' => 'Integrasi provider belum siap.',
            ]);
        }

        return $config;
    }
}
