<?php

namespace App\Services\Payment;

use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MidtransGateway
{
    /**
     * @return array<string, mixed>
     */
    public function status(string $merchantReference): array
    {
        $credential = IntegrationCredential::where('code', 'midtrans')->where('is_active', true)->first();
        $config = $credential?->config_ciphertext;
        if (! is_array($config) || empty($config['server_key'])) {
            throw new RuntimeException('MIDTRANS_STATUS_UNAVAILABLE');
        }

        $production = (bool) ($config['is_production'] ?? false);
        $baseUrl = $production
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';

        try {
            $response = Http::acceptJson()
                ->withBasicAuth((string) $config['server_key'], '')
                ->connectTimeout(2)
                ->timeout(4)
                ->get($baseUrl.'/v2/'.rawurlencode($merchantReference).'/status');
        } catch (\Throwable $exception) {
            throw new RuntimeException('MIDTRANS_STATUS_UNAVAILABLE', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('MIDTRANS_STATUS_UNAVAILABLE');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('MIDTRANS_STATUS_UNAVAILABLE');
        }

        foreach (['order_id', 'status_code', 'gross_amount', 'transaction_status'] as $key) {
            if (! isset($data[$key]) || ! is_scalar($data[$key])) {
                throw new RuntimeException('MIDTRANS_STATUS_UNAVAILABLE');
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function create(array $context): array
    {
        $credential = IntegrationCredential::where('code', 'midtrans')->where('is_active', true)->first();
        $config = $credential?->config_ciphertext;
        if (! is_array($config) || empty($config['server_key'])) {
            throw ValidationException::withMessages(['payment' => 'Metode pembayaran sedang tidak tersedia.']);
        }

        $production = (bool) ($config['is_production'] ?? false);
        $url = $production
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $payload = [
            'transaction_details' => [
                'order_id' => $context['merchant_reference'],
                'gross_amount' => $context['amount_idr'],
            ],
            'customer_details' => array_filter([
                'first_name' => $context['customer_name'] ?: 'Customer LFAMILIA',
                'email' => $context['customer_email'],
                'phone' => $context['customer_phone'],
            ]),
        ];

        $enabled = $context['route_configuration']['enabled_payments'] ?? null;
        if (is_array($enabled) && $enabled !== []) {
            $payload['enabled_payments'] = array_values(array_filter($enabled, 'is_string'));
        } elseif (! empty($context['provider_channel'])) {
            $payload['enabled_payments'] = [(string) $context['provider_channel']];
        }

        $response = Http::acceptJson()
            ->withBasicAuth((string) $config['server_key'], '')
            ->timeout(12)
            ->post($url, $payload);

        if ($response->clientError()) {
            throw ValidationException::withMessages([
                'payment' => 'Permintaan pembayaran ditolak. Periksa konfigurasi channel.',
            ]);
        }
        if (! $response->successful()) {
            throw new RuntimeException('MIDTRANS_CREATE_UNCERTAIN');
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['token']) || empty($data['redirect_url'])) {
            throw new RuntimeException('MIDTRANS_CREATE_UNCERTAIN');
        }

        return [
            'status' => 'PENDING',
            'external_reference' => null,
            'public_payload' => [
                'kind' => 'redirect',
                'redirect_url' => (string) $data['redirect_url'],
                'token' => (string) $data['token'],
            ],
            'gateway_payload' => [
                'token' => (string) $data['token'],
                'redirect_url' => (string) $data['redirect_url'],
            ],
        ];
    }
}
