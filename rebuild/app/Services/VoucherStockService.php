<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherStockService
{
    /**
     * @return array{received:int,imported:int,duplicates:int}
     */
    public function import(string $stockKey, string $codesText): array
    {
        $key = $this->normalizeKey($stockKey);
        $codes = collect(preg_split('/\R/u', $codesText) ?: [])
            ->map(fn (string $code): string => trim($code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            throw ValidationException::withMessages(['codes_text' => 'Masukkan minimal satu kode.']);
        }
        if ($codes->count() > 1000) {
            throw ValidationException::withMessages(['codes_text' => 'Maksimal 1.000 kode untuk sekali impor.']);
        }

        $imported = 0;
        foreach ($codes as $code) {
            if (mb_strlen($code) < 3 || mb_strlen($code) > 500 || str_contains($code, "\0")) {
                throw ValidationException::withMessages(['codes_text' => 'Setiap kode harus berisi 3–500 karakter yang valid.']);
            }

            $hash = $this->hash($code);
            $imported += DB::table('voucher_stock_codes')->insertOrIgnore([
                'stock_key' => $key,
                'code_ciphertext' => Crypt::encryptString($code),
                'code_hash' => $hash,
                'status' => 'AVAILABLE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'received' => $codes->count(),
            'imported' => $imported,
            'duplicates' => $codes->count() - $imported,
        ];
    }

    public function available(string $stockKey): bool
    {
        return DB::table('voucher_stock_codes')
            ->where('stock_key', $this->normalizeKey($stockKey))
            ->where('status', 'AVAILABLE')
            ->exists();
    }

    /**
     * Caller must wrap this method in a database transaction.
     *
     * @return array{id:int,code:string}
     */
    public function claim(int $orderId, string $stockKey): array
    {
        $key = $this->normalizeKey($stockKey);
        $existing = DB::table('voucher_stock_codes')
            ->where('order_id', $orderId)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return ['id' => (int) $existing->id, 'code' => Crypt::decryptString($existing->code_ciphertext)];
        }

        $row = DB::table('voucher_stock_codes')
            ->where('stock_key', $key)
            ->where('status', 'AVAILABLE')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $row) {
            throw ValidationException::withMessages(['fulfillment' => 'Stok kode untuk nominal ini habis.']);
        }

        $updated = DB::table('voucher_stock_codes')
            ->where('id', $row->id)
            ->where('status', 'AVAILABLE')
            ->update([
                'status' => 'RESERVED',
                'order_id' => $orderId,
                'reserved_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['fulfillment' => 'Stok kode sedang dipakai transaksi lain. Coba ulang.']);
        }

        return ['id' => (int) $row->id, 'code' => Crypt::decryptString($row->code_ciphertext)];
    }

    public function markDelivered(int $codeId, int $orderId): void
    {
        DB::table('voucher_stock_codes')
            ->where('id', $codeId)
            ->where('order_id', $orderId)
            ->whereIn('status', ['RESERVED', 'DELIVERED'])
            ->update([
                'status' => 'DELIVERED',
                'delivered_at' => DB::raw('COALESCE(delivered_at, CURRENT_TIMESTAMP)'),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, array{available:int,reserved:int,delivered:int,void:int,total:int}>
     */
    public function counts(): array
    {
        return DB::table('voucher_stock_codes')
            ->selectRaw("stock_key,
                SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN status = 'RESERVED' THEN 1 ELSE 0 END) AS reserved,
                SUM(CASE WHEN status = 'DELIVERED' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status = 'VOID' THEN 1 ELSE 0 END) AS void_count,
                COUNT(*) AS total")
            ->groupBy('stock_key')
            ->orderBy('stock_key')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $row->stock_key => [
                    'available' => (int) $row->available,
                    'reserved' => (int) $row->reserved,
                    'delivered' => (int) $row->delivered,
                    'void' => (int) $row->void_count,
                    'total' => (int) $row->total,
                ],
            ])->all();
    }

    public function normalizeKey(string $stockKey): string
    {
        $key = strtolower(trim($stockKey));
        if (! preg_match('/^[a-z0-9][a-z0-9._:-]{1,99}$/', $key)) {
            throw ValidationException::withMessages([
                'stock_key' => 'Kunci stok hanya boleh berisi huruf kecil, angka, titik, garis, titik dua, atau underscore.',
            ]);
        }

        return $key;
    }

    private function hash(string $code): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw ValidationException::withMessages(['codes_text' => 'Kunci enkripsi aplikasi belum tersedia.']);
        }

        return hash_hmac('sha256', $code, $secret);
    }
}
