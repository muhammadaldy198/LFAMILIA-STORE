<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DigiflazzAutoSources
{
    public function key(object $item): string
    {
        $identity = [];
        foreach (['category', 'brand', 'type', 'product_name'] as $field) {
            $identity[] = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($item->{$field} ?? ''))));
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public function catalog(): Collection
    {
        return DB::table('digiflazz_catalog_items')->where('is_present', true)->get()->groupBy(fn (object $item): string => $this->key($item));
    }

    public function importGroup(Product $product, Collection $items, ?string $template, bool $publish, string $margin, bool $automatic, Request $request): ProductPackage
    {
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        $existing = ProviderMapping::where('provider_id', $provider->id)
            ->whereIn('external_sku', $items->pluck('buyer_sku_code'))->lockForUpdate()->get();
        $existingBySku = $existing->keyBy('external_sku');
        $packageIds = $existing->pluck('product_package_id')->unique();
        if ($packageIds->count() > 1) {
            throw ValidationException::withMessages(['item_ids' => 'Sumber nominal yang sama sudah terpisah di beberapa nominal. Tidak ada sumber yang dipindahkan.']);
        }
        $package = $packageIds->isNotEmpty() ? ProductPackage::whereKey($packageIds->first())->lockForUpdate()->firstOrFail() : null;
        if ($package && (int) $package->product_id !== (int) $product->id) {
            throw ValidationException::withMessages(['item_ids' => 'SKU sudah dipakai produk lain. Tidak ada sumber yang dipindahkan.']);
        }
        $sellable = $items->filter(fn (object $item): bool => app(DigiflazzCatalogService::class)->sellable($item));
        // Historical one-time imports may carry a temporarily cut-off/stale source.
        // Preserve its identity and price; runtime checkout still checks availability.
        $first = $sellable->sortBy('price_idr')->first() ?? $items->sortBy('price_idr')->first();
        if (! $first) {
            throw ValidationException::withMessages(['item_ids' => 'Tidak ada SKU Digiflazz yang aktif dan tersedia.']);
        }
        $nominal = app(CatalogNominalOrder::class)->value($first->product_name, $first->brand);
        if (! $package) {
            // The initial fixed selling price uses the margin configured by
            // the admin. Supplier sync must not rewrite this selling price.
            $fixedPrice = app(DigiflazzFixedPricePolicy::class)->fixedPriceFromCost(
                (int) $first->price_idr, $margin
            );
            $package = $product->packages()->create([
                'code' => 'DF_'.substr(hash('sha256', $first->buyer_sku_code), 0, 20),
                'name' => $first->product_name, 'group_name' => $first->type ?: null,
                'nominal_value' => $nominal, 'sort_order' => 0, 'is_active' => $publish,
                'pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => $fixedPrice,
                'margin_percent' => null,
            ]);
            app(AdminAuditService::class)->record($request, 'catalog.package.imported', 'product_package', $package->id, null, $package->toArray());
        } else {
            $before = $package->toArray();
            $package->update(['nominal_value' => $package->nominal_value ?? $nominal, 'is_active' => $publish ?: $package->is_active]);
            app(AdminAuditService::class)->record($request, 'catalog.package.updated', 'product_package', $package->id, $before, $package->fresh()->toArray());
        }
        foreach ($items as $item) {
            // Automatic grouping may never attach a second active source.
            if ($automatic && ! $existingBySku->has($item->buyer_sku_code)
                && $package->mappings()->where('provider_id', $provider->id)->where('is_active', true)->exists()) {
                continue;
            }
            if (! app(DigiflazzCatalogService::class)->sellable($item)) {
                continue;
            }
            $mapping = app(DigiflazzCatalogImport::class)->upsert($package, $item->buyer_sku_code, (int) $item->price_idr, (int) $item->price_idr);
            $before = $mapping->toArray();
            $config = $mapping->fulfillment_config ?? [];
            if ($template !== null) {
                $config['customer_no_template'] = $template;
            }
            if ($automatic) {
                $config['auto_source_group'] = $this->key($item);
            }
            $mapping->update([
                // Do not silently reactivate an existing disabled SKU when
                // the owner enables or refreshes auto-managed backups.
                'is_active' => $automatic && $existingBySku->has($item->buyer_sku_code)
                    ? (bool) $existingBySku->get($item->buyer_sku_code)->is_active
                    : ($publish ?: $mapping->is_active),
                'priority' => $automatic ? 0 : $mapping->priority,
                'fulfillment_config' => $config ?: null,
            ]);
            app(AdminAuditService::class)->record($request, 'catalog.mapping.updated', 'provider_mapping', $mapping->id, $before, $mapping->fresh()->toArray());
        }

        if ($automatic) {
            $this->rankAutomaticSources($package, (int) $provider->id, $this->key($first), $request);
        }

        return $package;
    }

    /**
     * Only sources explicitly managed by automatic grouping are reprioritized.
     * Manual SKU mappings keep their existing priority and enabled state.
     */
    private function rankAutomaticSources(ProductPackage $package, int $providerId, string $groupKey, Request $request): void
    {
        $mappings = $package->mappings()->where('provider_id', $providerId)
            ->get()
            ->filter(fn (ProviderMapping $mapping): bool => data_get($mapping->fulfillment_config, 'auto_source_group') === $groupKey)
            ->sort(fn (ProviderMapping $a, ProviderMapping $b): int => (int) (! $a->is_active) <=> (int) (! $b->is_active)
                ?: (int) $a->cost_idr <=> (int) $b->cost_idr
                ?: strcmp((string) $a->external_sku, (string) $b->external_sku)
                ?: $a->id <=> $b->id
            )->values();

        foreach ($mappings as $position => $mapping) {
            if ((int) $mapping->priority === $position) {
                continue;
            }

            $before = $mapping->toArray();
            $mapping->update(['priority' => $position]);
            app(AdminAuditService::class)->record(
                $request, 'catalog.fulfillment_source.priority_auto_ranked',
                'provider_mapping', $mapping->id, $before, $mapping->fresh()->toArray()
            );
        }
    }

    public function orderProduct(Product $product): void
    {
        foreach (app(CatalogNominalOrder::class)->sort($product->packages()->get()) as $order => $package) {
            $package->update(['sort_order' => $order]);
        }
    }

    public function enableProduct(Product $product, Request $request): int
    {
        return DB::transaction(function () use ($product, $request): int {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $catalog = $this->catalog();
            $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
            $added = 0;
            foreach ($product->packages()->with('mappings')->get() as $package) {
                $mappings = $package->mappings->where('provider_id', $provider->id);
                // Only the admin can intentionally add or change additional sources.
                if ($mappings->isNotEmpty()) {
                    $this->warnDuplicateMappings($package, $provider->id);
                    continue;
                }
                $rows = DB::table('digiflazz_catalog_items')->whereIn('buyer_sku_code', $mappings->pluck('external_sku'))->get();
                $keys = $rows->map(fn (object $item): string => $this->key($item))->unique();
                if ($keys->count() > 1) {
                    throw ValidationException::withMessages(['auto_sources' => 'Nominal '.$package->name.' memiliki sumber dari varian berbeda. Tidak ada sumber yang dipindahkan.']);
                }
                if ($keys->isEmpty()) {
                    continue;
                }
                $peers = $catalog->get($keys->first(), collect());
                $template = $mappings->map(fn (ProviderMapping $mapping) => data_get($mapping->fulfillment_config, 'customer_no_template'))
                    ->filter()->unique();
                if ($template->count() > 1 || ($product->fields()->count() > 1 && $template->isEmpty())) {
                    throw ValidationException::withMessages(['auto_sources' => 'Format ID tujuan nominal '.$package->name.' harus seragam sebelum cadangan otomatis diaktifkan.']);
                }
                $before = $mappings->count();
                $this->importGroup($product, $peers, $template->first(), $package->is_active && $mappings->contains('is_active', true), (string) $product->margin_percent, true, $request);
                $added += $package->mappings()->where('provider_id', $provider->id)->count() - $before;
            }
            $this->orderProduct($product);

            return $added;
        });
    }

    /**
     * Observe existing mappings without changing their state. Bootstrap exactly one
     * safe, uniquely identified Digiflazz mapping for an active unmapped package.
     */
    public function sync(): int
    {
        $provider = Provider::where('code', 'DIGIFLAZZ')->first();
        if (! $provider || ! $provider->is_active) {
            return 0;
        }

        $request = Request::create('/internal/digiflazz-auto-sources', 'POST');
        $request->setUserResolver(fn ($guard = null) => null);
        $catalog = $this->catalog()->flatten(1)->filter(
            fn (object $item): bool => app(DigiflazzCatalogService::class)->sellable($item)
        );
        $packages = ProductPackage::with(['mappings', 'product'])
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true)
                ->where('fulfillment_mode', 'AUTO_PROVIDER'))
            ->get();
        $added = 0;

        foreach ($packages as $package) {
            $this->warnDuplicateMappings($package, (int) $provider->id);
            // Bootstrap ONLY when there are no Digiflazz mappings of any state.
            if ($package->mappings->contains(fn (ProviderMapping $mapping): bool => (int) $mapping->provider_id === (int) $provider->id)
                || $package->nominal_value === null || (int) $package->nominal_value <= 0) {
                continue;
            }

            $productName = $this->normalizedIdentity((string) $package->product->name);
            if ($productName === '') {
                continue;
            }
            $candidates = $catalog->filter(function (object $item) use ($package, $productName, $provider): bool {
                $brand = $this->normalizedIdentity((string) $item->brand);
                $catalogName = $this->normalizedIdentity((string) $item->product_name);
                // Never attach a same-nominal SKU belonging to a different game/service.
                if ($brand !== $productName && ! str_starts_with($catalogName, $productName)) {
                    return false;
                }

                return $this->skuNominal((string) $item->buyer_sku_code) === (int) $package->nominal_value
                    && ! ProviderMapping::where('provider_id', $provider->id)
                        ->where('external_sku', $item->buyer_sku_code)->exists();
            })->sort(fn (object $a, object $b): int => (int) $a->price_idr <=> (int) $b->price_idr
                ?: strcmp((string) $a->buyer_sku_code, (string) $b->buyer_sku_code));
            $item = $candidates->first();
            if (! $item) {
                continue;
            }

            DB::transaction(function () use ($package, $provider, $item, $request, &$added): void {
                $locked = ProductPackage::whereKey($package->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->is_active || ! $locked->product()->where('is_active', true)->exists()
                    || ProviderMapping::where('product_package_id', $package->id)
                        ->where('provider_id', $provider->id)->exists()
                    || ProviderMapping::where('provider_id', $provider->id)
                        ->where('external_sku', $item->buyer_sku_code)->exists()) {
                    return;
                }
                $mapping = app(DigiflazzCatalogImport::class)->upsert(
                    $locked, (string) $item->buyer_sku_code, (int) $item->price_idr, (int) $item->price_idr
                );
                $before = $mapping->toArray();
                $mapping->update([
                    'is_active' => true, 'priority' => 0, 'is_manual_price' => false,
                    'cost_idr' => (int) $item->price_idr, 'max_price_idr' => (int) $item->price_idr,
                ]);
                app(AdminAuditService::class)->record(
                    $request, 'catalog.fulfillment_source.auto_bootstrapped',
                    'provider_mapping', $mapping->id, $before, $mapping->fresh()->toArray()
                );
                $added++;
            }, 3);
        }

        return $added;
    }

    private function normalizedIdentity(string $name): string
    {
        return mb_strtolower(preg_replace('/[^\\pL\\pN]+/u', '', $name) ?: '');
    }

    private function skuNominal(string $sku): ?int
    {
        // The SKU is supplier-controlled; ambiguous multi-number codes are skipped.
        preg_match_all('/(?<!\\d)(\\d{1,3}(?:[.,]\\d{3})+|\\d+)(?!\\d)/', $sku, $matches);
        if (count($matches[1]) !== 1) {
            return null;
        }
        $digits = str_replace(['.', ','], '', $matches[1][0]);

        return strlen($digits) < 16 ? (int) $digits : null;
    }

    private function warnDuplicateMappings(ProductPackage $package, int $providerId): void
    {
        $active = $package->mappings->filter(
            fn (ProviderMapping $mapping): bool => (int) $mapping->provider_id === $providerId && $mapping->is_active
        );
        if ($active->count() < 2) {
            return;
        }
        Log::warning('Digiflazz package has duplicate active mappings; admin action required.', [
            'package_id' => $package->id, 'active_count' => $active->count(),
        ]);
        $key = 'digiflazz.mapping.duplicates.'.$package->id;
        if (Cache::add($key, true, now()->addHour())) {
            app(AdminNotificationService::class)->record(
                'digiflazz.mapping.duplicate_active', 'Mapping Digiflazz aktif ganda',
                'Paket #'.$package->id.' memiliki '.$active->count().' mapping aktif. Periksa di panel admin.',
                'WARNING', 'product_package', $package->id
            );
        }
    }
}
