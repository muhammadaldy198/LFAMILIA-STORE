<?php

namespace App\Services;

/**
 * Pintu masuk pembayaran: class ini hanya meneruskan panggilan agar
 * pemanggil lama tidak berubah. Memulai pembayaran diurus
 * PaymentStartService, membuat transaksi di gateway diurus
 * PaymentCreationService, dan memulihkan pembayaran yang macet
 * diurus PaymentRecoveryService.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentStartService $start,
        private readonly PaymentCreationService $creation,
        private readonly PaymentRecoveryService $recovery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startOrder(object $order, string $idempotencyKey, ?int $userId): array
    {
        return $this->start->startOrder($order, $idempotencyKey, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function startTopup(object $topup, string $idempotencyKey, string $customerName, string $customerEmail, string $customerPhone): array
    {
        return $this->start->startTopup($topup, $idempotencyKey, $customerName, $customerEmail, $customerPhone);
    }

    // Penerusan privat: titik masuk pembuatan lama dipertahankan di
    // class ini dan diteruskan ke PaymentCreationService.
    private function createExternal(object $payment, object $target, array $route): array
    {
        return $this->creation->createExternal($payment, $target, $route);
    }

    /**
     * Recover one payment orphaned in CREATING: its local creation
     * transaction committed but the gateway creation call never ran, so
     * no other flow will ever move the row again. The gateway is always
     * asked first; a new creation is driven only when the gateway
     * definitively has no transaction for the merchant reference.
     *
     * @return string adopted|redriven|terminated|inconclusive|skipped
     */
    public function recoverStuckCreating(int $paymentId): string
    {
        return $this->recovery->recoverStuckCreating($paymentId);
    }
}
