<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            } catch (\Throwable $error) {
                Log::warning('Digiflazz catalog sync rejected.', [
                    'reason' => 'request_exception',
                    'exception_class' => $error::class,
                ]);
                throw ValidationException::withMessages(['sync' => 'Digiflazz tidak merespons. Data sebelumnya tetap tersimpan.']);
            }
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || ($sku === null && count($rows) === 0)) {
                $providerCode = $response->json('data.rc');
                $providerCode = is_string($providerCode) || is_int($providerCode) ? (string) $providerCode : '';
                Log::warning('Digiflazz catalog sync rejected.', [
                    'reason' => ! $response->successful() ? 'http_error'
                        : (! is_array($rows) || ! array_is_list($rows) ? 'invalid_data_shape' : 'empty_full_catalog'),
                    'http_status' => $response->status(),
                    'data_type' => get_debug_type($rows),
                    'row_count' => is_array($rows) && array_is_list($rows) ? count($rows) : null,
                    'provider_rc' => preg_match('/^[A-Za-z0-9_-]{1,16}$/', $providerCode) ? $providerCode : null,
                ]);
                throw ValidationException::withMessages(['sync' => 'Daftar harga Digiflazz kosong atau tidak valid.']);
            }
            $clean = [];
            foreach ($rows as $index => $row) {
                $invalidReason = $this->invalidRowReason($row, $sku);
                if ($invalidReason !== null) {
                    Log::warning('Digiflazz catalog sync rejected.', [
                        'reason' => $invalidReason,
                        'row_index' => $index,
                        'http_status' => $response->status(),
                    ]);
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
                if ($sku === null || $clean === []) {
                    $missingMappings = DB::table('provider_mappings')->where('provider_id', $providerId)
                        ->whereNotNull('external_sku');
                    $missingItems = DB::table('digiflazz_catalog_items');
                    if ($sku === null) {
                        $codes = array_column($clean, 'buyer_sku_code');
                        $missingMappings->whereNotIn('external_sku', $codes);
                        $missingItems->whereNotIn('buyer_sku_code', $codes);
                    } else {
                        $missingMappings->where('external_sku', $sku);
                        $missingItems->where('buyer_sku_code', $sku);
                    }
                    // Keep order/source history, but prevent deleted SKUs from being sold.
                    $disabled = $missingMappings->where('is_active', true)
                        ->update(['is_active' => false, 'updated_at' => now()]);
                    $removed = $missingItems->delete();
                    if ($sku === null) {
                        DB::table('system_settings')->updateOrInsert(['key' => 'digiflazz.last_catalog_sync'], [
                            'value' => json_encode([
                                'at' => now()->toIso8601String(), 'count' => count($clean),
                                'removed' => $removed, 'disabled_mappings' => $disabled,
                            ], JSON_THROW_ON_ERROR),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }

                if ($sku === null) {
                    app(DigiflazzAutoSources::class)->sync();
                }

                return count($clean);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    /**
     * Keep provider payload values out of diagnostic logs.
     */
    private function invalidRowReason(mixed $row, ?string $sku): ?string
    {
        if (! is_array($row)) {
            return 'row_not_object';
        }
        if (! preg_match('/^[A-Za-z0-9._-]{1,120}$/', (string) ($row['buyer_sku_code'] ?? ''))) {
            return 'invalid_sku';
        }
        if (! isset($row['price']) || ! is_numeric($row['price']) || (int) $row['price'] <= 0) {
            return 'invalid_price';
        }
        foreach (['buyer_product_status', 'seller_product_status', 'unlimited_stock'] as $field) {
            if (! isset($row[$field])) {
                return 'missing_'.$field;
            }
            if (! is_bool($row[$field])) {
                return 'invalid_type_'.$field;
            }
        }
        if ($sku !== null && $row['buyer_sku_code'] !== $sku) {
            return 'sku_mismatch';
        }

        return null;
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
