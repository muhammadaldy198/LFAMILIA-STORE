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
        // Importing another nominal must not change the product-level markup.
        // New products still initialize their fixed selling price from the
        // reviewed import markup and the one-time-uplifted store cost.
        if (! $package) {
            if ($product->sell_markup_percent === null) {
                $product->update(['sell_markup_percent' => $margin]);
            }
            $storeCost = app(DigiflazzCatalogImport::class)->initialStoreCost(null, $product, (int) $first->price_idr);
            // The initial selling price uses the effective markup on the store
            // cost. Supplier sync must not rewrite this selling price.
            $effectiveMarkup = $product->sell_markup_percent ?? $margin;
            $fixedPrice = app(DigiflazzFixedPricePolicy::class)->fixedPriceFromCost(
                $storeCost, $effectiveMarkup
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
            if ($automatic && $existingBySku->has($item->buyer_sku_code) === false
                && $package->mappings()->where('provider_id', $provider->id)->exists()) {
                continue;
            }
            if (! app(DigiflazzCatalogService::class)->sellable($item)) {
                continue;
            }
            $mapping = app(DigiflazzCatalogImport::class)->upsert($package, $item->buyer_sku_code, (int) $item->price_idr, (int) $item->price_idr);
            $before = $mapping->toArray();
            $config = $mapping->fulfillment_config ?? [];
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
        // The admin action uses the same safe one-source bootstrap as full sync.
        // It may add an initial mapping, but never auto-add a second mapping.
        // Supplier sync already owns this lock while calling sync().
        // The standalone admin action must share that exclusion to prevent
        // new/retagged catalog identities appearing during candidate selection.
        $lock = Cache::lock('digiflazz.catalog.sync', 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'auto_sources' => 'Sinkronisasi Digiflazz sedang berlangsung. Coba lagi setelah selesai.',
            ]);
        }

        try {
            return $this->sync((int) $product->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Observe existing mappings without changing their state. Bootstrap exactly one
     * safe, uniquely identified Digiflazz mapping for an active unmapped package.
     */
    public function sync(?int $productId = null): int
    {
        $provider = Provider::where('code', 'DIGIFLAZZ')->first();
        if ($provider === null || $provider->is_active === false) {
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
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->get();
        $added = 0;

        foreach ($packages as $package) {
            $this->warnDuplicateMappings($package, (int) $provider->id);
            // Bootstrap ONLY when there are no Digiflazz mappings of any state.
            if ($package->mappings->contains(fn (ProviderMapping $mapping): bool => (int) $mapping->provider_id === (int) $provider->id)
                || $package->nominal_value === null || (int) $package->nominal_value <= 0) {
                continue;
            }

            // Without a verified provider template the fulfillment target builder
            // requires exactly one nonempty destination. An optional, absent, or
            // multi-field destination must not become purchasable automatically.
            $fields = $package->product->fields()->get(['field_key', 'is_required']);
            if ($fields->count() !== 1 || ! (bool) $fields->first()->is_required) {
                Log::warning('Digiflazz auto-source skipped: destination requires one verified field.', [
                    'product_id' => $package->product_id,
                    'package_id' => $package->id,
                ]);

                continue;
            }

            $productName = $this->normalizedIdentity((string) $package->product->name);
            if ($productName === '') {
                continue;
            }
            // Identity ambiguity is evaluated across ALL matching supplier SKUs,
            // including those already mapped to another package. Ownership does
            // not prove which region/service the unmapped package intends.
            $matching = $catalog->filter(
                fn (object $item): bool => $this->matchesPackage($package, $package->product, $item)
            );
            $candidates = $matching->filter(
                fn (object $item): bool => ProviderMapping::where('provider_id', $provider->id)
                    ->where('external_sku', $item->buyer_sku_code)->doesntExist()
            )->sort(fn (object $a, object $b): int => (int) $a->price_idr <=> (int) $b->price_idr
                ?: strcmp((string) $a->buyer_sku_code, (string) $b->buyer_sku_code));
            // Multiple distinct catalog identities (including region or type)
            // are ambiguous. Never guess which service to fulfill by lowest cost.
            if ($matching->groupBy(fn (object $item): string => $this->key($item))->count() > 1) {
                Log::warning('Digiflazz auto-source skipped: ambiguous supplier identity.', [
                    'package_id' => $package->id,
                ]);

                continue;
            }
            $item = $candidates->first();
            if ($item === null) {
                continue;
            }

            DB::transaction(function () use ($package, $provider, $request, &$added): void {
                // Lock and recheck the actual inputs, not the optimistic snapshot
                // selected before this transaction. Match global pricing lock order.
                $product = Product::whereKey($package->product_id)->lockForUpdate()->first();
                $locked = ProductPackage::whereKey($package->id)->lockForUpdate()->first();
                if ($product === null || ! $product->is_active || $product->fulfillment_mode !== 'AUTO_PROVIDER'
                    || $locked === null || ! $locked->is_active
                    || (int) $locked->product_id !== (int) $product->id
                    || $locked->nominal_value === null || (int) $locked->nominal_value <= 0) {
                    return;
                }

                $freshFields = $product->fields()->orderBy('id')->lockForUpdate()
                    ->get(['id', 'field_key', 'is_required']);
                if ($freshFields->count() !== 1 || ! (bool) $freshFields->first()->is_required) {
                    return;
                }

                // Re-evaluate the complete catalog identity under current DB
                // locking reads, not only the original chosen row. New variants,
                // seller ownership and retagged regions can change the choice.
                $freshMatches = DB::table('digiflazz_catalog_items')
                    ->where('is_present', true)->orderBy('buyer_sku_code')
                    ->lockForUpdate()->get()
                    ->filter(fn (object $candidate): bool => app(DigiflazzCatalogService::class)->sellable($candidate)
                        && $this->matchesPackage($locked, $product, $candidate));
                if ($freshMatches->groupBy(fn (object $candidate): string => $this->key($candidate))->count() !== 1
                    || ProviderMapping::where('product_package_id', $locked->id)
                        ->where('provider_id', $provider->id)->exists()) {
                    return;
                }

                $freshItem = $freshMatches->filter(
                    fn (object $candidate): bool => ProviderMapping::where('provider_id', $provider->id)
                        ->where('external_sku', $candidate->buyer_sku_code)->doesntExist()
                )->sort(fn (object $a, object $b): int => (int) $a->price_idr <=> (int) $b->price_idr
                    ?: strcmp((string) $a->buyer_sku_code, (string) $b->buyer_sku_code))->first();
                if ($freshItem === null) {
                    return;
                }
                $mapping = app(DigiflazzCatalogImport::class)->upsert(
                    $locked, (string) $freshItem->buyer_sku_code,
                    (int) $freshItem->price_idr, (int) $freshItem->price_idr
                );
                $before = $mapping->toArray();
                $config = $mapping->fulfillment_config ?? [];
                // Pin the verified supplier identity. A reused SKU code must
                // never silently sell/deliver a different brand or service.
                $config['auto_source_group'] = $this->key($freshItem);
                $mapping->update([
                    'is_active' => true, 'priority' => 0, 'is_manual_price' => false,
                    'fulfillment_config' => $config,
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

    private function matchesPackage(ProductPackage $package, Product $product, object $item): bool
    {
        $productName = $this->normalizedIdentity((string) $product->name);
        $brand = $this->normalizedIdentity((string) $item->brand);
        $catalogName = $this->normalizedIdentity((string) $item->product_name);
        if ($productName === '' || ($brand !== $productName && ! str_starts_with($catalogName, $productName))) {
            return false;
        }
        if ($this->skuNominal((string) $item->buyer_sku_code) !== (int) $package->nominal_value) {
            return false;
        }

        $group = $this->normalizedIdentity((string) ($package->group_name ?? ''));

        return $group === '' || $group === $this->normalizedIdentity((string) $item->type)
            || str_contains($catalogName, $group);
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
