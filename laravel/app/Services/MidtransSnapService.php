<?php

namespace App\Services;

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

    private function serverKey(string $environment): ?string
    {
        try {
            $profile = $this->integrations->paymentProfile('midtrans', $environment);
            $value = trim((string) ($profile['serverKey'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        } catch (\Throwable) {
            // Fall through to the server environment. This permits a fresh VPS
            // to be configured before encrypted dashboard profiles are imported.
        }

        $configuredEnvironment = trim((string) config('lfamilia.integrations.midtrans.environment'));
        if ($configuredEnvironment !== $environment) {
            return null;
        }

        $value = trim((string) config('lfamilia.integrations.midtrans.server_key'));

        return $value !== '' ? $value : null;
    }
}
