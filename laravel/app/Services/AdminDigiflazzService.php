<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminDigiflazzService
{
    private const SYNC_COOLDOWN_SECONDS = 60;
    private const SYNC_LOCK_MINUTES = 2;

    public function __construct(private readonly IntegrationConfigService $integrations)
    {
    }

    /** @return array{isAutoSync:bool} */
    public function pricingSettings(): array
    {
        DB::table('digiflazz_pricing_settings')->insertOrIgnore([
            'id' => 1,
            'is_auto_sync' => 1,
            'margin_type' => 'fixed',
            'margin_value' => 0,
            'updated_at' => now(),
        ]);
        $row = DB::table('digiflazz_pricing_settings')->where('id', 1)->first(['is_auto_sync']);

        return ['isAutoSync' => (bool) ($row->is_auto_sync ?? true)];
    }

    public function savePricingSettings(bool $isAutoSync): void
    {
        DB::table('digiflazz_pricing_settings')->updateOrInsert(
            ['id' => 1],
            ['is_auto_sync' => $isAutoSync ? 1 : 0, 'updated_at' => now()],
        );
    }

    /** @return array{count:int,lastSyncedAt:mixed,lastStartedAt:mixed} */
    public function cacheMeta(): array
    {
        $cache = DB::table('digiflazz_pricelist_cache')
            ->selectRaw('COUNT(*) AS aggregate_count, MAX(synced_at) AS synced_at')
            ->first();
        $state = DB::table('digiflazz_pricelist_sync_state')->where('id', 1)
            ->first(['last_started_at', 'last_success_at']);

        return [
            'count' => (int) ($cache->aggregate_count ?? 0),
            'lastSyncedAt' => $cache->synced_at ?? $state->last_success_at ?? null,
            'lastStartedAt' => $state->last_started_at ?? null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        return DB::table('digiflazz_pricelist_cache')
            ->orderBy('category')->orderBy('brand')->orderBy('product_name')->orderBy('price')
            ->get()
            ->map(fn ($row) => $this->catalogItem($row))
            ->all();
    }

    /** @return array<string,mixed> */
    public function syncAll(bool $force = false): array
    {
        $settings = $this->pricingSettings();
        if (!$settings['isAutoSync'] && !$force) {
            $cache = $this->cacheMeta();
            return [
                'updated' => 0,
                'cached' => $cache['count'],
                'lastSyncedAt' => $cache['lastSyncedAt'],
                'skipped' => true,
                'reason' => 'auto_sync_disabled',
            ];
        }

        $lock = $this->acquireSyncLock(false);
        if (!$lock['acquired']) {
            $cache = $this->cacheMeta();
            return [
                'updated' => 0,
                'cached' => $cache['count'],
                'lastSyncedAt' => $cache['lastSyncedAt'],
                'skipped' => true,
                'reason' => $lock['reason'],
            ];
        }

        $successful = false;
        try {
            $items = $this->fetchPriceList();
            $lastSyncedAt = $this->writeCache($items, $lock['token']);
            $result = $this->syncRows(null, null, $lock['token']);
            $successful = true;

            return [
                ...$result,
                'cached' => count($items),
                'lastSyncedAt' => $lastSyncedAt,
                'reason' => null,
            ];
        } finally {
            $this->releaseSyncLock($lock['token'], $successful);
        }
    }

    /** @return array{updated:int,skipped:bool} */
    public function syncProduct(int $productId): array
    {
        $lock = $this->acquireSyncLock(true);
        if (!$lock['acquired']) {
            throw new RuntimeException('Sync DigiFlazz sedang dikunci oleh perubahan konfigurasi atau sync lain.');
        }
        try {
            return $this->syncRows($productId, null, $lock['token']);
        } finally {
            $this->releaseSyncLock($lock['token'], false);
        }
    }

    /** @return array{updated:int,skipped:bool} */
    public function syncPackage(int $productId, string $packageSku): array
    {
        $lock = $this->acquireSyncLock(true);
        if (!$lock['acquired']) {
            throw new RuntimeException('Sync DigiFlazz sedang dikunci oleh perubahan konfigurasi atau sync lain.');
        }
        try {
            return $this->syncRows($productId, trim($packageSku), $lock['token']);
        } finally {
            $this->releaseSyncLock($lock['token'], false);
        }
    }

    /** @return array<string,mixed> */
    public function updatePackagePricing(int $packageId, int $maxPrice, string $marginType, int $marginValue): array
    {
        return DB::transaction(function () use ($packageId, $maxPrice, $marginType, $marginValue): array {
            $row = DB::table('product_packages as pp')
                ->leftJoin('digiflazz_pricelist_cache as c', 'c.buyer_sku_code', '=', 'pp.provider_sku')
                ->where('pp.id', $packageId)
                ->where('pp.provider_code', 'digiflazz')
                ->whereNotNull('pp.provider_sku')
                ->lockForUpdate()
                ->first(['pp.id', 'pp.supplier_price', 'c.price as cached_price']);
            if (!$row) throw new RuntimeException('Nominal DigiFlazz tidak ditemukan.');

            $currentCost = (int) ($row->cached_price ?? $row->supplier_price ?? 0);
            if ($currentCost <= 0) {
                throw new RuntimeException('Harga DigiFlazz belum tersedia. Jalankan Sync Pricelist terlebih dahulu.');
            }

            $maxPrice = max(1, $maxPrice);
            $marginValue = max(0, $marginValue);
            $sellingPrice = $this->sellingPrice($maxPrice, $marginType, $marginValue);

            DB::table('product_packages')->where('id', $packageId)->update([
                'provider_max_price' => $maxPrice,
                'margin_type' => $marginType,
                'margin_value' => $marginValue,
                'pricing_mode' => 'auto',
                'supplier_price' => $currentCost,
                'price' => $sellingPrice,
                'updated_at' => now(),
            ]);

            return [
                'packageId' => $packageId,
                'currentCost' => $currentCost,
                'maxPrice' => $maxPrice,
                'marginType' => $marginType,
                'marginValue' => $marginValue,
                'sellingPrice' => $sellingPrice,
            ];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function dashboard(string $role): array
    {
        $monitor = $this->monitor();
        $cache = $this->cacheMeta();
        $readiness = $this->readiness();
        $balance = null;
        $reason = $readiness['reason'];

        if ($role === 'super_admin' && $readiness['ready']) {
            try {
                $balance = $this->balance();
                $reason = null;
            } catch (Throwable $error) {
                $reason = $error->getMessage() ?: 'Koneksi DigiFlazz gagal.';
            }
        }

        return [
            ...$monitor,
            'cache' => $cache,
            'api' => [
                'ready' => $readiness['ready'] && $reason === null,
                'balance' => $balance,
                'environment' => $role === 'super_admin' ? $readiness['environment'] : null,
                'reason' => $reason,
            ],
        ];
    }

    /** @return array{items:array<int,array<string,mixed>>,summary:array<string,int>} */
    public function monitor(): array
    {
        $rows = DB::table('product_packages as pp')
            ->join('products as p', 'p.id', '=', 'pp.product_id')
            ->leftJoin('digiflazz_seller_monitor as m', 'm.package_id', '=', 'pp.id')
            ->leftJoin('digiflazz_pricelist_cache as c', 'c.buyer_sku_code', '=', 'pp.provider_sku')
            ->where('pp.provider_code', 'digiflazz')
            ->whereNotNull('pp.provider_sku')
            ->orderBy('c.category')->orderBy('c.brand')->orderBy('p.name')->orderBy('pp.sort_order')
            ->get([
                'pp.id as package_id', 'p.name as product_name', 'pp.label as package_label', 'pp.provider_sku',
                'c.category', 'c.brand', 'm.seller_name', 'm.current_price', 'c.price as cached_price',
                'm.baseline_price', 'pp.provider_max_price', 'pp.margin_type', 'pp.margin_value', 'pp.price as selling_price',
                'm.buyer_product_status', 'm.seller_product_status', 'm.unlimited_stock', 'm.stock', 'm.multi',
                'm.start_cut_off', 'm.end_cut_off', 'm.description as monitor_description', 'c.description as cached_description',
                'm.health', 'm.alert_reason', 'm.last_checked_at',
            ]);

        $items = $rows->map(fn ($row) => [
            'packageId' => (int) $row->package_id,
            'productName' => (string) $row->product_name,
            'packageLabel' => (string) $row->package_label,
            'providerSku' => (string) $row->provider_sku,
            'category' => trim((string) ($row->category ?? '')) ?: 'Tanpa Kategori',
            'brand' => trim((string) ($row->brand ?? '')) ?: (string) $row->product_name,
            'sellerName' => $row->seller_name,
            'currentPrice' => $row->current_price !== null ? (int) $row->current_price : ($row->cached_price !== null ? (int) $row->cached_price : null),
            'baselinePrice' => $row->baseline_price === null ? null : (int) $row->baseline_price,
            'maxPrice' => $row->provider_max_price === null ? null : (int) $row->provider_max_price,
            'marginType' => $row->margin_type ?: 'fixed',
            'marginValue' => (int) ($row->margin_value ?? 0),
            'sellingPrice' => (int) $row->selling_price,
            'buyerProductStatus' => $row->buyer_product_status === null ? true : (bool) $row->buyer_product_status,
            'sellerProductStatus' => $row->seller_product_status === null ? true : (bool) $row->seller_product_status,
            'unlimitedStock' => $row->unlimited_stock === null ? false : (bool) $row->unlimited_stock,
            'stock' => (int) ($row->stock ?? 0),
            'multi' => $row->multi === null ? false : (bool) $row->multi,
            'startCutOff' => $row->start_cut_off,
            'endCutOff' => $row->end_cut_off,
            'description' => $row->monitor_description ?: $row->cached_description,
            'health' => $row->health ?: 'unknown',
            'alertReason' => $row->alert_reason,
            'lastCheckedAt' => $row->last_checked_at,
        ])->all();

        return [
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'healthy' => count(array_filter($items, fn ($item) => $item['health'] === 'healthy')),
                'warning' => count(array_filter($items, fn ($item) => $item['health'] === 'warning')),
                'critical' => count(array_filter($items, fn ($item) => $item['health'] === 'critical')),
                'unknown' => count(array_filter($items, fn ($item) => $item['health'] === 'unknown')),
            ],
        ];
    }

    /** @return array{ready:bool,environment:?string,reason:?string} */
    public function readiness(): array
    {
        try {
            $config = $this->runtimeConfig(true, true);
            return ['ready' => true, 'environment' => $config['environment'], 'reason' => null];
        } catch (Throwable $error) {
            return [
                'ready' => false,
                'environment' => null,
                'reason' => $error->getMessage() ?: 'Konfigurasi DigiFlazz belum lengkap.',
            ];
        }
    }

    public function balance(): int
    {
        $config = $this->runtimeConfig(false, true);
        $cacheKey = 'lfamilia:digiflazz:balance:'.$config['environment'];

        return (int) Cache::remember($cacheKey, 60, function () use ($config): int {
            $parts = parse_url($config['transactionUrl']);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                throw new RuntimeException('URL transaksi DigiFlazz belum valid.');
            }
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $balanceUrl = strtolower((string) $parts['scheme']).'://'.$parts['host'].$port.'/v1/cek-saldo';
            $response = DigiflazzEndpoint::request()->timeout(12)->post($balanceUrl, [
                'cmd' => 'deposit',
                'username' => $config['username'],
                'sign' => md5($config['username'].$config['apiKey'].'depo'),
            ]);
            $payload = $response->json();
            $deposit = is_array($payload) ? ($payload['data']['deposit'] ?? null) : null;
            if (!$response->successful() || !is_numeric($deposit)) {
                $message = is_array($payload) ? trim((string) ($payload['data']['message'] ?? '')) : '';
                $rc = is_array($payload) ? trim((string) ($payload['data']['rc'] ?? '')) : '';
                throw new RuntimeException($this->providerMessage($message, $rc, 'Saldo DigiFlazz tidak dapat dibaca.'));
            }

            return (int) $deposit;
        });
    }

    /** @return array{acquired:bool,token?:string,reason?:string} */
    private function acquireSyncLock(bool $targeted): array
    {
        return DB::transaction(function () use ($targeted): array {
            DB::table('digiflazz_pricelist_sync_state')->insertOrIgnore(['id' => 1]);
            DB::table('digiflazz_runtime_state')->insertOrIgnore(['id' => 1, 'generation' => 1]);

            $runtime = DB::table('digiflazz_runtime_state')->where('id', 1)->lockForUpdate()->first();
            if ($runtime && $runtime->maintenance_token && $runtime->maintenance_until
                && CarbonImmutable::parse($runtime->maintenance_until)->isFuture()) {
                return ['acquired' => false, 'reason' => 'maintenance'];
            }

            $state = DB::table('digiflazz_pricelist_sync_state')->where('id', 1)->lockForUpdate()->first();
            $now = CarbonImmutable::now();
            if ($state?->lock_token && $state->locked_until
                && CarbonImmutable::parse($state->locked_until)->greaterThan($now)) {
                return ['acquired' => false, 'reason' => 'sync_in_progress'];
            }
            if (!$targeted && $state?->last_started_at
                && CarbonImmutable::parse($state->last_started_at)->greaterThan($now->subSeconds(self::SYNC_COOLDOWN_SECONDS))) {
                return ['acquired' => false, 'reason' => 'cooldown'];
            }

            $token = (string) Str::uuid();
            $values = [
                'lock_token' => $token,
                'locked_until' => $now->addMinutes(self::SYNC_LOCK_MINUTES),
            ];
            if (!$targeted) $values['last_started_at'] = $now;
            DB::table('digiflazz_pricelist_sync_state')->where('id', 1)->update($values);

            return ['acquired' => true, 'token' => $token];
        }, 3);
    }

    private function releaseSyncLock(string $token, bool $successful): void
    {
        $values = ['lock_token' => null, 'locked_until' => null];
        if ($successful) $values['last_success_at'] = now();
        DB::table('digiflazz_pricelist_sync_state')->where('id', 1)->where('lock_token', $token)->update($values);
    }

    private function assertLock(string $token, bool $renew = true): void
    {
        $state = DB::table('digiflazz_pricelist_sync_state')->where('id', 1)->first(['lock_token', 'locked_until']);
        $maintenance = DB::table('digiflazz_runtime_state')
            ->where('id', 1)->whereNotNull('maintenance_token')->where('maintenance_until', '>', now())->exists();
        if (!$state || (string) $state->lock_token !== $token || !$state->locked_until
            || CarbonImmutable::parse($state->locked_until)->isPast() || $maintenance) {
            throw new RuntimeException('Lock sync DigiFlazz kedaluwarsa sebelum data dapat disimpan.');
        }
        if ($renew) {
            DB::table('digiflazz_pricelist_sync_state')->where('id', 1)->where('lock_token', $token)->update([
                'locked_until' => now()->addMinutes(self::SYNC_LOCK_MINUTES),
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchPriceList(): array
    {
        $config = $this->runtimeConfig(true, false);
        $response = DigiflazzEndpoint::request()->timeout(20)->post($config['priceListUrl'], [
            'cmd' => 'prepaid',
            'username' => $config['username'],
            'sign' => md5($config['username'].$config['apiKey'].'pricelist'),
        ]);
        $payload = $response->json();
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;

        if (!$response->successful() || !is_array($data) || !array_is_list($data)) {
            $message = is_array($data) ? trim((string) ($data['message'] ?? '')) : (is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '');
            $rc = is_array($data) ? trim((string) ($data['rc'] ?? '')) : '';
            throw new RuntimeException($this->providerMessage($message, $rc, 'DigiFlazz price list gagal dimuat.'));
        }

        $items = [];
        foreach ($data as $raw) {
            if (!is_array($raw)) continue;
            $sku = trim((string) ($raw['buyer_sku_code'] ?? ''));
            $price = (int) ($raw['price'] ?? 0);
            if ($sku === '' || $price <= 0) continue;
            $items[] = [
                'buyerSkuCode' => $sku,
                'productName' => trim((string) ($raw['product_name'] ?? '')) ?: $sku,
                'category' => trim((string) ($raw['category'] ?? '')),
                'brand' => trim((string) ($raw['brand'] ?? '')),
                'type' => trim((string) ($raw['type'] ?? '')),
                'sellerName' => trim((string) ($raw['seller_name'] ?? '')),
                'price' => $price,
                'buyerProductStatus' => $this->boolValue($raw['buyer_product_status'] ?? true, true),
                'sellerProductStatus' => $this->boolValue($raw['seller_product_status'] ?? true, true),
                'unlimitedStock' => $this->boolValue($raw['unlimited_stock'] ?? false, false),
                'stock' => max(0, (int) ($raw['stock'] ?? 0)),
                'multi' => $this->boolValue($raw['multi'] ?? false, false),
                'startCutOff' => trim((string) ($raw['start_cut_off'] ?? '00:00')) ?: '00:00',
                'endCutOff' => trim((string) ($raw['end_cut_off'] ?? '00:00')) ?: '00:00',
                'description' => trim((string) ($raw['desc'] ?? '')),
            ];
        }
        if ($items === []) {
            throw new RuntimeException('DigiFlazz mengembalikan price list kosong; cache lama dipertahankan.');
        }

        return $items;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function writeCache(array $items, string $token): string
    {
        $syncedAt = now()->utc()->format('Y-m-d H:i:s');
        foreach (array_chunk($items, 100) as $chunk) {
            $this->assertLock($token);
            $rows = array_map(fn (array $item) => [
                'buyer_sku_code' => $item['buyerSkuCode'],
                'product_name' => $item['productName'],
                'category' => $item['category'],
                'brand' => $item['brand'],
                'type' => $item['type'],
                'seller_name' => $item['sellerName'],
                'price' => $item['price'],
                'buyer_product_status' => $item['buyerProductStatus'] ? 1 : 0,
                'seller_product_status' => $item['sellerProductStatus'] ? 1 : 0,
                'unlimited_stock' => $item['unlimitedStock'] ? 1 : 0,
                'stock' => $item['stock'],
                'multi' => $item['multi'] ? 1 : 0,
                'start_cut_off' => $item['startCutOff'],
                'end_cut_off' => $item['endCutOff'],
                'description' => $item['description'],
                'synced_at' => $syncedAt,
            ], $chunk);
            DB::table('digiflazz_pricelist_cache')->upsert(
                $rows,
                ['buyer_sku_code'],
                [
                    'product_name','category','brand','type','seller_name','price','buyer_product_status',
                    'seller_product_status','unlimited_stock','stock','multi','start_cut_off','end_cut_off','description','synced_at',
                ],
            );
        }
        $this->assertLock($token);
        DB::table('digiflazz_pricelist_cache')->where('synced_at', '<>', $syncedAt)->delete();

        return $syncedAt;
    }

    /** @return array{updated:int,skipped:bool} */
    private function syncRows(?int $productId, ?string $packageSku, string $token): array
    {
        $this->assertLock($token);
        $source = DB::table('digiflazz_pricelist_cache')->get()->keyBy('buyer_sku_code');
        if ($source->isEmpty()) {
            throw new RuntimeException('Cache pricelist DigiFlazz masih kosong. Jalankan Sync Pricelist sekali terlebih dahulu.');
        }

        $query = DB::table('product_packages as pp')
            ->leftJoin('digiflazz_seller_monitor as m', 'm.package_id', '=', 'pp.id')
            ->where('pp.provider_code', 'digiflazz')
            ->whereNotNull('pp.provider_sku')
            ->select([
                'pp.id', 'pp.product_id', 'pp.sku', 'pp.provider_sku', 'pp.provider_max_price', 'pp.margin_type', 'pp.margin_value',
                'm.seller_name as previous_seller_name', 'm.baseline_price as previous_baseline_price',
            ]);
        if ($productId !== null) $query->where('pp.product_id', $productId);
        if ($packageSku !== null) {
            $query->where(function ($nested) use ($packageSku) {
                $nested->where('pp.sku', $packageSku)->orWhere('pp.provider_sku', $packageSku);
            });
        }
        $rows = $query->get();
        if ($productId !== null && $rows->isEmpty()) {
            throw new RuntimeException($packageSku !== null
                ? 'Nominal DigiFlazz belum memiliki SKU provider yang valid.'
                : 'Produk ini belum memiliki nominal DigiFlazz yang dapat disinkronkan.');
        }

        $updated = 0;
        DB::transaction(function () use ($rows, $source, $token, &$updated): void {
            $this->assertLock($token);
            foreach ($rows as $row) {
                $item = $source->get($row->provider_sku);
                if (!$item || (int) $item->price <= 0) continue;
                $updated++;
                $maxPrice = (int) $row->provider_max_price > 0 ? (int) $row->provider_max_price : (int) $item->price;
                $marginType = in_array($row->margin_type, ['fixed','percent'], true) ? (string) $row->margin_type : 'fixed';
                $marginValue = max(0, (int) ($row->margin_value ?? 0));

                DB::table('product_packages')->where('id', $row->id)->update([
                    'supplier_price' => (int) $item->price,
                    'provider_max_price' => $maxPrice,
                    'price' => $this->sellingPrice($maxPrice, $marginType, $marginValue),
                    'supplier_synced_at' => now(),
                    'updated_at' => now(),
                ]);

                $snapshot = $this->evaluateSnapshot([
                    'sellerName' => trim((string) $item->seller_name) ?: null,
                    'currentPrice' => (int) $item->price,
                    'previousSellerName' => $row->previous_seller_name,
                    'previousBaselinePrice' => $row->previous_baseline_price,
                    'buyerProductStatus' => (bool) $item->buyer_product_status,
                    'sellerProductStatus' => (bool) $item->seller_product_status,
                    'unlimitedStock' => (bool) $item->unlimited_stock,
                    'stock' => (int) $item->stock,
                    'startCutOff' => (string) $item->start_cut_off,
                    'endCutOff' => (string) $item->end_cut_off,
                ]);

                DB::table('digiflazz_seller_monitor')->upsert([[
                    'package_id' => (int) $row->id,
                    'seller_name' => trim((string) $item->seller_name) ?: null,
                    'current_price' => (int) $item->price,
                    'baseline_price' => $snapshot['baselinePrice'],
                    'buyer_product_status' => (bool) $item->buyer_product_status ? 1 : 0,
                    'seller_product_status' => (bool) $item->seller_product_status ? 1 : 0,
                    'unlimited_stock' => (bool) $item->unlimited_stock ? 1 : 0,
                    'stock' => (int) $item->stock,
                    'multi' => (bool) $item->multi ? 1 : 0,
                    'start_cut_off' => (string) $item->start_cut_off,
                    'end_cut_off' => (string) $item->end_cut_off,
                    'description' => (string) $item->description,
                    'health' => $snapshot['health'],
                    'alert_reason' => $snapshot['alertReason'],
                    'last_checked_at' => now(),
                ]], ['package_id'], [
                    'seller_name','current_price','baseline_price','buyer_product_status','seller_product_status',
                    'unlimited_stock','stock','multi','start_cut_off','end_cut_off','description','health','alert_reason','last_checked_at',
                ]);
            }
            $this->assertLock($token);
        }, 3);

        if ($productId !== null && $updated === 0) {
            throw new RuntimeException($packageSku !== null
                ? 'SKU nominal tidak ditemukan pada cache pricelist DigiFlazz.'
                : 'Tidak ada SKU nominal produk ini pada cache pricelist DigiFlazz.');
        }

        return ['updated' => $updated, 'skipped' => false];
    }

    /** @param array<string,mixed> $input @return array{baselinePrice:int,health:string,alertReason:?string} */
    private function evaluateSnapshot(array $input): array
    {
        $sameSeller = $input['sellerName'] && $input['sellerName'] === $input['previousSellerName']
            && (int) $input['previousBaselinePrice'] > 0;
        $baseline = $sameSeller ? (int) $input['previousBaselinePrice'] : (int) $input['currentPrice'];
        $critical = [];
        $warnings = [];

        if (!$input['buyerProductStatus']) $critical[] = 'Produk Buyer DigiFlazz sedang nonaktif.';
        if (!$input['sellerProductStatus']) $critical[] = 'Seller DigiFlazz sedang nonaktif.';
        if (!$input['unlimitedStock'] && (int) $input['stock'] <= 0) $critical[] = 'Stok seller habis.';
        if (!$input['unlimitedStock'] && (int) $input['stock'] > 0 && (int) $input['stock'] <= 5) {
            $warnings[] = 'Stok seller menipis: '.((int) $input['stock']).' tersisa.';
        }
        if ($this->insideCutoff((string) $input['startCutOff'], (string) $input['endCutOff'])) {
            $warnings[] = 'Seller sedang cut-off '.$input['startCutOff'].'–'.$input['endCutOff'].' WIB.';
        }
        if ($baseline > 0 && (int) $input['currentPrice'] > $baseline) {
            $increase = (((int) $input['currentPrice'] - $baseline) / $baseline) * 100;
            if ($increase >= 3) $warnings[] = 'Harga seller naik '.number_format($increase, 1).'% dari baseline. Cek seller lain di DigiFlazz.';
        }

        return [
            'baselinePrice' => $baseline,
            'health' => $critical ? 'critical' : ($warnings ? 'warning' : 'healthy'),
            'alertReason' => ($critical || $warnings) ? implode(' ', [...$critical, ...$warnings]) : null,
        ];
    }

    private function insideCutoff(string $start, string $end): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $start, $startParts)
            || !preg_match('/^(\d{2}):(\d{2})$/', $end, $endParts)) return false;
        $startMinute = ((int) $startParts[1] * 60) + (int) $startParts[2];
        $endMinute = ((int) $endParts[1] * 60) + (int) $endParts[2];
        if ($startMinute === $endMinute) return false;
        $now = CarbonImmutable::now('Asia/Jakarta');
        $minute = ($now->hour * 60) + $now->minute;
        return $startMinute < $endMinute
            ? $minute >= $startMinute && $minute < $endMinute
            : $minute >= $startMinute || $minute < $endMinute;
    }

    private function sellingPrice(int $basePrice, string $marginType, int $marginValue): int
    {
        return $marginType === 'percent'
            ? max(1, (int) ceil($basePrice * (100 + $marginValue) / 100))
            : max(1, $basePrice + $marginValue);
    }

    /** @return array<string,mixed> */
    private function catalogItem(object $row): array
    {
        return [
            'buyerSkuCode' => (string) $row->buyer_sku_code,
            'productName' => (string) $row->product_name,
            'category' => (string) $row->category,
            'brand' => (string) $row->brand,
            'type' => (string) $row->type,
            'sellerName' => (string) $row->seller_name,
            'price' => (int) $row->price,
            'buyerProductStatus' => (bool) $row->buyer_product_status,
            'sellerProductStatus' => (bool) $row->seller_product_status,
            'unlimitedStock' => (bool) $row->unlimited_stock,
            'stock' => (int) $row->stock,
            'multi' => (bool) $row->multi,
            'startCutOff' => (string) $row->start_cut_off,
            'endCutOff' => (string) $row->end_cut_off,
            'description' => (string) $row->description,
        ];
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return $value != 0;
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true','1','yes','on'], true)) return true;
            if (in_array($normalized, ['false','0','no','off'], true)) return false;
        }
        return $default;
    }

    /** @return array{environment:string,username:string,apiKey:string,priceListUrl?:string,transactionUrl?:string} */
    private function runtimeConfig(bool $needsPriceList, bool $needsTransaction): array
    {
        $runtime = $this->integrations->digiflazzRuntime();
        $environment = $runtime['environment'];
        $username = $runtime['username'];
        $apiKey = $runtime['apiKey'];
        if ($username === '' || $apiKey === '') {
            throw new RuntimeException('Kredensial DigiFlazz belum lengkap.');
        }

        $result = ['environment' => $environment, 'username' => $username, 'apiKey' => $apiKey];
        if ($needsPriceList) {
            $url = $runtime['priceListUrl'];
            $result['priceListUrl'] = DigiflazzEndpoint::requireOfficial($url, '/v1/price-list');
        }
        if ($needsTransaction) {
            $url = $runtime['transactionApiUrl'];
            $result['transactionUrl'] = DigiflazzEndpoint::requireOfficial($url, '/v1/transaction');
        }

        return $result;
    }

    private function providerMessage(string $message, string $rc, string $fallback): string
    {
        $message = $message !== '' ? $message : $fallback;
        return $rc !== '' ? '[RC '.$rc.'] '.$message : $message;
    }
}
