<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminPromotionService
{
    /** @return array<int,array<string,mixed>> */
    public function vouchers(): array
    {
        return DB::table('discount_vouchers')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'description' => (string) $row->description,
                'discountType' => (string) $row->discount_type,
                'discountValue' => (int) $row->discount_value,
                'minPurchase' => (int) $row->min_purchase,
                'maxDiscount' => $row->max_discount === null ? null : (int) $row->max_discount,
                'usageLimit' => $row->usage_limit === null ? null : (int) $row->usage_limit,
                'usedCount' => (int) $row->used_count,
                'reservedCount' => (int) $row->reserved_count,
                'startsAt' => $row->starts_at,
                'endsAt' => $row->ends_at,
                'isActive' => (bool) $row->is_active,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function flashSales(): array
    {
        return DB::table('flash_sales as fs')
            ->join('products as p', 'p.slug', '=', 'fs.product_slug')
            ->join('product_packages as pp', function ($join) {
                $join->on('pp.product_id', '=', 'p.id')->on('pp.sku', '=', 'fs.package_sku');
            })
            ->orderBy('fs.ends_at')
            ->get([
                'fs.id', 'fs.product_slug', 'p.name as product_name', 'fs.package_sku',
                'pp.label as package_label', 'p.image_url', 'p.accent', 'p.initials', 'pp.price as base_price',
                'fs.sale_price', 'fs.badge', 'fs.starts_at', 'fs.ends_at', 'fs.stock_limit',
                'fs.sold_count', 'fs.reserved_count', 'fs.is_active',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'productSlug' => (string) $row->product_slug,
                'productName' => (string) $row->product_name,
                'packageSku' => (string) $row->package_sku,
                'packageLabel' => (string) $row->package_label,
                'imageUrl' => $row->image_url,
                'accent' => (string) $row->accent,
                'initials' => (string) $row->initials,
                'basePrice' => (int) $row->base_price,
                'salePrice' => (int) $row->sale_price,
                'badge' => (string) $row->badge,
                'startsAt' => $row->starts_at,
                'endsAt' => $row->ends_at,
                'stockLimit' => $row->stock_limit === null ? null : (int) $row->stock_limit,
                'soldCount' => (int) $row->sold_count,
                'reservedCount' => (int) $row->reserved_count,
                'isActive' => (bool) $row->is_active,
            ])->all();
    }

    /** @param array<string,mixed> $input */
    public function saveVoucher(array $input, ?int $id = null): int
    {
        $code = strtoupper(trim((string) $input['code']));

        return DB::transaction(function () use ($input, $id, $code): int {
            if ($id) {
                $current = DB::table('discount_vouchers')->where('id', $id)->lockForUpdate()->first();
                if (!$current) throw new RuntimeException('Voucher diskon tidak ditemukan.');

                $renaming = (string) $current->code !== $code;
                if ($renaming) {
                    if ((int) $current->used_count > 0) {
                        throw new RuntimeException('Kode voucher tidak dapat diubah setelah pernah digunakan. Ubah nama/deskripsi, atau buat voucher baru.');
                    }
                    $history = DB::table('promotion_reservations')->where('voucher_code', $current->code)->exists();
                    if ((int) $current->reserved_count > 0 || $history) {
                        throw new RuntimeException('Kode voucher tidak dapat diubah setelah dipakai atau direservasi. Ubah nama/deskripsi, atau buat voucher baru.');
                    }
                    if (DB::table('promotion_reservations')->where('voucher_code', $code)->exists()) {
                        throw new RuntimeException('Kode voucher pernah dipakai oleh transaksi lain. Gunakan kode baru agar riwayat promo tidak tertukar.');
                    }
                }

                if ($input['usageLimit'] !== null
                    && (int) $input['usageLimit'] < (int) $current->used_count + (int) $current->reserved_count) {
                    throw new RuntimeException('Batas penggunaan tidak boleh lebih kecil dari penggunaan + reservasi aktif.');
                }

                $duplicate = DB::table('discount_vouchers')
                    ->where('code', $code)
                    ->where('id', '<>', $id)
                    ->exists();
                if ($duplicate) throw new RuntimeException('Kode voucher sudah digunakan.');

                $query = DB::table('discount_vouchers')
                    ->where('id', $id)
                    ->where('code', $current->code);
                if ($input['usageLimit'] !== null) {
                    $query->whereRaw('used_count + reserved_count <= ?', [(int) $input['usageLimit']]);
                }
                $affected = $query->update($this->voucherValues($input, $code));

                if ($affected < 1) {
                    throw new RuntimeException('Voucher diskon berubah bersamaan dengan checkout. Muat ulang lalu coba lagi.');
                }

                return $id;
            }

            if (DB::table('promotion_reservations')->where('voucher_code', $code)->exists()) {
                throw new RuntimeException('Kode voucher pernah dipakai oleh transaksi lama. Gunakan kode lain agar riwayat promo tetap konsisten.');
            }
            if (DB::table('discount_vouchers')->where('code', $code)->exists()) {
                throw new RuntimeException('Kode voucher sudah digunakan.');
            }

            return (int) DB::table('discount_vouchers')->insertGetId([
                ...$this->voucherValues($input, $code),
                'used_count' => 0,
                'reserved_count' => 0,
                'created_at' => now(),
            ]);
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function saveFlashSale(array $input, ?int $id = null): int
    {
        return DB::transaction(function () use ($input, $id): int {
            $package = DB::table('products as p')
                ->join('product_packages as pp', 'pp.product_id', '=', 'p.id')
                ->where('p.slug', $input['productSlug'])
                ->where('pp.sku', $input['packageSku'])
                ->first(['pp.price']);
            if (!$package) throw new RuntimeException('Produk atau nominal flash sale tidak ditemukan.');
            if ((int) $input['salePrice'] >= (int) $package->price) {
                throw new RuntimeException('Harga flash sale harus lebih rendah dari harga normal.');
            }

            if ($id) {
                $current = DB::table('flash_sales')->where('id', $id)->lockForUpdate()->first();
                if (!$current) throw new RuntimeException('Flash sale tidak ditemukan.');

                $changingIdentity = (string) $current->product_slug !== (string) $input['productSlug']
                    || (string) $current->package_sku !== (string) $input['packageSku'];
                if ($changingIdentity) {
                    $history = DB::table('promotion_reservations')->where('flash_sale_id', $id)->exists();
                    if ((int) $current->reserved_count > 0 || (int) $current->sold_count > 0 || $history) {
                        throw new RuntimeException('Produk/nominal flash sale tidak dapat diganti setelah promo pernah digunakan, direservasi, atau memiliki riwayat transaksi.');
                    }
                }
                if ($input['stockLimit'] !== null
                    && (int) $input['stockLimit'] < (int) $current->sold_count + (int) $current->reserved_count) {
                    throw new RuntimeException('Batas stok tidak boleh lebih kecil dari terjual + reservasi aktif.');
                }

                $query = DB::table('flash_sales')->where('id', $id);
                if ($input['stockLimit'] !== null) {
                    $query->whereRaw('sold_count + reserved_count <= ?', [(int) $input['stockLimit']]);
                }
                $affected = $query->update($this->flashValues($input));
                if ($affected < 1) {
                    throw new RuntimeException('Flash sale berubah bersamaan dengan checkout. Muat ulang lalu coba lagi.');
                }

                return $id;
            }

            return (int) DB::table('flash_sales')->insertGetId([
                ...$this->flashValues($input),
                'sold_count' => 0,
                'reserved_count' => 0,
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function delete(string $kind, int $id): void
    {
        DB::transaction(function () use ($kind, $id): void {
            if ($kind === 'voucher') {
                $current = DB::table('discount_vouchers')->where('id', $id)->lockForUpdate()->first();
                if (!$current) return;
                if ((int) $current->reserved_count > 0) {
                    throw new RuntimeException('Promo tidak dapat dihapus saat masih memiliki reservasi pembayaran aktif.');
                }
                if ((int) $current->used_count > 0) {
                    throw new RuntimeException('Voucher pernah digunakan. Nonaktifkan voucher agar riwayat transaksi tetap mengacu ke voucher yang sama.');
                }
                if (DB::table('promotion_reservations')->where('voucher_code', $current->code)->exists()) {
                    throw new RuntimeException('Voucher memiliki riwayat transaksi. Nonaktifkan voucher agar pembayaran terlambat tetap dapat direkonsiliasi.');
                }
                DB::table('discount_vouchers')->where('id', $id)->delete();
                return;
            }

            $current = DB::table('flash_sales')->where('id', $id)->lockForUpdate()->first();
            if (!$current) return;
            if ((int) $current->reserved_count > 0) {
                throw new RuntimeException('Promo tidak dapat dihapus saat masih memiliki reservasi pembayaran aktif.');
            }
            if ((int) $current->sold_count > 0) {
                throw new RuntimeException('Flash sale pernah digunakan. Nonaktifkan promo agar riwayat transaksi tetap mengacu ke promo yang sama.');
            }
            if (DB::table('promotion_reservations')->where('flash_sale_id', $id)->exists()) {
                throw new RuntimeException('Flash sale memiliki riwayat transaksi. Nonaktifkan promo agar pembayaran terlambat tetap dapat direkonsiliasi.');
            }
            DB::table('flash_sales')->where('id', $id)->delete();
        }, 3);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function voucherValues(array $input, string $code): array
    {
        return [
            'code' => $code,
            'name' => trim((string) $input['name']),
            'description' => trim((string) $input['description']),
            'discount_type' => (string) $input['discountType'],
            'discount_value' => (int) $input['discountValue'],
            'min_purchase' => (int) $input['minPurchase'],
            'max_discount' => $input['maxDiscount'] === null ? null : (int) $input['maxDiscount'],
            'usage_limit' => $input['usageLimit'] === null ? null : (int) $input['usageLimit'],
            'starts_at' => $input['startsAt'],
            'ends_at' => $input['endsAt'],
            'is_active' => $input['isActive'] ? 1 : 0,
            'updated_at' => now(),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function flashValues(array $input): array
    {
        return [
            'product_slug' => trim((string) $input['productSlug']),
            'package_sku' => trim((string) $input['packageSku']),
            'sale_price' => (int) $input['salePrice'],
            'badge' => trim((string) $input['badge']),
            'starts_at' => $input['startsAt'],
            'ends_at' => $input['endsAt'],
            'stock_limit' => $input['stockLimit'] === null ? null : (int) $input['stockLimit'],
            'is_active' => $input['isActive'] ? 1 : 0,
            'updated_at' => now(),
        ];
    }
}
