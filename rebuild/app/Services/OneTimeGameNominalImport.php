<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Explicit, one-time curation of existing game product denominations.
 *
 * Called only from the one-time October 2026 migration. It does not schedule
 * imports, create games, change non-game products, or auto-enroll future SKUs.
 */
class OneTimeGameNominalImport
{
    private const GAME_BRANDS = [
        'Mobile Legends' => 'MOBILE LEGENDS',
        'Free Fire' => 'FREE FIRE',
        'PUBG Mobile' => 'PUBG MOBILE',
        'Honor of Kings' => 'Honor of Kings',
        'Genshin Impact' => 'Genshin Impact',
        'Valorant' => 'Valorant',
        'Call of Duty Mobile' => 'Call of Duty MOBILE',
        'League of Legends: Wild Rift' => 'League of Legends Wild Rift',
        'EA SPORTS FC Mobile' => 'FC Mobile',
        'Point Blank' => 'POINT BLANK',
    ];

    private const DESTINATION_TEMPLATES = [
        'Mobile Legends' => '{{destination}}{{server}}',
        'Genshin Impact' => '{{destination}}|{{server}}',
    ];

    public function run(): array
    {
        $result = ['games_activated' => 0, 'packages_created' => 0, 'mappings_created' => 0, 'groups_skipped' => 0];
        $provider = Provider::query()->where('code', 'DIGIFLAZZ')->where('is_active', true)->first();
        $category = DB::table('categories')->where('slug', 'game')->where('is_active', true)->first();
        if (! $provider || ! $category) {
            Log::warning('One-time game import skipped: game category or Digiflazz provider inactive.');

            return $result;
        }

        $sources = app(DigiflazzAutoSources::class);
        $catalogImporter = app(DigiflazzCatalogImport::class);
        $audit = app(AdminAuditService::class);
        $request = Request::create('/internal/one-time-game-nominal-import', 'POST');
        $request->headers->set('User-Agent', 'LFAMILIA one-time manual game import');

        foreach (self::GAME_BRANDS as $name => $brand) {
            $product = Product::query()
                ->where('category_id', $category->id)
                ->where('name', $name)
                ->where('fulfillment_mode', 'AUTO_PROVIDER')
                ->first();
            if (! $product) {
                continue;
            }

            $template = self::DESTINATION_TEMPLATES[$name] ?? '{{destination}}';
            preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $template, $matches);
            $fields = $product->fields()->where('is_required', true)->pluck('field_key')->all();
            if ($fields === [] || array_diff($fields, $matches[1] ?? [])
                || array_diff($matches[1] ?? [], $product->fields()->pluck('field_key')->all())) {
                Log::warning('One-time game import skipped: incompatible customer input fields.', ['product_id' => $product->id]);

                continue;
            }

            // A valid supplier SKU may be temporarily unavailable during a nightly
            // cutoff. Preserve it, while runtime pricing enforces cutoffs and stock.
            $items = DB::table('digiflazz_catalog_items')
                ->where('category', 'Games')
                ->where('type', 'Umum')
                ->where('brand', $brand)
                ->where('buyer_active', true)
                ->where('seller_active', true)
                ->where(function ($query): void {
                    $query->where('unlimited_stock', true)->orWhere('stock', '>', 0);
                })
                ->where('price_idr', '>', 0)
                ->where('synced_at', '>=', now()->subHours(24))
                ->orderBy('price_idr')->get();

            $successfulGroups = 0;
            $wasActive = (bool) $product->is_active;
            foreach ($items->groupBy(fn (object $item): string => $sources->key($item)) as $group) {
                $skus = $group->pluck('buyer_sku_code');
                $existing = ProviderMapping::query()->where('provider_id', $provider->id)
                    ->whereIn('external_sku', $skus)->get();

                // Never move a SKU that is already assigned to another product.
                $packageProductIds = DB::table('product_packages')
                    ->whereIn('id', $existing->pluck('product_package_id'))
                    ->pluck('product_id')->unique();
                if ($packageProductIds->contains(fn ($id): bool => (int) $id !== (int) $product->id)) {
                    $result['groups_skipped']++;

                    continue;
                }

                // Never re-enable a SKU intentionally disabled by the merchant.
                $disabled = $existing->filter(fn (ProviderMapping $mapping): bool => ! $mapping->is_active)
                    ->pluck('external_sku')->all();
                $safe = $group->reject(fn (object $item): bool => in_array($item->buyer_sku_code, $disabled, true))
                    ->values();
                if ($safe->isEmpty()) {
                    $result['groups_skipped']++;
                    continue;
                }

                $beforePackageCount = $product->packages()->count();
                $beforeMappingCount = ProviderMapping::query()->where('provider_id', $provider->id)
                    ->whereIn('product_package_id', $product->packages()->pluck('id'))->count();

                DB::transaction(function () use ($product, $safe, $template, $sources, $provider, $catalogImporter, $audit, $request): void {
                    // Manual mode: no auto_source_group tag; future catalogue sync
                    // will update prices, but will not import new denominations.
                    $package = $sources->importGroup(
                        $product, $safe, $template, true,
                        (string) $product->margin_percent, false, $request
                    );

                    // Fill sources that importGroup safely omits at supplier cutoff.
                    foreach ($safe as $item) {
                        if ($package->mappings()->where('provider_id', $provider->id)
                            ->where('external_sku', $item->buyer_sku_code)->exists()) {
                            continue;
                        }
                        $mapping = $catalogImporter->upsert(
                            $package, (string) $item->buyer_sku_code,
                            (int) $item->price_idr, (int) $item->price_idr
                        );
                        $mapping->update([
                            'is_active' => true,
                            'fulfillment_config' => ['customer_no_template' => $template],
                        ]);
                        $audit->record($request, 'catalog.manual_source_cutoff_attached',
                            'provider_mapping', $mapping->id, null, $mapping->fresh()->toArray());
                    }

                    // Deterministic cheapest-first fallback, not arbitrary all-zero priority.
                    $ranked = $package->mappings()->where('provider_id', $provider->id)
                        ->where('is_active', true)
                        ->orderBy('cost_idr')->orderBy('external_sku')->orderBy('id')->get();
                    foreach ($ranked as $position => $mapping) {
                        if ((int) $mapping->priority === $position) {
                            continue;
                        }
                        $before = $mapping->toArray();
                        $mapping->update(['priority' => $position]);
                        $audit->record($request, 'catalog.manual_source_ranked',
                            'provider_mapping', $mapping->id, $before, $mapping->fresh()->toArray());
                    }
                }, 2);

                $result['packages_created'] += $product->packages()->count() - $beforePackageCount;
                $afterMappingCount = ProviderMapping::query()->where('provider_id', $provider->id)
                    ->whereIn('product_package_id', $product->packages()->pluck('id'))->count();
                $result['mappings_created'] += $afterMappingCount - $beforeMappingCount;
                $successfulGroups++;
            }

            if ($successfulGroups > 0) {
                DB::transaction(function () use ($sources, $product, $audit, $request): void {
                    $sources->orderProduct($product);
                    if (! $product->is_active) {
                        $before = $product->toArray();
                        $product->update(['is_active' => true]);
                        $audit->record($request, 'catalog.product.manual_nominals_activated',
                            'product', $product->id, $before, $product->fresh()->toArray());
                    }
                });
                if (! $wasActive) {
                    $result['games_activated']++;
                }
            }
        }

        Log::info('One-time manual game catalog import completed.', $result);

        return $result;
    }
}
