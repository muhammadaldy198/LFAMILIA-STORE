<?php

namespace App\Services\Fulfillment;

use App\Services\IntegrationRuntimeConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DigiflazzClient
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function transact(array $request): array
    {
        $resolved = $this->resolved();
        $config = $resolved['config'];
        $environment = $resolved['environment'];
        $baseUrl = $this->runtime->digiflazzApiBase($config);

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
            'cb_url' => rtrim((string) config('app.url'), '/').'/api/fulfillment/digiflazz/webhook',
        ];

        if ($environment === 'test') {
            $payload['testing'] = true;
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
        $resolved = $this->resolved();
        $secret = trim((string) ($resolved['config']['webhook_secret'] ?? ''));
        if ($secret === '') {
            throw ValidationException::withMessages([
                'fulfillment' => 'Webhook provider belum dikonfigurasi.',
            ]);
        }

        return $secret;
    }

    /**
     * @return array{environment:string,config:array<string,mixed>}
     */
    private function resolved(): array
    {
        $resolved = $this->runtime->resolve('digiflazz');
        $config = $resolved['config'] ?? [];
        $environment = (string) ($resolved['environment'] ?? '');

        if (! is_array($config)
            || ! in_array($environment, ['test', 'production'], true)
            || trim((string) ($config['username'] ?? '')) === ''
            || trim((string) ($config['api_key'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'fulfillment' => 'Integrasi provider belum siap.',
            ]);
        }

        return ['environment' => $environment, 'config' => $config];
    }
}
