<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Exceptions\MidtransTransactionNotFoundException;
use RuntimeException;

class MidtransSnapService
{
    public function __construct(private readonly IntegrationConfigService $integrations)
    {
    }

    public function verifyNotification(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $signature,
        string $environment,
    ): bool {
        $serverKey = $this->serverKey($environment);
        if ($serverKey === null || trim($signature) === '') {
            return false;
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        return hash_equals(strtolower($expected), strtolower(trim($signature)));
    }

    public function mapStatus(string $transactionStatus, ?string $fraudStatus): string
    {
        $status = strtolower(trim($transactionStatus));
        $fraud = strtolower(trim((string) $fraudStatus));

        if (in_array($status, ['settlement', 'capture'], true)) {
            if ($fraud === 'deny') {
                return 'failed';
            }
            if ($fraud !== '' && $fraud !== 'accept') {
                return 'pending';
            }

            return 'paid';
        }

        if (in_array($status, ['pending', 'authorize'], true)) {
            return 'pending';
        }
        if ($status === 'expire') {
            return 'expired';
        }
        if (in_array($status, ['cancel', 'deny', 'failure'], true)) {
            return 'failed';
        }

        return 'ignore';
    }

    /** @return array{status:string,amount:int,transactionId:string,raw:array<string,mixed>} */
    public function queryStatus(string $orderId, string $environment): array
    {
        $serverKey = $this->serverKey($environment);
        if (!$serverKey) {
            throw new RuntimeException('Server Key Midtrans belum tersedia untuk environment transaksi.');
        }

        $configuredEnvironment = $this->integrations->setting('midtrans_environment') ?: 'sandbox';
        $apiOrigin = trim((string) config('lfamilia.integrations.midtrans.api_base_url'));
        if ($configuredEnvironment !== $environment || !$this->validHttpsOrigin($apiOrigin)) {
            throw new RuntimeException('URL API Midtrans belum dikonfigurasi untuk environment transaksi.');
        }

        $response = Http::acceptJson()
            ->withBasicAuth($serverKey, '')
            ->timeout(15)
            ->get(rtrim($apiOrigin, '/').'/v2/'.rawurlencode($orderId).'/status');

        $raw = $response->json();
        if (!is_array($raw)) {
            $raw = [];
        }

        if (!$response->successful()) {
            $message = trim((string) ($raw['status_message'] ?? ''));
            if ($response->status() === 404) {
                throw new MidtransTransactionNotFoundException(
                    $message ?: 'Transaksi Midtrans tidak ditemukan.',
                );
            }
            throw new RuntimeException($message ?: 'Midtrans belum dapat mengembalikan status transaksi.');
        }

        $responseOrderId = trim((string) ($raw['order_id'] ?? ''));
        if ($responseOrderId !== '' && !hash_equals($orderId, $responseOrderId)) {
            throw new RuntimeException('Order ID dari Midtrans tidak sesuai.');
        }

        $amount = is_numeric($raw['gross_amount'] ?? null)
            ? (int) round((float) $raw['gross_amount'])
            : 0;

        return [
            'status' => $this->mapStatus(
                (string) ($raw['transaction_status'] ?? ''),
                isset($raw['fraud_status']) ? (string) $raw['fraud_status'] : null,
            ),
            'amount' => $amount,
            'transactionId' => trim((string) ($raw['transaction_id'] ?? '')),
            'raw' => $raw,
        ];
    }

    private function validHttpsOrigin(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
    }

    private function serverKey(string $environment): ?string
    {
        try {
            $profile = $this->integrations->paymentProfile('midtrans', $environment);
            $value = trim((string) ($profile['serverKey'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}