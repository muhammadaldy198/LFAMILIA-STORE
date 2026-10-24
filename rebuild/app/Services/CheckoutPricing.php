<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutPricing
{
    /**
     * @return array<string, mixed>
     */
    public function forPackage(int $packageId, bool $lock = false): array
    {
        return $this->quoteForPackage($packageId, $lock, false);
    }

    /** @return array<string, mixed> */
    public function previewForPackage(int $packageId): array
    {
        return $this->forPackages([$packageId], true)[$packageId]
            ?? throw ValidationException::withMessages(['package_id' => 'Produk atau nominal tidak tersedia.']);
    }

    /** @return array<string, mixed> */
    private function quoteForPackage(int $packageId, bool $lock, bool $includeInactive): array
    {
        $contextQuery = DB::table('product_packages as packages')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('packages.id', $packageId)
            ->when(! $includeInactive, fn ($query) => $query
                ->where('packages.is_active', true)
                ->where('products.is_active', true)
                ->where('categories.is_active', true))
            ->select(
                'packages.id as package_id',
                'packages.code as package_code',
                'packages.name as package_name',
                'packages.nominal_value',
                'packages.pricing_mode', 'packages.margin_percent as package_margin_percent',
                'packages.sell_markup_percent as package_sell_markup_percent',
                'packages.margin_fixed_idr', 'packages.sell_price_idr',
                'products.id as product_id',
                'products.name as product_name',
                'products.slug as product_slug',
                'products.category_id',
                'products.fulfillment_mode',
                'categories.name as category_name'
            );

        if ($lock) {
            $contextQuery->lockForUpdate();
        }

        $context = $contextQuery->first();
        if (! $context) {
            throw ValidationException::withMessages([
                'package_id' => 'Produk atau nominal tidak tersedia.',
            ]);
        }

        $mappingQuery = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->where('mappings.product_package_id', $context->package_id)
            ->when(! $includeInactive, fn ($query) => $query
                ->where('mappings.is_active', true)
                ->where('providers.is_active', true))
            ->where('providers.fulfillment_mode', $context->fulfillment_mode)
            ->whereRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr) > 0')
            ->where(function ($query): void {
                $query->whereNull('mappings.max_price_idr')
                    ->orWhereRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr) <= mappings.max_price_idr');
            })
            ->orderBy('mappings.priority')
            ->orderByRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr)')
            ->orderBy('mappings.id')
            ->select(
                'mappings.id as mapping_id',
                'mappings.external_sku',
                DB::raw('COALESCE(mappings.store_cost_idr, mappings.cost_idr) as cost_idr'),
                'mappings.store_cost_idr',
                'mappings.max_price_idr',
                'mappings.is_manual_price',
                'mappings.fulfillment_config',
                'providers.code as provider_code'
            );

        if ($lock) {
            $mappingQuery->lockForUpdate();
        }

        $eligibleMappings = $mappingQuery->get()->filter(function (object $candidate): bool {
            if ($candidate->provider_code === 'VOUCHER_STOCK') {
                $config = is_string($candidate->fulfillment_config)
                    ? (json_decode($candidate->fulfillment_config, true) ?: [])
                    : (is_array($candidate->fulfillment_config) ? $candidate->fulfillment_config : []);
                $stockKey = trim((string) ($config['stock_key'] ?? ''));

                return $stockKey !== '' && app(VoucherStockService::class)->available($stockKey);
            }
            if ($candidate->provider_code !== 'DIGIFLAZZ') {
                return true;
            }
            $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', $candidate->external_sku)->first();
            if ($candidate->is_manual_price) {
                // A locked accounting cost must not conceal a supplier price spike.
                // A missing catalog price is unsafe for manually locked SKUs.
                $candidate->live_cost_idr = $item?->price_idr;
            }

            return $item !== null && app(DigiflazzCatalogService::class)->sellable($item)
                && app(DigiflazzCatalogService::class)->verifiedIdentityMatches($item, $candidate->fulfillment_config)
                && ! $this->sellerPriceExceedsStoreCost($candidate, $item);
        })->values();

        return $this->quoteForContext($context, $eligibleMappings);
    }

    /**
     * Batch preview quotes; checkout continues to use forPackage() with locking.
     *
     * @param  array<int, int>  $packageIds
     * @return array<int, array<string, mixed>>
     */
    public function forPackages(array $packageIds, bool $includeInactivePackages = false): array
    {
        $packageIds = array_values(array_unique(array_filter(
            array_map('intval', $packageIds),
            fn (int $id): bool => $id > 0
        )));
        if ($packageIds === []) {
            return [];
        }

        $contextsQuery = DB::table('product_packages as packages')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('packages.id', $packageIds)
            ->when(! $includeInactivePackages, fn ($query) => $query
                ->where('packages.is_active', true)
                ->where('products.is_active', true)
                ->where('categories.is_active', true))
            ->select(
                'packages.id as package_id',
                'packages.code as package_code',
                'packages.name as package_name',
                'packages.nominal_value',
                'packages.pricing_mode', 'packages.margin_percent as package_margin_percent',
                'packages.sell_markup_percent as package_sell_markup_percent',
                'packages.margin_fixed_idr', 'packages.sell_price_idr',
                'products.id as product_id',
                'products.name as product_name',
                'products.slug as product_slug',
                'products.category_id',
                'products.fulfillment_mode',
                'categories.name as category_name'
            );
        $contexts = $contextsQuery->get()->keyBy('package_id');
        if ($contexts->isEmpty()) {
            return [];
        }

        $mappingQuery = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages as packages', 'packages.id', '=', 'mappings.product_package_id')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->whereIn('mappings.product_package_id', $contexts->keys()->all())
            ->where('mappings.is_active', true)
            ->where('providers.is_active', true)
            ->whereColumn('providers.fulfillment_mode', 'products.fulfillment_mode')
            ->whereRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr) > 0')
            ->where(function ($query): void {
                $query->whereNull('mappings.max_price_idr')
                    ->orWhereRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr) <= mappings.max_price_idr');
            })
            ->orderBy('mappings.priority')
            ->orderByRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr)')
            ->orderBy('mappings.id')
            ->select(
                'mappings.product_package_id as package_id',
                'mappings.id as mapping_id',
                'mappings.external_sku',
                DB::raw('COALESCE(mappings.store_cost_idr, mappings.cost_idr) as cost_idr'),
                'mappings.store_cost_idr',
                'mappings.max_price_idr',
                'mappings.is_manual_price',
                'mappings.fulfillment_config',
                'providers.code as provider_code'
            );
        $mappings = $mappingQuery->get()->groupBy('package_id');
        $skus = $mappings->flatten(1)->where('provider_code', 'DIGIFLAZZ')
            ->pluck('external_sku')->unique()->values()->all();
        $items = $skus === [] ? collect() : DB::table('digiflazz_catalog_items')
            ->whereIn('buyer_sku_code', $skus)->get()->keyBy('buyer_sku_code');
        $quotes = [];

        foreach ($contexts as $id => $context) {
            $eligible = $mappings->get($id, collect())->filter(function (object $candidate) use ($items): bool {
                if ($candidate->provider_code === 'VOUCHER_STOCK') {
                    $config = is_string($candidate->fulfillment_config)
                        ? (json_decode($candidate->fulfillment_config, true) ?: [])
                        : (is_array($candidate->fulfillment_config) ? $candidate->fulfillment_config : []);
                    $stockKey = trim((string) ($config['stock_key'] ?? ''));

                    return $stockKey !== '' && app(VoucherStockService::class)->available($stockKey);
                }
                if ($candidate->provider_code !== 'DIGIFLAZZ') {
                    return true;
                }
                $item = $items->get($candidate->external_sku);
                if ($candidate->is_manual_price) {
                    $candidate->live_cost_idr = $item?->price_idr;
                }

                return $item !== null && app(DigiflazzCatalogService::class)->sellable($item)
                && app(DigiflazzCatalogService::class)->verifiedIdentityMatches($item, $candidate->fulfillment_config)
                && ! $this->sellerPriceExceedsStoreCost($candidate, $item);
            })->values();

            try {
                $quotes[(int) $id] = $this->quoteForContext($context, $eligible);
            } catch (ValidationException) {
                // One unavailable nominal must not hide other packages.
            }
        }

        return $quotes;
    }

    /**
     * @param  Collection<int, object>  $eligibleMappings
     * @return array<string, mixed>
     */
    private function quoteForContext(object $context, Collection $eligibleMappings): array
    {
        // Reject an expensive/missing live supplier SKU even if the admin
        // deliberately keeps its accounting cost and per-source cap fixed.
        $eligibleMappings = $eligibleMappings->filter(function (object $candidate): bool {
            if ($candidate->provider_code !== 'DIGIFLAZZ' || ! $candidate->is_manual_price) {
                return true;
            }

            return $candidate->live_cost_idr !== null
                && (int) $candidate->live_cost_idr > 0
                && (int) $candidate->live_cost_idr <= (int) $candidate->max_price_idr;
        })->values();

        // The existing SELL_PRICE rule does not allow a negative margin.
        // Skip an unaffordable source so an eligible backup can still be used.
        // There is no fixed minimum-margin percentage.
        if ($context->pricing_mode === 'SELL_PRICE') {
            $eligibleMappings = $eligibleMappings->filter(
                fn (object $candidate): bool => (int) $candidate->cost_idr <= (int) $context->sell_price_idr
            )->values();
        }

        $mapping = $eligibleMappings->first();
        if ($mapping === null) {
            throw ValidationException::withMessages([
                'package_id' => 'Nominal sedang tidak tersedia untuk checkout.',
            ]);
        }

        $cost = (int) ($mapping->store_cost_idr ?? $mapping->cost_idr);
        $legacyDigiflazzPricing = $mapping->provider_code === 'DIGIFLAZZ'
            && $mapping->store_cost_idr === null
            && $context->package_sell_markup_percent === null;
        // Baris katalog lama (belum punya modal toko baru dan tanpa override markup)
        // tetap memakai margin lamanya. Semua baris lain — termasuk nominal manual
        // yang modal tokonya diisi admin — memakai harga jual modal toko × markup.
        $legacyPricing = $mapping->store_cost_idr === null
            && $context->package_sell_markup_percent === null;
        // Mode harga nominal yang dipilih admin (persen/patok) selalu menang atas
        // markup. Markup hanya berlaku untuk mode PRODUCT_MARGIN.
        $markupPercent = in_array($context->pricing_mode, ['SELL_PRICE', 'PERCENT', 'FIXED'], true)
            ? null
            : ($legacyPricing
                ? null
                : ($context->package_sell_markup_percent ?? $this->globalSellMarkupPercent()));
        $percent = $context->package_margin_percent ?? $this->globalSellMarkupPercent();
        $margin = $markupPercent !== null
            ? $this->margin($cost, (string) $markupPercent)
            : match ($context->pricing_mode) {
                'FIXED' => (int) $context->margin_fixed_idr,
                'SELL_PRICE' => (int) $context->sell_price_idr - $cost,
                default => $this->margin($cost, (string) $percent),
            };
        if ($margin < 0) {
            throw ValidationException::withMessages(['package_id' => 'Harga jual nominal berada di bawah modal.']);
        }

        $maxPrice = $mapping->max_price_idr !== null ? (int) $mapping->max_price_idr : null;
        if ($mapping->provider_code === 'DIGIFLAZZ') {
            // Legacy catalog entries retain their provider-price tolerance.
            $maxPrice = $mapping->is_manual_price
                ? (int) $mapping->max_price_idr
                : ($legacyDigiflazzPricing
                    ? $this->digiflazzMaxPrice((int) $eligibleMappings->where('provider_code', 'DIGIFLAZZ')->max('cost_idr'))
                    : $cost);
        }

        return [
            'product_id' => (int) $context->product_id,
            'product_name' => $context->product_name,
            'product_slug' => $context->product_slug,
            'category_id' => (int) $context->category_id,
            'category_name' => $context->category_name,
            'fulfillment_mode' => $context->fulfillment_mode,
            'margin_percent' => (string) ($context->package_margin_percent ?? $this->globalSellMarkupPercent()),
            'package_id' => (int) $context->package_id,
            'package_code' => $context->package_code,
            'package_name' => $context->package_name,
            'nominal_value' => $context->nominal_value !== null ? (int) $context->nominal_value : null,
            'provider_mapping_id' => (int) $mapping->mapping_id,
            'provider_code' => $mapping->provider_code,
            'provider_sku' => $mapping->external_sku,
            // Kunci stok kode hanya untuk sumber VOUCHER_STOCK; dipakai
            // CheckoutService untuk memesan kode sebelum pembayaran.
            'stock_key' => $this->stockKey($mapping),
            // Snapshot the admin's exact manual cap, or the existing
            // automatic-source cap, for fulfillment and reconciliation.
            'max_price_idr' => $maxPrice,
            'cost_idr' => $cost,
            'margin_idr' => $margin,
            'subtotal_idr' => $cost + $margin,
        ];
    }

    /**
     * Kunci stok hanya berlaku untuk sumber VOUCHER_STOCK.
     */
    private function stockKey(object $mapping): ?string
    {
        if (($mapping->provider_code ?? null) !== 'VOUCHER_STOCK') {
            return null;
        }

        $config = $mapping->fulfillment_config ?? null;
        $config = is_string($config) ? (json_decode($config, true) ?: []) : (array) $config;
        $key = trim((string) ($config['stock_key'] ?? ''));

        return $key === '' ? null : $key;
    }

    private function digiflazzMaxPrice(int $highestEligibleCost): int
    {
        // Integer ceil(cost * 1.01): avoid floating-point rounding and overflow.
        if ($highestEligibleCost <= 0 || $highestEligibleCost > intdiv(PHP_INT_MAX - 99, 101)) {
            throw ValidationException::withMessages(['package_id' => 'Batas harga Digiflazz tidak valid.']);
        }

        return intdiv(($highestEligibleCost * 101) + 99, 100);
    }

    private function globalSellMarkupPercent(): string
    {
        return $this->configuredGlobalSellMarkupPercent() ?? '10';
    }

    /**
     * Catalog redesign: the admin store cost is the Digiflazz `max_price` cap.
     * A live seller price above that cap makes the SKU temporarily unavailable
     * until the provider price recovers. Legacy mappings are unaffected.
     */
    private function sellerPriceExceedsStoreCost(object $candidate, ?object $item): bool
    {
        return $candidate->store_cost_idr !== null
            && $item !== null
            && (int) $item->price_idr > (int) $candidate->store_cost_idr;
    }

    private function configuredGlobalSellMarkupPercent(): ?string
    {
        $stored = DB::table('system_settings')->where('key', 'pricing.sell_markup_percent')->value('value');
        $decoded = $stored !== null ? json_decode((string) $stored, true) : null;

        return is_numeric($decoded) && (float) $decoded >= 0 && (float) $decoded <= 1000
            ? (string) $decoded
            : null;
    }

    private function margin(int $cost, string $percent): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $percent, $matches)) {
            throw ValidationException::withMessages(['package_id' => 'Konfigurasi margin tidak valid.']);
        }

        $units = ((int) $matches[1] * 10000)
            + (int) str_pad($matches[2] ?? '', 4, '0');
        if ($units === 0) {
            return 0;
        }

        $denominator = 1000000;
        if ($cost > intdiv(PHP_INT_MAX - ($denominator - 1), $units)) {
            throw ValidationException::withMessages(['package_id' => 'Harga produk di luar batas aman.']);
        }

        return intdiv(($cost * $units) + ($denominator - 1), $denominator);
    }
}
