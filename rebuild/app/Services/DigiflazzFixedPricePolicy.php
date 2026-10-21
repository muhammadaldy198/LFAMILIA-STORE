<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DigiflazzFixedPricePolicy
{
    // A price proposal follows the administrator's configured margin.
    // There is no minimum margin percentage imposed by the supplier policy.
    public function fixedPriceFromCost(int $cost, string $percent): int
    {
        if ($cost <= 0 || ! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $percent, $matches)) {
            throw new InvalidArgumentException('Modal atau margin Digiflazz tidak valid.');
        }

        $units = ((int) $matches[1] * 10000)
            + (int) str_pad($matches[2] ?? '', 4, '0');
        $denominator = 1000000;
        if ($units > 0 && $cost > intdiv(PHP_INT_MAX - ($denominator - 1), $units)) {
            throw new InvalidArgumentException('Harga Digiflazz di luar batas aman.');
        }

        $margin = $units === 0 ? 0 : intdiv(($cost * $units) + ($denominator - 1), $denominator);
        if ($margin > PHP_INT_MAX - $cost) {
            throw new InvalidArgumentException('Harga Digiflazz di luar batas aman.');
        }

        return $cost + $margin;
    }

    /** Suggested fixed prices for legacy imported packages; no database writes. */
    public function proposals(): array
    {
        $suggestions = [];
        $catalog = app(DigiflazzCatalogService::class);
        foreach ($this->mappingRows(false) as $row) {
            if (! $catalog->sellable($row)) {
                continue;
            }

            $id = (int) $row->package_id;
            $cost = (int) ($row->store_cost_idr ?? $row->cost_idr);
            if (! isset($suggestions[$id]) || $cost < $suggestions[$id]['cost_idr']) {
                $legacyPricing = $row->store_cost_idr === null
                    && $row->package_sell_markup_percent === null
                    && $row->product_sell_markup_percent === null;
                $markup = $legacyPricing ? null : app(CatalogPricingSettings::class)->sellMarkupFor(
                    $row->package_sell_markup_percent,
                    $row->product_sell_markup_percent
                );
                $suggestions[$id] = [
                    'package_id' => $id,
                    'product_name' => (string) $row->product_name,
                    'package_name' => (string) $row->package_name,
                    'cost_idr' => $cost,
                    'sell_price_idr' => $markup !== null
                        ? $this->fixedPriceFromCost($cost, $markup)
                        : ($row->pricing_mode === 'FIXED'
                            ? $cost + (int) $row->margin_fixed_idr
                            : $this->fixedPriceFromCost(
                                $cost,
                                (string) ($row->pricing_mode === 'PERCENT' ? $row->package_margin_percent : $row->product_margin_percent)
                            )),
                    'seller_sku' => (string) $row->external_sku,
                ];
            }
        }

        return array_values($suggestions);
    }

    /** All sources too expensive, stale, on cutoff, or unavailable. */
    public function alerts(): array
    {
        $alerts = [];
        $catalog = app(DigiflazzCatalogService::class);
        foreach ($this->mappingRows(true) as $row) {
            $id = (int) $row->package_id;
            $alerts[$id] ??= [
                'package_id' => $id,
                'product_name' => (string) $row->product_name,
                'package_name' => (string) $row->package_name,
                'sell_price_idr' => (int) $row->sell_price_idr,
                'maximum_cost_idr' => null,
                'lowest_cost_idr' => null,
                'has_eligible_source' => false,
            ];
            if ($row->is_manual_price) {
                $alerts[$id]['maximum_cost_idr'] = max(
                    (int) ($alerts[$id]['maximum_cost_idr'] ?? 0),
                    (int) $row->max_price_idr
                );
            }
            if (! $catalog->available($row)) {
                continue;
            }

            $cost = (int) ($row->is_manual_price ? $row->live_price_idr : $row->cost_idr);
            $alerts[$id]['lowest_cost_idr'] = $alerts[$id]['lowest_cost_idr'] === null
                ? $cost : min($cost, $alerts[$id]['lowest_cost_idr']);
            // Catalog redesign: the admin store cost is the affordability cap.
            $ceiling = $row->store_cost_idr !== null
                ? (int) $row->store_cost_idr
                : (int) $row->sell_price_idr;
            if ($catalog->sellable($row)
                && $cost > 0
                && $cost <= $ceiling
                && (! $row->is_manual_price || $cost <= (int) $row->max_price_idr)) {
                $alerts[$id]['has_eligible_source'] = true;
            }
        }

        return array_values(array_filter($alerts, fn (array $row): bool => ! $row['has_eligible_source']));
    }

    private function mappingRows(bool $fixed): Collection
    {
        return DB::table('product_packages as packages')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->join('provider_mappings as mappings', 'mappings.product_package_id', '=', 'packages.id')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('digiflazz_catalog_items as items', 'items.buyer_sku_code', '=', 'mappings.external_sku')
            ->where('providers.code', 'DIGIFLAZZ')
            ->where('providers.is_active', true)
            ->where('products.fulfillment_mode', 'AUTO_PROVIDER')
            ->where('mappings.is_active', true)
            ->where('mappings.cost_idr', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('mappings.max_price_idr')
                    ->orWhereColumn('mappings.cost_idr', '<=', 'mappings.max_price_idr')
                    // Catalog redesign: a mapping with an admin store cost is
                    // evaluated against that cap by the alert logic below.
                    ->orWhereNotNull('mappings.store_cost_idr');
            })
            ->where('packages.pricing_mode', $fixed ? '=' : '!=', 'SELL_PRICE')
            ->select(
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.sell_price_idr',
                'packages.pricing_mode',
                'packages.margin_percent as package_margin_percent',
                'packages.sell_markup_percent as package_sell_markup_percent',
                'packages.margin_fixed_idr',
                'products.margin_percent as product_margin_percent',
                'products.sell_markup_percent as product_sell_markup_percent',
                'products.name as product_name',
                'mappings.external_sku',
                'mappings.cost_idr',
                'mappings.store_cost_idr',
                'mappings.max_price_idr',
                'mappings.is_manual_price',
                'items.price_idr as live_price_idr',
                'items.is_present',
                'items.buyer_active',
                'items.seller_active',
                'items.stock',
                'items.unlimited_stock',
                'items.start_cut_off',
                'items.end_cut_off',
                'items.synced_at'
            )
            ->orderBy('packages.id')->orderBy('mappings.cost_idr')->get();
    }
}
