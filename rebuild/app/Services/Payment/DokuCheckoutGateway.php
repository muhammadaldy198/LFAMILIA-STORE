<?php

namespace App\Services\Payment;

use App\Services\IntegrationRuntimeConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DokuCheckoutGateway
{
    public function __construct(
        private readonly DokuSignature $signature,
        private readonly IntegrationRuntimeConfig $runtime,
    ) {}

    /**
     * Independent Non-SNAP inquiry before accepting payment notifications.
     * A failed inquiry never proves that an order is unpaid.
     *
     * @return array<string, mixed>
     */
    public function status(string $merchantReference): array
    {
        $resolved = $this->runtime->resolve('doku');
        $config = $resolved['config'] ?? [];
        $environment = (string) ($resolved['environment'] ?? '');
        if (! is_array($config) || empty($config['client_id']) || empty($config['secret_key'])
            || $environment !== 'production') {
            throw new RuntimeException('DOKU_STATUS_UNAVAILABLE');
        }

        $path = '/orders/v1/status/'.rawurlencode($merchantReference);
        $requestId = (string) Str::uuid();
        $timestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $clientId = trim((string) $config['client_id']);
        $signature = $this->signature->signGet(
            $clientId, $requestId, $timestamp, $path, (string) $config['secret_key']
        );

        try {
            $response = Http::acceptJson()->withHeaders([
                'Client-Id' => $clientId,
                'Request-Id' => $requestId,
                'Request-Timestamp' => $timestamp,
                'Signature' => $signature,
            ])->connectTimeout(2)->timeout(5)
                ->get($this->runtime->dokuApiBase($environment, $config).$path);
        } catch (\Throwable $exception) {
            throw new RuntimeException('DOKU_STATUS_UNAVAILABLE', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('DOKU_STATUS_UNAVAILABLE');
        }

        $data = $response->json();
        if (! is_array($data)
            || ! is_string(data_get($data, 'order.invoice_number'))
            || ! is_scalar(data_get($data, 'order.amount'))
            || ! is_string(data_get($data, 'transaction.status'))) {
            throw new RuntimeException('DOKU_STATUS_INVALID');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function create(array $context): array
    {
        $resolved = $this->runtime->resolve('doku');
        $config = $resolved['config'] ?? [];
        $environment = (string) ($resolved['environment'] ?? '');
        if (! is_array($config)
            || empty($config['client_id'])
            || empty($config['secret_key'])
            || $environment !== 'production') {
            throw ValidationException::withMessages(['payment' => 'Metode pembayaran sedang tidak tersedia.']);
        }

        $route = $context['route_configuration'];
        $path = (string) ($route['api_path'] ?? '');
        if ($path !== '/checkout/v1/payment') {
            throw ValidationException::withMessages(['payment' => 'Konfigurasi metode pembayaran belum lengkap.']);
        }

        $baseUrl = $this->runtime->dokuApiBase($environment, $config);

        $payload = $this->payload($context);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $requestId = (string) Str::uuid();
        $timestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $clientId = trim((string) $config['client_id']);
        $signature = $this->signature->sign(
            $clientId,
            $requestId,
            $timestamp,
            $path,
            $body,
            (string) $config['secret_key']
        );

        $response = Http::withBody($body, 'application/json')
            ->acceptJson()
            ->withHeaders([
                'Client-Id' => $clientId,
                'Request-Id' => $requestId,
                'Request-Timestamp' => $timestamp,
                'Signature' => $signature,
            ])->timeout(12)->post($baseUrl.$path);

        if ($response->clientError()) {
            throw ValidationException::withMessages([
                'payment' => 'Permintaan pembayaran ditolak. Periksa konfigurasi channel.',
            ]);
        }
        if (! $response->successful()) {
            throw new RuntimeException('DOKU_CREATE_UNCERTAIN');
        }

        $responseTimestamp = (string) $response->header('Response-Timestamp');
        $responseSignature = (string) $response->header('Signature');
        if ($responseTimestamp === '' || $responseSignature === ''
            || ! $this->signature->isFreshTimestamp($responseTimestamp)
            || ! $this->signature->verifyResponse(
                $responseSignature,
                $clientId,
                $requestId,
                $responseTimestamp,
                $path,
                $response->body(),
                (string) $config['secret_key']
            )) {
            throw new RuntimeException('DOKU_RESPONSE_UNVERIFIED');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('DOKU_CREATE_UNCERTAIN');
        }

        // Hosted DOKU Checkout supplies exactly one payment-page URL.
        // Never expose direct-VA instructions through this adapter.
        $checkoutUrl = data_get($data, 'response.payment.url');
        if (! is_string($checkoutUrl) || ! filter_var($checkoutUrl, FILTER_VALIDATE_URL)
            || ! str_starts_with($checkoutUrl, 'https://')) {
            throw new RuntimeException('DOKU_CREATE_UNCERTAIN');
        }
        $public = ['kind' => 'doku_checkout', 'payment_url' => $checkoutUrl];

        return [
            'status' => 'PENDING',
            'external_reference' => $this->firstString([
                data_get($data, 'transaction.id'),
                data_get($data, 'referenceNo'),
                data_get($data, 'order.invoice_number'),
            ]),
            'public_payload' => $public,
            'gateway_payload' => [
                'request_id' => $requestId,
                'response_reference' => $this->firstString([
                    data_get($data, 'transaction.id'),
                    data_get($data, 'referenceNo'),
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function payload(array $context): array
    {
        // DOKU Checkout only. A merchant must never accidentally create a
        // provider-direct VA from an old route or an editor-supplied template.
        $payment = ['payment_due_date' => $context['expires_minutes']];
        $methods = $context['route_configuration']['payment_method_types'] ?? null;
        if (is_array($methods) && $methods !== []) {
            $allowed = ['QRIS'];
            foreach (['payment_bank_channels', 'payment_ewallet_channels', 'payment_retail_channels'] as $catalog) {
                foreach ((array) config($catalog, []) as $entry) {
                    if (! empty($entry['doku']) && empty($entry['requires_integration'])) {
                        $allowed[] = (string) $entry['doku'];
                    }
                }
            }
            if (array_values(array_filter($methods,
                fn ($method): bool => ! is_string($method) || ! in_array($method, $allowed, true)
            )) !== []) {
                throw ValidationException::withMessages([
                    'payment' => 'Kode metode DOKU Checkout tidak didukung oleh katalog LFAMILIA.',
                ]);
            }
            $payment['payment_method_types'] = array_values($methods);
        }

        return [
            'order' => [
                'invoice_number' => $context['merchant_reference'],
                'amount' => $context['amount_idr'],
                'auto_redirect' => ! empty($context['return_url']),
                'callback_url_result' => $context['return_url'] ?? null,
            ],
            'payment' => $payment,
            'customer' => array_filter([
                'name' => $context['customer_name'] ?: 'Customer LFAMILIA',
                'email' => $context['customer_email'],
                'phone' => $context['customer_phone'],
            ]),
        ];
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
