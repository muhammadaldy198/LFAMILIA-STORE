<?php

namespace App\Services;

use App\Models\User;

/**
 * Pintu masuk checkout: class ini hanya meneruskan panggilan agar
 * pemanggil lama tidak berubah. Menghitung penawaran diurus
 * CheckoutQuoteService dan membuat pesanan diurus
 * CheckoutCreationService.
 */
class CheckoutService
{
    public function __construct(
        private readonly CheckoutQuoteService $quote,
        private readonly CheckoutCreationService $creation,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableVouchers(
        int $packageId,
        ?User $user,
        ?string $guestEmail,
        ?string $guestPhone,
    ): array {
        return $this->quote->availableVouchers($packageId, $user, $guestEmail, $guestPhone);
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(
        int $packageId,
        string $paymentChannelCode,
        ?string $voucherCode,
        ?User $user,
        ?string $guestEmail,
        ?string $guestPhone,
    ): array {
        return $this->quote->quote($packageId, $paymentChannelCode, $voucherCode, $user, $guestEmail, $guestPhone);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{order:object,access_code:?string,created:bool}
     */
    public function create(array $data, ?User $user): array
    {
        return $this->creation->create($data, $user);
    }
}
