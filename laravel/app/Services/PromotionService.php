<?php

namespace App\Services;

use App\Exceptions\PromotionQuoteException;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    /** @return array<string,mixed> */
    public function quote(
        string $productSlug,
        string $packageSku,
        int $unitPrice,
        ?string $voucherCode,
        ?string $customerId,
        int $quantity,
    ): array {
        $quantity = max(1, min(5, $quantity));
        $now = now();

        $flash = DB::table('flash_sales')
            ->where('product_slug', $productSlug)
            ->where('package_sku', $packageSku)
            ->where('is_active', 1)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->where(function ($query) {
                $query->whereNull('stock_limit')
                    ->orWhereRaw('sold_count + reserved_count < stock_limit');
            })
            ->first(['id', 'sale_price', 'ends_at']);

        $basePrice = $unitPrice * $quantity;
        $sellingPrice = (($flash && (int) $flash->sale_price < $unitPrice)
            ? (int) $flash->sale_price
            : $unitPrice) * $quantity;

        $voucher = null;
        $voucherDiscount = 0;
        $code = strtoupper(trim((string) $voucherCode));

        if ($code !== '') {
            $voucher = DB::table('discount_vouchers')
                ->where('code', $code)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->where(function ($query) {
                    $query->whereNull('usage_limit')
                        ->orWhereRaw('used_count + reserved_count < usage_limit');
                })
                ->first();

            if (!$voucher) {
                throw new PromotionQuoteException('Kode voucher tidak aktif, sudah habis, atau tidak ditemukan.');
            }
            if ($sellingPrice < (int) $voucher->min_purchase) {
                throw new PromotionQuoteException('Minimum transaksi voucher belum terpenuhi.');
            }

            $voucherDiscount = $voucher->discount_type === 'fixed'
                ? (int) $voucher->discount_value
                : (int) floor($sellingPrice * (int) $voucher->discount_value / 100);
            if ($voucher->max_discount !== null) {
                $voucherDiscount = min($voucherDiscount, (int) $voucher->max_discount);
            }
            $voucherDiscount = min($voucherDiscount, max(0, $sellingPrice - 1));
        }

        [$tier, $memberPercent] = $this->memberDiscount($customerId);
        $memberDiscount = min(
            (int) floor($sellingPrice * $memberPercent / 100),
            max(0, $sellingPrice - 1),
        );

        $useMember = $memberDiscount > 0 && $memberDiscount >= $voucherDiscount;
        $discountAmount = $useMember ? $memberDiscount : $voucherDiscount;
        $discountSource = $discountAmount > 0 ? ($useMember ? 'member' : 'voucher') : null;

        return [
            'basePrice' => $basePrice,
            'sellingPrice' => $sellingPrice,
            'discountAmount' => $discountAmount,
            'finalPrice' => max(1, $sellingPrice - $discountAmount),
            'voucherCode' => $discountSource === 'voucher' ? ($voucher?->code ?? null) : null,
            'flashSaleId' => $flash?->id ? (int) $flash->id : null,
            'flashSaleEndsAt' => $flash?->ends_at,
            'memberTier' => $tier,
            'memberDiscountPercent' => $memberPercent,
            'memberDiscountAmount' => $memberDiscount,
            'discountSource' => $discountSource,
        ];
    }

    /** @return array{0:?string,1:float} */
    private function memberDiscount(?string $customerId): array
    {
        if (!$customerId) {
            return [null, 0.0];
        }

        $user = DB::table('customer_users')->where('id', $customerId)->first([
            'tier_mode', 'tier_override', 'tier_progress_bonus',
        ]);
        if (!$user) {
            return [null, 0.0];
        }

        $lifetime = (int) DB::table('orders')
            ->where('customer_id', $customerId)
            ->where('payment_status', 'paid')
            ->sum('total');
        $progress = $lifetime + max(0, (int) $user->tier_progress_bonus);

        $tier = 'basic';
        if ($progress >= 50000000) {
            $tier = 'platinum';
        } elseif ($progress >= 10000000) {
            $tier = 'diamond';
        } elseif ($progress >= 1000000) {
            $tier = 'gold';
        }

        if ($user->tier_mode === 'manual'
            && in_array($user->tier_override, ['basic', 'gold', 'diamond', 'platinum'], true)) {
            $tier = (string) $user->tier_override;
        }

        $percent = (float) (DB::table('member_tier_settings')
            ->where('tier', $tier)
            ->value('discount_percent') ?? 0);

        return [$tier, max(0.0, min(100.0, $percent))];
    }
}
