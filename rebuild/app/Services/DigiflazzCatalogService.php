<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class DigiflazzCatalogService
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    public function sync(?string $sku = null): int
    {
        $lock = Cache::lock('digiflazz.catalog.sync', 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['sync' => 'Sinkronisasi masih berjalan.']);
        }
        try {
            $resolved = $this->runtime->resolve('digiflazz');
            $config = $resolved['config'] ?? [];
            if (! is_array($config) || empty($config['username']) || empty($config['api_key'])) {
                throw ValidationException::withMessages(['sync' => 'Lengkapi dan aktifkan Digiflazz di Integrasi.']);
            }
            $url = $this->runtime->digiflazzApiBase($config);
            try {
                $response = Http::acceptJson()->timeout(20)->post($url.'/v1/price-list', [
                    'cmd' => 'prepaid', 'username' => $config['username'],
                    'sign' => md5($config['username'].$config['api_key'].'pricelist'),
                    ...($sku !== null ? ['code' => $sku] : []),
                ]);
                $rows = $response->json('data');
            } catch (\Throwable) {
                throw ValidationException::withMessages(['sync' => 'Digiflazz tidak merespons. Data sebelumnya tetap tersimpan.']);
            }
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || count($rows) === 0) {
                throw ValidationException::withMessages(['sync' => 'Daftar harga Digiflazz kosong atau tidak valid.']);
            }
            $clean = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! preg_match('/^[A-Za-z0-9._-]{1,120}$/', (string) ($row['buyer_sku_code'] ?? ''))
                    || ! isset($row['price']) || ! is_numeric($row['price']) || (int) $row['price'] <= 0
                    || ! isset($row['buyer_product_status'], $row['seller_product_status'], $row['unlimited_stock'])
                    || ! is_bool($row['buyer_product_status']) || ! is_bool($row['seller_product_status']) || ! is_bool($row['unlimited_stock'])
                    || ($sku !== null && $row['buyer_sku_code'] !== $sku)) {
                    throw ValidationException::withMessages(['sync' => 'Format daftar harga tidak valid. Tidak ada perubahan disimpan.']);
                }
                $clean[] = [
                    'buyer_sku_code' => $row['buyer_sku_code'],
                    'product_name' => mb_substr((string) ($row['product_name'] ?? $row['buyer_sku_code']), 0, 255),
                    'category' => mb_substr((string) ($row['category'] ?? ''), 0, 255),
                    'brand' => mb_substr((string) ($row['brand'] ?? ''), 0, 255),
                    'type' => mb_substr((string) ($row['type'] ?? ''), 0, 255),
                    'seller_name' => mb_substr((string) ($row['seller_name'] ?? ''), 0, 255),
                    'price_idr' => (int) $row['price'],
                    'buyer_active' => $row['buyer_product_status'],
                    'seller_active' => $row['seller_product_status'],
                    'unlimited_stock' => $row['unlimited_stock'],
                    'stock' => max(0, (int) ($row['stock'] ?? 0)),
                    'multi' => (bool) ($row['multi'] ?? false),
                    'start_cut_off' => preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) ($row['start_cut_off'] ?? '')) ? $row['start_cut_off'] : '00:00',
                    'end_cut_off' => preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) ($row['end_cut_off'] ?? '')) ? $row['end_cut_off'] : '00:00',
                    'description' => mb_substr((string) ($row['desc'] ?? ''), 0, 5000),
                    'synced_at' => now(), 'updated_at' => now(),
                ];
            }

            return DB::transaction(function () use ($clean, $sku): int {
                $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
                foreach ($clean as $row) {
                    $existing = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', $row['buyer_sku_code'])->lockForUpdate()->first();
                    if ($existing) {
                        $update = $row;
                        if ((string) $existing->seller_name !== (string) $row['seller_name']) {
                            $update['baseline_price_idr'] = $row['price_idr'];
                        }
                        DB::table('digiflazz_catalog_items')->where('id', $existing->id)->update($update);
                    } else {
                        DB::table('digiflazz_catalog_items')->insert([...$row, 'baseline_price_idr' => $row['price_idr'], 'created_at' => now()]);
                    }
                    DB::table('provider_mappings')->where('provider_id', $providerId)->where('external_sku', $row['buyer_sku_code'])
                        ->update(['cost_idr' => $row['price_idr'], 'max_price_idr' => $row['price_idr'], 'updated_at' => now()]);
                }
                if ($sku === null) {
                    foreach (DB::table('provider_mappings')->where('provider_id', $providerId)->whereNotNull('external_sku')
                        ->whereNotIn('external_sku', array_column($clean, 'buyer_sku_code'))->get() as $missing) {
                        DB::table('digiflazz_catalog_items')->insertOrIgnore([
                            'buyer_sku_code' => $missing->external_sku, 'product_name' => $missing->external_sku,
                            'price_idr' => max(1, (int) $missing->cost_idr), 'baseline_price_idr' => max(1, (int) $missing->cost_idr),
                            'buyer_active' => false, 'seller_active' => false, 'unlimited_stock' => false, 'multi' => false,
                            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table('digiflazz_catalog_items')->whereNotIn('buyer_sku_code', array_column($clean, 'buyer_sku_code'))
                        ->update(['buyer_active' => false, 'seller_active' => false, 'updated_at' => now()]);
                }

                return count($clean);
            });
        } finally {
            $lock->release();
        }
    }

    public function available(object $item): bool
    {
        if (! $item->buyer_active || ! $item->seller_active || (! $item->unlimited_stock && $item->stock <= 0)) {
            return false;
        }
        $start = $item->start_cut_off;
        $end = $item->end_cut_off;
        $time = now('Asia/Jakarta')->format('H:i');
        if ($start === $end) {
            return true;
        }

        return ! ($start < $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end));
    }
}
