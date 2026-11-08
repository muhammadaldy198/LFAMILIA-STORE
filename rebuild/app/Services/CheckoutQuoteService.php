<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menghitung penawaran checkout sebelum pesanan dibuat: harga,
 * biaya per channel, dan voucher yang tersedia untuk pembeli.
 * Membuat pesanannya diurus CheckoutCreationService.
 */
class CheckoutQuoteService
{
    public function __construct(
        private readonly CheckoutPricing $pricing,
        private readonly PaymentRoutingService $paymentRouting,
        private readonly MembershipService $membership,
        private readonly CheckoutVoucherService $vouchers,
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
        // Alasan belum dipecah: satu definisi query voucher yang
        // tersedia — syarat lingkup harga dan keberadaan voucher yang
        // bersarang membentuk satu makna daftar yang ditawarkan.
        $price = $this->pricing->forPackage($packageId);
        $member = $user
            ? $this->membership->discount($user, $price['subtotal_idr'])
            : ['amount_idr' => 0, 'tier_code' => null, 'discount_bps' => 0, 'profile' => null];
        $now = now();

        $candidates = DB::table('vouchers')
            ->where('is_active', true)
            ->where('minimum_total_idr', '<=', $price['subtotal_idr'])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(function ($scope) use ($price): void {
                $scope->where(function ($unscoped): void {
                    $unscoped->whereNotExists(function ($sub): void {
                        $sub->selectRaw('1')->from('voucher_products')
                            ->whereColumn('voucher_products.voucher_id', 'vouchers.id');
                    })->whereNotExists(function ($sub): void {
                        $sub->selectRaw('1')->from('voucher_categories')
                            ->whereColumn('voucher_categories.voucher_id', 'vouchers.id');
                    });
                })->orWhereExists(function ($sub) use ($price): void {
                    $sub->selectRaw('1')->from('voucher_products')
                        ->whereColumn('voucher_products.voucher_id', 'vouchers.id')
                        ->where('voucher_products.product_id', $price['product_id']);
                })->orWhereExists(function ($sub) use ($price): void {
                    $sub->selectRaw('1')->from('voucher_categories')
                        ->whereColumn('voucher_categories.voucher_id', 'vouchers.id')
                        ->where('voucher_categories.category_id', $price['category_id']);
                });
            })
            ->orderByDesc('discount_value')
            ->get(['code', 'discount_type', 'discount_value', 'minimum_total_idr', 'ends_at']);

        $available = [];
        foreach ($candidates as $candidate) {
            try {
                $resolved = $this->vouchers->voucher(
                    (string) $candidate->code,
                    $price,
                    $user,
                    $guestEmail,
                    $guestPhone,
                    false,
                    (int) $member['amount_idr']
                );
            } catch (ValidationException) {
                continue;
            }

            $available[] = [
                'code' => (string) $candidate->code,
                'discount_type' => (string) $candidate->discount_type,
                'discount_value' => (int) $candidate->discount_value,
                'discount_idr' => (int) $resolved['discount_idr'],
                'minimum_total_idr' => (int) $candidate->minimum_total_idr,
                'ends_at' => $candidate->ends_at
                    ? Carbon::parse($candidate->ends_at, 'UTC')->toIso8601String() : null,
            ];
        }

        usort($available, fn (array $left, array $right): int => $right['discount_idr'] <=> $left['discount_idr']);

        return array_slice($available, 0, 30);
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
        $price = $this->pricing->forPackage($packageId);
        $member = $user
            ? $this->membership->discount($user, $price['subtotal_idr'])
            : ['amount_idr' => 0, 'tier_code' => null, 'discount_bps' => 0, 'profile' => null];
        $voucher = $this->vouchers->voucher(
            $voucherCode,
            $price,
            $user,
            $guestEmail,
            $guestPhone,
            false,
            (int) $member['amount_idr']
        );
        $route = $this->paymentRouting->resolve($paymentChannelCode, false, 'order');
        if ($route['gateway_code'] === 'WALLET' && ! $user) {
            throw ValidationException::withMessages([
                'payment_channel_code' => 'Saldo hanya tersedia untuk akun customer.',
            ]);
        }
        $discount = (int) $member['amount_idr'] + (int) $voucher['discount_idr'];
        $chargeable = $price['subtotal_idr'] - $discount;
        $fee = $this->paymentRouting->fee($chargeable, $route);

        return [
            'subtotal_idr' => $price['subtotal_idr'],
            'member_discount_idr' => (int) $member['amount_idr'],
            'voucher_discount_idr' => (int) $voucher['discount_idr'],
            'discount_idr' => $discount,
            'member_tier_code' => $member['tier_code'],
            'member_discount_bps' => (int) $member['discount_bps'],
            'fee_idr' => $fee,
            'total_idr' => $chargeable + $fee,
            'voucher_code' => $voucher['code'],
            'payment_channel_code' => $route['channel_code'],
        ];
    }
}
