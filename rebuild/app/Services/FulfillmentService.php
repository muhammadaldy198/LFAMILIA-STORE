<?php

namespace App\Services;

/**
 * Pintu masuk pemenuhan pesanan: meneruskan pekerjaan ke service
 * pengiriman, hasil, dan manual di baliknya. Pemanggil lama (job,
 * webhook, panel admin) tetap memakai class ini. Class ini sendiri
 * tidak menjalankan logika pemenuhan apa pun.
 */
class FulfillmentService
{
    public function __construct(
        private readonly FulfillmentSendingService $sending,
        private readonly FulfillmentResultService $result,
        private readonly FulfillmentManualService $manual,
        private readonly FulfillmentSupportService $support,
    ) {}

    public function startOrder(int $orderId): void
    {
        $this->sending->startOrder($orderId);
    }

    /**
     * Prepare an immutable provider request and encrypt persisted credential data.
     */
    public function sendAttempt(int $attemptId): void
    {
        $this->sending->sendAttempt($attemptId);
    }

    public function reconcileAttempt(int $attemptId, bool $manual = false): void
    {
        $this->result->reconcileAttempt($attemptId, $manual);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function applyProviderResult(int $attemptId, array $response, string $source): void
    {
        $this->result->applyProviderResult($attemptId, $response, $source);
    }

    /**
     * Open a NEW production attempt after a SUPER_ADMIN verifies a quarantined
     * historical reference failed. The historical attempt is never reactivated.
     */
    public function resolveLegacyQuarantine(
        int $attemptId,
        int $adminId,
        string $providerRefId,
        string $providerRc,
        string $evidenceReference,
        string $note,
        string $confirmedCustomerNo,
    ): int {
        return $this->manual->resolveLegacyQuarantine($attemptId, $adminId, $providerRefId, $providerRc, $evidenceReference, $note, $confirmedCustomerNo);
    }

    /**
     * Compute the exact Digiflazz destination used by normal sends and
     * quarantine approval, including the historical mapping fallback.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function digiflazzCustomerNo(object $order, object $mapping, array $snapshot): string
    {
        return $this->sending->digiflazzCustomerNo($order, $mapping, $snapshot);
    }

    public function retrySafeAttempt(int $attemptId): void
    {
        $this->manual->retrySafeAttempt($attemptId);
    }

    public static function isDefinitiveProviderFailure(?string $status, ?string $rc): bool
    {
        return FulfillmentManualService::isDefinitiveProviderFailure($status, $rc);
    }

    public function resendAfterConfirmedFailure(int $orderId, int $adminId): int
    {
        return $this->manual->resendAfterConfirmedFailure($orderId, $adminId);
    }

    public function completeManual(int $attemptId, ?string $deliveryCode, ?string $note, int $adminId): void
    {
        $this->manual->completeManual($attemptId, $deliveryCode, $note, $adminId);
    }

    public function failManual(int $attemptId, string $reason, int $adminId): void
    {
        $this->manual->failManual($attemptId, $reason, $adminId);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $value): array
    {
        return $this->support->json($value);
    }
}
