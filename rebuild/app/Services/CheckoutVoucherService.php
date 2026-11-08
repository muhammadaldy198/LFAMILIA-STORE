<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Memilih voucher untuk checkout: menentukan voucher yang berlaku
 * bagi pembeli dan harga tertentu, termasuk status aktif dan
 * reservasinya. Dipakai CheckoutQuoteService dan
 * CheckoutCreationService.
 */
class CheckoutVoucherService
{
    /**
     * @param  array<string, mixed>  $price
     * @return array{voucher_id:?int,code:?string,discount_idr:int,snapshot:?array}
     */
    public function voucher(
        ?string $code,
        array $price,
        ?User $user,
        ?string $guestEmail,
        ?string $guestPhone,
        bool $lock,
        int $memberDiscountIdr = 0,
    ): array {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu definisi pemilihan voucher — syarat lingkup, status
        // aktif, dan reservasi voucher membentuk satu makna berlaku
        // atau tidak; memisahkannya membuat definisinya terbaca
        // setengah-setengah.
        $code = Str::upper(trim((string) $code));
        if ($code === '') {
            return ['voucher_id' => null, 'code' => null, 'discount_idr' => 0, 'snapshot' => null];
        }

        $query = DB::table('vouchers')->whereRaw('UPPER(code) = ?', [$code]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $voucher = $query->first();

        if (! $voucher || ! $voucher->is_active) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher tidak tersedia.']);
        }

        $now = now();
        if ($voucher->starts_at && $now->lt(Carbon::parse($voucher->starts_at))) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher belum aktif.']);
        }
        if ($voucher->ends_at && $now->gt(Carbon::parse($voucher->ends_at))) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher sudah berakhir.']);
        }
        if ($price['subtotal_idr'] < (int) $voucher->minimum_total_idr) {
            throw ValidationException::withMessages(['voucher_code' => 'Minimum transaksi voucher belum terpenuhi.']);
        }

        $productScoped = DB::table('voucher_products')->where('voucher_id', $voucher->id)->exists();
        $categoryScoped = DB::table('voucher_categories')->where('voucher_id', $voucher->id)->exists();
        if (($productScoped || $categoryScoped)
            && ! DB::table('voucher_products')->where('voucher_id', $voucher->id)
                ->where('product_id', $price['product_id'])->exists()
            && ! DB::table('voucher_categories')->where('voucher_id', $voucher->id)
                ->where('category_id', $price['category_id'])->exists()) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher tidak berlaku untuk produk ini.']);
        }

        $active = fn ($query) => $query->where(function ($status) use ($now): void {
            $status->where('status', 'REDEEMED')
                ->orWhere(function ($reserved) use ($now): void {
                    $reserved->where('status', 'RESERVED')->where('reserved_until', '>', $now);
                });
        });

        if ($voucher->total_quota !== null) {
            $used = $active(DB::table('voucher_redemptions')->where('voucher_id', $voucher->id))->count();
            if ($used >= (int) $voucher->total_quota) {
                throw ValidationException::withMessages(['voucher_code' => 'Kuota voucher sudah habis.']);
            }
        }

        if ($voucher->per_customer_limit !== null) {
            $customerQuery = DB::table('voucher_redemptions')->where('voucher_id', $voucher->id);
            if ($user) {
                $customerQuery->where('user_id', $user->id);
            } else {
                $customerQuery->where('guest_identifier_hash', $this->guestIdentifier(
                    (string) $guestEmail,
                    (string) $guestPhone
                ));
            }
            $used = $active($customerQuery)->count();
            if ($used >= (int) $voucher->per_customer_limit) {
                throw ValidationException::withMessages(['voucher_code' => 'Batas penggunaan voucher sudah tercapai.']);
            }
        }

        $type = Str::upper((string) $voucher->discount_type);
        $value = (int) $voucher->discount_value;
        $afterMember = max(1, $price['subtotal_idr'] - max(0, $memberDiscountIdr));
        $discount = $type === 'PERCENT'
            ? intdiv($afterMember, 100) * $value + intdiv(($afterMember % 100) * $value, 100)
            : $value;
        if ($voucher->max_discount_idr !== null) {
            $discount = min($discount, (int) $voucher->max_discount_idr);
        }
        $discount = min($discount, max(0, $afterMember - 1));

        if ($discount <= 0) {
            throw ValidationException::withMessages(['voucher_code' => 'Nilai voucher menghasilkan total tidak valid.']);
        }

        return [
            'voucher_id' => (int) $voucher->id,
            'code' => (string) $voucher->code,
            'discount_idr' => $discount,
            'snapshot' => [
                'id' => (int) $voucher->id,
                'code' => (string) $voucher->code,
                'name' => filled($voucher->name) ? (string) $voucher->name : (string) $voucher->code,
                'discount_type' => $type,
                'discount_value' => $value,
                'max_discount_idr' => $voucher->max_discount_idr === null ? null : (int) $voucher->max_discount_idr,
            ],
        ];
    }

    private function guestIdentifier(string $email, string $phone): string
    {
        $email = Str::lower(trim($email));
        $phone = $this->normalizePhone($phone);
        if ($email === '' || $phone === '') {
            throw ValidationException::withMessages([
                'guest_email' => 'Email dan nomor HP guest diperlukan untuk voucher.',
            ]);
        }

        return hash_hmac('sha256', $email.'|'.$phone, (string) config('app.key'));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', trim($phone)) ?? '';
    }
}
