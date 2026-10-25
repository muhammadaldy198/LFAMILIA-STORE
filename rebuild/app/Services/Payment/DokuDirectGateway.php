<?php

namespace App\Services\Payment;

use App\Services\IntegrationRuntimeConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DokuDirectGateway
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
            || ! in_array($environment, ['sandbox', 'production'], true)) {
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
            || ! in_array($environment, ['sandbox', 'production'], true)) {
            throw ValidationException::withMessages(['payment' => 'Metode pembayaran sedang tidak tersedia.']);
        }

        $route = $context['route_configuration'];
        $path = (string) ($route['api_path'] ?? '');
        // Reject legacy hosted Checkout paths and unreviewed provider endpoints.
        if (! preg_match('~^/(?:doku|bca|bni|mandiri|permata|cimb)-virtual-account/v2/payment-code$~', $path)) {
            throw ValidationException::withMessages(['payment' => 'Metode DOKU Direct API belum didukung.']);
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

        $public = ['kind' => 'instructions'];
        foreach ((array) ($route['public_paths'] ?? []) as $name => $dataPath) {
            if (! is_string($name) || ! is_string($dataPath)
                || ! in_array($name, ['payment_code', 'payment_url', 'qr_string', 'va_number'], true)) {
                continue;
            }
            $value = data_get($data, $dataPath);
            if (is_string($value) || is_numeric($value)) {
                $public[$name] = (string) $value;
            }
        }

        if (! isset($public['va_number']) || trim((string) $public['va_number']) === '') {
            throw new RuntimeException('DOKU_DIRECT_VA_MISSING');
        }
        $returnedInvoice = data_get($data, 'order.invoice_number');
        if (is_string($returnedInvoice) && ! hash_equals((string) $context['merchant_reference'], $returnedInvoice)) {
            throw new RuntimeException('DOKU_DIRECT_INVOICE_MISMATCH');
        }

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
        $template = $context['route_configuration']['request_template'] ?? null;
        if (! is_array($template)) {
            $vaInfo = [
                'expired_time' => $context['expires_minutes'],
                'reusable_status' => false,
            ];
            // BNI Non-SNAP uses "biling_type" (provider spelling) and requires
            // a short, unique merchant reference in addition to the invoice.
            $apiPath = (string) ($context['route_configuration']['api_path'] ?? '');
            if ($apiPath === '/bni-virtual-account/v2/payment-code') {
                $vaInfo['billing_type'] = 'FIXED';
                $vaInfo['merchant_unique_reference'] = strtoupper(substr(
                    hash('sha256', (string) $context['merchant_reference']), 0, 12
                ));
            }

            $customerName = (string) ($context['customer_name'] ?: 'Customer LFAMILIA');
            // Each acquiring bank enforces its own customer-name length.
            $customerName = mb_substr($customerName, 0, $apiPath === '/permata-virtual-account/v2/payment-code' ? 20 : 64);

            return [
                'order' => [
                    'invoice_number' => $context['merchant_reference'],
                    'amount' => $context['amount_idr'],
                ],
                'virtual_account_info' => $vaInfo,
                'customer' => array_filter([
                    'name' => $customerName,
                    'email' => $context['customer_email'],
                    'phone' => $context['customer_phone'],
                ]),
            ];
        }

        $replacements = [
            '{{merchant_reference}}' => (string) $context['merchant_reference'],
            '{{amount_idr}}' => (string) $context['amount_idr'],
            '{{customer_name}}' => (string) ($context['customer_name'] ?: 'Customer LFAMILIA'),
            '{{customer_email}}' => (string) ($context['customer_email'] ?? ''),
            '{{customer_phone}}' => (string) ($context['customer_phone'] ?? ''),
            '{{expires_minutes}}' => (string) $context['expires_minutes'],
        ];

        return $this->replaceTemplate($template, $replacements);
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, string>  $replacements
     * @return array<string, mixed>
     */
    private function replaceTemplate(array $value, array $replacements): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $this->replaceTemplate($item, $replacements);
            } elseif (is_string($item) && array_key_exists($item, $replacements)) {
                $replacement = $replacements[$item];
                $result[$key] = in_array($item, ['{{amount_idr}}', '{{expires_minutes}}'], true)
                    ? (int) $replacement : $replacement;
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
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
