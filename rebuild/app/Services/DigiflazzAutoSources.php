<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $first = $items->sortBy('price_idr')->first();
        $nominal = app(CatalogNominalOrder::class)->value($first->product_name, $first->brand);
        if (! $package) {
            $package = $product->packages()->create([
                'code' => 'DF_'.substr(hash('sha256', $first->buyer_sku_code), 0, 20),
                'name' => $first->product_name, 'group_name' => $first->type ?: null,
                'nominal_value' => $nominal, 'sort_order' => 0, 'is_active' => $publish,
                'pricing_mode' => 'PRODUCT_MARGIN', 'margin_percent' => null,
            ]);
            app(AdminAuditService::class)->record($request, 'catalog.package.imported', 'product_package', $package->id, null, $package->toArray());
        } else {
            $before = $package->toArray();
            $package->update(['nominal_value' => $package->nominal_value ?? $nominal, 'is_active' => $publish ?: $package->is_active]);
            app(AdminAuditService::class)->record($request, 'catalog.package.updated', 'product_package', $package->id, $before, $package->fresh()->toArray());
        }
        foreach ($items as $item) {
            if (! app(DigiflazzCatalogService::class)->available($item) || now()->diffInHours($item->synced_at, true) > 24) {
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

    public function sync(): int
    {
        $provider = Provider::where('code', 'DIGIFLAZZ')->first();
        if (! $provider) {
            return 0;
        }
        $catalog = $this->catalog();
        $packages = ProductPackage::whereHas('mappings', fn ($query) => $query->where('provider_id', $provider->id)->whereNotNull('fulfillment_config->auto_source_group'))->with(['mappings', 'product'])->get();
        $request = Request::create('/internal/digiflazz-auto-sources', 'POST');
        $request->setUserResolver(fn ($guard = null) => null);
        $bySku = $catalog->flatten(1)->keyBy('buyer_sku_code');
        $added = 0;
        foreach ($packages as $package) {
            $seed = $package->mappings->first(fn (ProviderMapping $mapping): bool => $mapping->provider_id === $provider->id && data_get($mapping->fulfillment_config, 'auto_source_group') !== null);
            $key = data_get($seed->fulfillment_config, 'auto_source_group');
            $items = $catalog->get($key, collect());
            foreach ($package->mappings as $existing) {
                $current = $bySku->get($existing->external_sku);
                $expected = data_get($existing->fulfillment_config, 'auto_source_group');
                if ($expected !== null && $current && $this->key($current) !== $expected) {
                    $before = $existing->toArray();
                    $existing->update(['is_active' => false]);
                    app(AdminAuditService::class)->record($request, 'catalog.fulfillment_source.identity_changed', 'provider_mapping', $existing->id, $before, $existing->fresh()->toArray());
                }
            }
            foreach ($items as $item) {
                if (! app(DigiflazzCatalogService::class)->available($item) || now()->diffInHours($item->synced_at, true) > 24
                    || ProviderMapping::where('provider_id', $provider->id)->where('external_sku', $item->buyer_sku_code)->exists()) {
                    continue;
                }
                $mapping = app(DigiflazzCatalogImport::class)->upsert($package, $item->buyer_sku_code, (int) $item->price_idr, (int) $item->price_idr);
                $before = $mapping->toArray();
                $mapping->update([
                    'is_active' => $provider->is_active && $package->is_active && $package->product->is_active,
                    'priority' => 0,
                    'fulfillment_config' => $seed->fulfillment_config,
                ]);
                app(AdminAuditService::class)->record($request, 'catalog.fulfillment_source.auto_attached', 'provider_mapping', $mapping->id, $before, $mapping->fresh()->toArray());
                $added++;
            }
            if ($key) {
                $this->rankAutomaticSources($package, (int) $provider->id, (string) $key, $request);
            }
        }

        return $added;
    }
}
