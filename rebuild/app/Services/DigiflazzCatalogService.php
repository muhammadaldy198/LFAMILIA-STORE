<?php

namespace App\Services;

use App\Models\ProductPackage;
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
                    'is_present' => true, 'synced_at' => now(), 'updated_at' => now(),
                ];
            }

            // Reject suspiciously short "full" lists before marking many live SKUs absent.
            // Small test catalogs are allowed, but a real catalog collapsing by >50% is not.
            if ($sku === null) {
                $previousCount = DB::table('digiflazz_catalog_items')->where('is_present', true)->count();
                if ($previousCount >= 100 && count($clean) * 2 < $previousCount) {
                    throw ValidationException::withMessages([
                        'sync' => 'Jumlah SKU Digiflazz turun drastis. Data lama tidak diubah; ulangi sinkronisasi setelah memeriksa penyedia.',
                    ]);
                }
            }

            return DB::transaction(function () use ($clean, $sku): int {
                $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

                // Lock ALL affected packages in stable ID order before any
                // catalog/mapping writes. The same locks cover present rows
                // and the missing-SKU cleanup below. A per-SKU lock order is
                // unsafe when Digiflazz returns SKUs in arbitrary order.
                $packageQuery = DB::table('provider_mappings')
                    ->where('provider_id', $providerId);
                if ($sku !== null) {
                    $packageQuery->where('external_sku', $sku);
                }
                $packageIds = $packageQuery->pluck('product_package_id')->unique()->sort()->values()->all();
                if ($packageIds !== []) {
                    DB::table('product_packages')->whereIn('id', $packageIds)
                        ->orderBy('id')->lockForUpdate()->get(['id']);
                }

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
                    $mappingQuery = DB::table('provider_mappings')->where('provider_id', $providerId)
                        ->where('external_sku', $row['buyer_sku_code']);
                    // Never overwrite the price of a mapping whose pinned supplier
                    // identity disagrees with this SKU, even when its price is not
                    // manually locked. Such mappings stay active but ineligible for
                    // checkout and fulfillment until an admin examines them.
                    $mappings = (clone $mappingQuery)->lockForUpdate()
                        ->get([
                            'id', 'is_manual_price', 'fulfillment_config', 'cost_idr', 'store_cost_idr',
                            'product_package_id',
                        ]);
                    $matching = $mappings->filter(
                        fn (object $mapping): bool => $this->verifiedIdentityMatches((object) $row, $mapping->fulfillment_config)
                    );
                    if ($matching->count() !== $mappings->count()) {
                        Log::warning('Digiflazz SKU identity changed; locked mapping values preserved.', [
                            'buyer_sku_code' => $row['buyer_sku_code'],
                            'affected_mappings' => $mappings->count() - $matching->count(),
                        ]);
                    }
                    $matchingIds = $matching->pluck('id')->all();
                    if ($matchingIds !== []) {
                        foreach ($matching as $mapping) {
                            // Seller price is authoritative, even for legacy
                            // mappings that used to have a manual cost lock.
                            DB::table('provider_mappings')->where('id', $mapping->id)->update([
                                'cost_idr' => $row['price_idr'],
                                'seller_reference_price_idr' => $row['price_idr'],
                                'store_cost_idr' => $row['price_idr'],
                                'max_price_idr' => $row['price_idr'],
                                'is_manual_price' => false,
                                'updated_at' => now(),
                            ]);
                        }
                        // Restore only mappings previously disabled *by sync*, and
                        // only if their verified supplier identity still matches.
                        if ($row['buyer_active'] && $row['seller_active']
                            && ($row['unlimited_stock'] || $row['stock'] > 0)) {
                            // Only a mapping previously disabled BY SYNC may
                            // return. Old manually-disabled SKU rows do not
                            // supersede the admin's chosen replacement; among
                            // multiple sync-disabled legacy rows, choose one
                            // deterministically. Never activate a second SKU.
                            $restoreCandidates = (clone $mappingQuery)->whereIn('id', $matchingIds)
                                ->where('disabled_by_sync', true)->where('is_active', false)
                                ->get(['id', 'product_package_id']);
                            foreach ($restoreCandidates as $candidate) {
                                $siblings = DB::table('provider_mappings')
                                    ->where('product_package_id', $candidate->product_package_id)
                                    ->where('provider_id', $providerId)
                                    ->orderBy('priority')->orderBy('id')
                                    ->lockForUpdate()->get(['id', 'is_active', 'disabled_by_sync']);
                                $chosen = $siblings->first(
                                    fn (object $sibling): bool => (bool) $sibling->disabled_by_sync
                                );
                                if ($chosen === null
                                    || (int) $chosen->id !== (int) $candidate->id
                                    || $siblings->contains(fn (object $sibling): bool => (bool) $sibling->is_active)) {
                                    continue;
                                }
                                DB::table('provider_mappings')->where('id', $candidate->id)
                                    ->where('disabled_by_sync', true)->where('is_active', false)
                                    ->update(['is_active' => true, 'disabled_by_sync' => false, 'updated_at' => now()]);
                            }
                            // Nominal yang dulu dimatikan sinkron ikut hidup lagi,
                            // tapi hanya bila penandanya nyala dan sekarang benar-benar
                            // punya sumber aktif. Nominal yang admin matikan sendiri
                            // tidak pernah disentuh.
                            DB::table('product_packages')
                                ->whereIn('id', $mappings->pluck('product_package_id')->unique()->all())
                                ->where('disabled_by_sync', true)
                                ->whereExists(fn ($query) => $query
                                    ->selectRaw('1')->from('provider_mappings')
                                    ->whereColumn('provider_mappings.product_package_id', 'product_packages.id')
                                    ->where('provider_mappings.is_active', true))
                                ->update(['is_active' => true, 'disabled_by_sync' => false, 'updated_at' => now()]);
                        }
                    }
                }
                if ($sku === null || $clean === []) {
                    $missingItems = DB::table('digiflazz_catalog_items');
                    if ($sku === null) {
                        $codes = array_column($clean, 'buyer_sku_code');
                        $missingItems->whereNotIn('buyer_sku_code', $codes);
                    } else {
                        $missingItems->where('buyer_sku_code', $sku);
                    }
                    $missingSkus = $missingItems->pluck('buyer_sku_code');
                    if ($sku === null) {
                        $presentCodes = array_column($clean, 'buyer_sku_code');
                        $mappedMissingSkus = DB::table('provider_mappings')
                            ->where('provider_id', $providerId)->whereNotNull('external_sku')
                            ->whereNotIn('external_sku', $presentCodes)->pluck('external_sku');
                        $missingSkus = $missingSkus->merge($mappedMissingSkus)->unique()->values();
                    } else {
                        $missingSkus = $missingSkus->push($sku)->unique()->values();
                    }
                    // Serialize orphan cleanup with checkout/fulfillment FK inserts. A
                    // child order/attempt must take a shared lock on its mapping row;
                    // locking candidate mappings first makes that insert either commit
                    // before our recheck or wait until after the mapping is removed.
                    $candidateMappings = DB::table('provider_mappings')
                        ->where('provider_id', $providerId)
                        ->whereIn('external_sku', $missingSkus)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get(['id', 'external_sku', 'product_package_id']);
                    $candidateIds = $candidateMappings->pluck('id');

                    // Locking reads are current reads under InnoDB REPEATABLE READ,
                    // unlike the earlier consistent-read subquery. Recheck after parent
                    // locks are acquired so a just-committed checkout is preserved.
                    $orderHistoryIds = DB::table('orders')->whereNotNull('provider_mapping_id')
                        ->whereIn('provider_mapping_id', $candidateIds)
                        ->lockForUpdate()->pluck('provider_mapping_id');
                    $attemptHistoryIds = DB::table('fulfillment_attempts')
                        ->whereIn('provider_mapping_id', $candidateIds)
                        ->lockForUpdate()->pluck('provider_mapping_id');
                    $historicalMappingIds = $orderHistoryIds->merge($attemptHistoryIds)->unique()->values();
                    $historicalSkus = $candidateMappings->whereIn('id', $historicalMappingIds)
                        ->pluck('external_sku')->unique();

                    // Keep catalogue identity only when an order/attempt needs the mapping for history.
                    $missingItems->update(['is_present' => false, 'updated_at' => now()]);
                    $missingItems->whereNotIn('buyer_sku_code', $historicalSkus)->delete();

                    DB::table('provider_mappings')->whereIn('id', $historicalMappingIds)
                        ->where('is_active', true)->update([
                            'is_active' => false, 'disabled_by_sync' => true, 'updated_at' => now(),
                        ]);

                    // Mappings without order or fulfillment history can be removed; historical FKs stay intact.
                    $removedMappings = DB::table('provider_mappings')->where('provider_id', $providerId)
                        ->whereIn('external_sku', $missingSkus)
                        ->whereNotIn('id', $historicalMappingIds)
                        ->delete();

                    // Nominal tanpa satu pun sumber aktif tidak bisa dijual. Matikan
                    // nominalnya supaya panel jujur, dengan penanda agar bisa
                    // dibedakan dari nominal yang dimatikan admin sendiri.
                    DB::table('product_packages')
                        ->whereIn('id', $candidateMappings->pluck('product_package_id')->unique()->all())
                        ->where('is_active', true)
                        ->whereNotExists(fn ($query) => $query
                            ->selectRaw('1')->from('provider_mappings')
                            ->whereColumn('provider_mappings.product_package_id', 'product_packages.id')
                            ->where('provider_mappings.is_active', true))
                        ->update(['is_active' => false, 'disabled_by_sync' => true, 'updated_at' => now()]);
                    $missingItems->whereNotIn('buyer_sku_code', $historicalSkus)->delete();
                    if ($sku === null) {
                        DB::table('system_settings')->updateOrInsert(['key' => 'digiflazz.last_catalog_sync'], [
                            'value' => json_encode([
                                'at' => now()->toIso8601String(), 'count' => count($clean),
                                'missing_marked_unavailable' => $historicalSkus->count(),
                                'mappings_deleted_without_history' => $removedMappings,
                            ], JSON_THROW_ON_ERROR),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }

                return count($clean);
            }, 3);
        } catch (\Throwable $error) {
            // Alert only after rollback. An alert failure must not mask the original error.
            try {
                app(AdminNotificationService::class)->recordDigiflazzSyncFailure($error);
            } catch (\Throwable $notificationError) {
                Log::warning('Digiflazz sync failure alert could not be recorded.', [
                    'exception_class' => $notificationError::class,
                ]);
            }
            throw $error;
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

    /**
     * Bootstrap and managed-import mappings retain a snapshot of the supplier's
     * brand, category, type and product name. A reused SKU must be rejected
     * without modifying an active mapping or its locked price.
     */
    public function verifiedIdentityMatches(object $item, mixed $fulfillmentConfig): bool
    {
        $config = is_string($fulfillmentConfig)
            ? json_decode($fulfillmentConfig, true)
            : $fulfillmentConfig;
        if ($config === null) {
            return true;
        }
        if (! is_array($config)) {
            return false;
        }
        if (! array_key_exists('auto_source_group', $config)) {
            return true;
        }

        $identity = $config['auto_source_group'];

        return is_string($identity) && preg_match('/\A[a-f0-9]{64}\z/D', $identity) === 1
            && hash_equals($identity, app(DigiflazzAutoSources::class)->key($item));
    }

    public function sellable(object $item): bool
    {
        return $this->available($item) && now()->diffInHours($item->synced_at, true) <= 24;
    }

    public function available(object $item): bool
    {
        if ((isset($item->is_present) && ! (bool) $item->is_present)
            || ! $item->buyer_active || ! $item->seller_active || (! $item->unlimited_stock && $item->stock <= 0)) {
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
