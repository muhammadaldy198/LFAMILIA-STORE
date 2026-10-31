<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Support\GameNamePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DigiflazzAutoSources
{
    /** @var array<int, array<int, bool>> Newly imported nominal IDs, per product/batch. */
    private array $newPackagesByProduct = [];

    private ?bool $hasPackageGroupTable = null;

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

    public function importGroup(Product $product, Collection $items, ?string $template, bool $publish, string $margin, bool $automatic, Request $request, ?string $costUplift = null, ?string $targetGroup = null): ProductPackage
    {
        // Enforce one SKU per nominal for all import callers, not only UI.
        if ($items->count() !== 1) {
            throw ValidationException::withMessages([
                'item_ids' => 'Satu nominal hanya boleh memiliki satu SKU Digiflazz terpilih.',
            ]);
        }
        $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
        // Resolve the package without taking a mapping lock. Both sync and
        // catalog importer lock the PACKAGE before the MAPPING; reversing
        // that order here can deadlock a historical import during sync.
        $mappingQuery = ProviderMapping::where('provider_id', $provider->id)
            ->whereIn('external_sku', $items->pluck('buyer_sku_code'));
        $initialPackageIds = (clone $mappingQuery)->pluck('product_package_id')->unique();
        if ($initialPackageIds->count() > 1) {
            throw ValidationException::withMessages(['item_ids' => 'Sumber nominal yang sama sudah terpisah di beberapa nominal. Tidak ada sumber yang dipindahkan.']);
        }
        $package = $initialPackageIds->isNotEmpty()
            ? ProductPackage::whereKey($initialPackageIds->first())->lockForUpdate()->firstOrFail()
            : null;
        $existing = (clone $mappingQuery)->lockForUpdate()->get();
        $existingBySku = $existing->keyBy('external_sku');
        $packageIds = $existing->pluck('product_package_id')->unique();
        if ($packageIds->count() > 1
            || ($package !== null && $packageIds->isNotEmpty()
                && (int) $packageIds->first() !== (int) $package->id)) {
            throw ValidationException::withMessages(['item_ids' => 'Mapping berubah saat impor. Coba ulangi setelah sinkronisasi selesai.']);
        }
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
        $destinationGroup = $targetGroup ?? trim((string) $first->type);
        $destinationGroup = $destinationGroup === '' ? 'Umum' : $destinationGroup;
        // Connect an SKU to an existing, unmapped nominal WITHOUT changing
        // the store-owned product or nominal name. Never swap an existing SKU.
        if ($package === null && $nominal !== null) {
            $matchingPackage = $product->packages()
                ->where('nominal_value', $nominal)
                ->where(function ($query) use ($destinationGroup): void {
                    if ($destinationGroup === 'Umum') {
                        $query->whereNull('group_name')->orWhere('group_name', '')
                            ->orWhere('group_name', 'Umum');
                    } else {
                        $query->where('group_name', $destinationGroup);
                    }
                })
                ->lockForUpdate()->first();
            if ($matchingPackage !== null) {
                if ($matchingPackage->mappings()->where('provider_id', $provider->id)->exists()) {
                    throw ValidationException::withMessages([
                        'item_ids' => 'Nominal ini sudah memiliki SKU Digiflazz. Lepas SKU lama terlebih dahulu.',
                    ]);
                }
                $package = $matchingPackage;
            }
        }
        if ($package !== null && $targetGroup !== null
            && (trim((string) $package->group_name) === '' ? 'Umum' : trim((string) $package->group_name)) !== $destinationGroup) {
            throw ValidationException::withMessages([
                'group_id' => 'SKU sudah terhubung ke grup lain. Ubah grup dari editor nominal.',
            ]);
        }
        // A legacy refresh must retain the store-owned group rather than
        // recreating the supplier's original label after an admin rename.
        if ($package !== null && $targetGroup === null) {
            $destinationGroup = trim((string) $package->group_name);
            $destinationGroup = $destinationGroup === '' ? 'Umum' : $destinationGroup;
        }
        // Importing a draft Digiflazz SKU must never reprice a nominal that
        // currently sells voucher stock. Require a separate nominal (or an
        // explicit source migration) rather than changing its price fields.
        if ($package !== null && $package->mappings()
            ->where('provider_id', Provider::where('code', 'VOUCHER_STOCK')->value('id'))
            ->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'item_ids' => 'Nominal ini masih memiliki sumber voucher lokal aktif. Pisahkan nominal terlebih dahulu sebelum memasang SKU Digiflazz.',
            ]);
        }
        if ($package !== null && $package->mappings()
            ->where('provider_id', $provider->id)
            ->where('external_sku', '<>', $first->buyer_sku_code)
            ->where('is_active', true)->exists()) {
            // Historical inactive mappings must not block an idempotent
            // refresh of the selected SKU. Never reactivate the old ones.
            throw ValidationException::withMessages([
                'item_ids' => 'Nominal ini sudah memiliki SKU Digiflazz lain.',
            ]);
        }
        // New Digiflazz nominal gets its own 10% markup, not a product price.
        // Existing explicit nominal pricing must never be overwritten by import/sync.
        if (! $package) {
            $package = $product->packages()->create([
                'code' => 'DF_'.substr(hash('sha256', $first->buyer_sku_code), 0, 20),
                'name' => GameNamePrefix::strip($first->product_name, $first->brand, $product->name),
                'group_name' => $targetGroup !== null ? $destinationGroup : ($destinationGroup === 'Umum' ? null : $destinationGroup),
                // Append temporarily, then orderProduct() inserts it by nominal
                // without resetting a previously hand-customized order.
                'nominal_value' => $nominal,
                'sort_order' => ((int) $product->packages()->max('sort_order')) + 1,
                'is_active' => $publish,
                'pricing_mode' => 'PERCENT',
                'margin_percent' => 10,
                'margin_fixed_idr' => null,
                'sell_price_idr' => null,
            ]);
            $this->newPackagesByProduct[(int) $product->id][(int) $package->id] = true;
            app(AdminAuditService::class)->record($request, 'catalog.package.imported', 'product_package', $package->id, null, $package->toArray());
        } else {
            $before = $package->toArray();
            $newPricing = ! $package->mappings()->where('provider_id', $provider->id)->exists()
                && in_array($package->pricing_mode, ['PRODUCT_MARGIN', 'SELL_PRICE'], true)
                ? ['pricing_mode' => 'PERCENT', 'margin_percent' => 10,
                    'margin_fixed_idr' => null, 'sell_price_idr' => null, 'sell_markup_percent' => null]
                : [];
            $package->update([
                'nominal_value' => $package->nominal_value ?? $nominal,
                'is_active' => $publish ?: $package->is_active,
                ...$newPricing,
                // Existing nominal name and explicit pricing are store-owned.
            ]);
            app(AdminAuditService::class)->record($request, 'catalog.package.updated', 'product_package', $package->id, $before, $package->fresh()->toArray());
        }
        // Legacy import paths still register supplier groups in the product panel.
        if (($this->hasPackageGroupTable ??= Schema::hasTable('product_package_groups'))
            && ! $product->packageGroups()->where('name', $destinationGroup)->exists()) {
            $product->packageGroups()->create([
                'name' => $destinationGroup,
                'sort_order' => ((int) $product->packageGroups()->max('sort_order')) + 1,
            ]);
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
            $mapping = app(DigiflazzCatalogImport::class)->upsert($package, $item->buyer_sku_code, (int) $item->price_idr, (int) $item->price_idr, $costUplift);
            $before = $mapping->toArray();
            $config = $mapping->fulfillment_config ?? [];
            if ($automatic) {
                $config['auto_source_group'] = $this->key($item);
            }
            $mapping->update([
                // Do not silently reactivate an existing disabled SKU when
                // the historical automatic import grouping is refreshed.
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
        $newIds = $this->newPackagesByProduct[(int) $product->id] ?? [];
        DB::transaction(function () use ($product, $newIds): void {
            // Lock in stable ID order, as the sync path does.
            $packages = $product->packages()->orderBy('id')->lockForUpdate()->get();
            $ordering = app(CatalogNominalOrder::class);
            // Follow checkout's effective order when legacy positions tie:
            // sort_order, then numeric nominal, then name/ID.
            $existing = $ordering->sort($packages->reject(
                fn (ProductPackage $package): bool => isset($newIds[(int) $package->id])
            ));
            $naturalExistingIds = $ordering->sortNaturally($existing)->pluck('id')->all();
            if ($existing->pluck('id')->all() === $naturalExistingIds) {
                // Previous order is numeric: insert any new batch naturally.
                $ordered = $ordering->sortNaturally($packages);
            } else {
                // Admin had rearranged positions: preserve them and append new
                // imports instead of undoing deliberate manual adjustments.
                $added = $ordering->sortNaturally($packages->filter(
                    fn (ProductPackage $package): bool => isset($newIds[(int) $package->id])
                ));
                $ordered = $existing->concat($added);
            }
            foreach ($ordered as $order => $package) {
                $package->update(['sort_order' => $order]);
            }
        }, 3);
        unset($this->newPackagesByProduct[(int) $product->id]);
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
