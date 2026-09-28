<?php

namespace App\Services;

class DokuCheckoutService
{
    public function __construct(private readonly IntegrationConfigService $integrations)
    {
    }

    /** @return array{referenceId:string,amount:int,originalRequestId:?string,status:string} */
    public function parseNotification(array $payload): array
    {
        $order = isset($payload['order']) && is_array($payload['order']) ? $payload['order'] : [];
        $transaction = isset($payload['transaction']) && is_array($payload['transaction'])
            ? $payload['transaction']
            : [];

        $transactionStatus = strtoupper(trim((string) ($transaction['status'] ?? '')));
        $orderStatus = strtoupper(trim((string) ($order['status'] ?? '')));

        $status = 'pending';
        if ($transactionStatus === 'SUCCESS') {
            $status = 'paid';
        } elseif ($transactionStatus === 'EXPIRED' || $orderStatus === 'ORDER_EXPIRED') {
            $status = 'expired';
        }

        return [
            'referenceId' => trim((string) ($order['invoice_number'] ?? '')),
            'amount' => is_numeric($order['amount'] ?? null) ? (int) $order['amount'] : 0,
            'originalRequestId' => isset($transaction['original_request_id'])
                ? trim((string) $transaction['original_request_id'])
                : null,
            'status' => $status,
        ];
    }

    public function validateNotification(
        string $rawBody,
        string $requestTarget,
        ?string $clientId,
        ?string $requestId,
        ?string $requestTimestamp,
        ?string $receivedSignature,
        string $environment,
    ): bool {
        if (!$clientId || !$requestId || !$requestTimestamp || !$receivedSignature) {
            return false;
        }

        $config = $this->credentials($environment);
        if (!$config) {
            return false;
        }

        if (!hash_equals($config['clientId'], trim($clientId))) {
            return false;
        }

        $digest = base64_encode(hash('sha256', $rawBody, true));
        $components = [
            'Client-Id:'.$config['clientId'],
            'Request-Id:'.trim($requestId),
            'Request-Timestamp:'.trim($requestTimestamp),
            'Request-Target:'.$requestTarget,
            'Digest:'.$digest,
        ];

        $expected = 'HMACSHA256='.base64_encode(hash_hmac(
            'sha256',
            implode("\n", $components),
            $config['secretKey'],
            true,
        ));

        return hash_equals($expected, trim($receivedSignature));
    }

    /** @return array{clientId:string,secretKey:string}|null */
    private function credentials(string $environment): ?array
    {
        try {
            $profile = $this->integrations->paymentProfile('doku', $environment);
            $clientId = trim((string) ($profile['clientId'] ?? ''));
            $secretKey = trim((string) ($profile['secretKey'] ?? ''));
            if ($clientId !== '' && $secretKey !== '') {
                return ['clientId' => $clientId, 'secretKey' => $secretKey];
            }
        } catch (\Throwable) {
            // Fall through to explicit server environment configuration.
        }

        $configuredEnvironment = trim((string) config('lfamilia.integrations.doku.environment'));
        if ($configuredEnvironment !== $environment) {
            return null;
        }

        $clientId = trim((string) config('lfamilia.integrations.doku.client_id'));
        $secretKey = trim((string) config('lfamilia.integrations.doku.secret_key'));

        return ($clientId !== '' && $secretKey !== '')
            ? ['clientId' => $clientId, 'secretKey' => $secretKey]
            : null;
    }
}
