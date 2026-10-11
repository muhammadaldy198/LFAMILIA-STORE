<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DigiflazzFixedPricePolicy
{
    // Target minimum gross margin: (sell - cost) / sell = 8%.
    public const GROSS_MARGIN_PERCENT = 8;

    private const COST_PERCENT = 92;

    public function fixedPriceFromCost(int $cost): int
    {
        if ($cost <= 0 || $cost > intdiv(PHP_INT_MAX - 200, 100)) {
            throw new InvalidArgumentException('Modal Digiflazz tidak valid.');
        }

        $minimum = intdiv($cost * 100 + self::COST_PERCENT - 1, self::COST_PERCENT);

        return intdiv($minimum + 99, 100) * 100;
    }

    public function maximumCost(int $sellPrice): int
    {
        if ($sellPrice <= 0 || $sellPrice > intdiv(PHP_INT_MAX, self::COST_PERCENT)) {
            return 0;
        }

        return intdiv($sellPrice * self::COST_PERCENT, 100);
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
            $cost = (int) $row->cost_idr;
            if (! isset($suggestions[$id]) || $cost < $suggestions[$id]['cost_idr']) {
                $suggestions[$id] = [
                    'package_id' => $id,
                    'product_name' => (string) $row->product_name,
                    'package_name' => (string) $row->package_name,
                    'cost_idr' => $cost,
                    'sell_price_idr' => $this->fixedPriceFromCost($cost),
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
            $cap = $this->maximumCost((int) $row->sell_price_idr);
            $alerts[$id] ??= [
                'package_id' => $id,
                'product_name' => (string) $row->product_name,
                'package_name' => (string) $row->package_name,
                'sell_price_idr' => (int) $row->sell_price_idr,
                'maximum_cost_idr' => $cap,
                'lowest_cost_idr' => null,
                'has_eligible_source' => false,
            ];
            if (! $catalog->available($row)) {
                continue;
            }

            $cost = (int) ($row->is_manual_price ? $row->live_price_idr : $row->cost_idr);
            $alerts[$id]['lowest_cost_idr'] = $alerts[$id]['lowest_cost_idr'] === null
                ? $cost : min($cost, $alerts[$id]['lowest_cost_idr']);
            if ($catalog->sellable($row) && $cost <= $cap
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
                    ->orWhereColumn('mappings.cost_idr', '<=', 'mappings.max_price_idr');
            })
            ->where('packages.pricing_mode', $fixed ? '=' : '!=', 'SELL_PRICE')
            ->select(
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.sell_price_idr',
                'products.name as product_name',
                'mappings.external_sku',
                'mappings.cost_idr',
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
