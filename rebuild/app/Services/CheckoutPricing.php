<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutPricing
{
    /**
     * @return array<string, mixed>
     */
    public function forPackage(int $packageId, bool $lock = false): array
    {
        $contextQuery = DB::table('product_packages as packages')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('packages.id', $packageId)
            ->where('packages.is_active', true)
            ->where('products.is_active', true)
            ->where('categories.is_active', true)
            ->select(
                'packages.id as package_id',
                'packages.code as package_code',
                'packages.name as package_name',
                'packages.nominal_value',
                'packages.pricing_mode', 'packages.margin_percent as package_margin_percent',
                'packages.margin_fixed_idr', 'packages.sell_price_idr',
                'products.id as product_id',
                'products.name as product_name',
                'products.slug as product_slug',
                'products.category_id',
                'products.margin_percent',
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
            ->where('mappings.is_active', true)
            ->where('providers.is_active', true)
            ->where('providers.fulfillment_mode', $context->fulfillment_mode)
            ->whereNotNull('mappings.cost_idr')
            ->where('mappings.cost_idr', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('mappings.max_price_idr')
                    ->orWhereColumn('mappings.cost_idr', '<=', 'mappings.max_price_idr');
            })
            ->orderBy('mappings.priority')
            ->orderBy('mappings.cost_idr')
            ->orderBy('mappings.id')
            ->select(
                'mappings.id as mapping_id',
                'mappings.external_sku',
                'mappings.cost_idr',
                'mappings.max_price_idr',
                'mappings.fulfillment_config',
                'providers.code as provider_code'
            );

        if ($lock) {
            $mappingQuery->lockForUpdate();
        }

        $mapping = $mappingQuery->get()->first(function (object $candidate): bool {
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

            return $item === null || app(DigiflazzCatalogService::class)->available($item);
        });
        if (! $mapping) {
            throw ValidationException::withMessages([
                'package_id' => 'Nominal sedang tidak tersedia untuk checkout.',
            ]);
        }

        $cost = (int) $mapping->cost_idr;
        $percent = $context->pricing_mode === 'PERCENT' ? $context->package_margin_percent : $context->margin_percent;
        $margin = match ($context->pricing_mode) {
            'FIXED' => (int) $context->margin_fixed_idr,
            'SELL_PRICE' => (int) $context->sell_price_idr - $cost,
            default => $this->margin($cost, (string) $percent),
        };
        if ($margin < 0) {
            throw ValidationException::withMessages(['package_id' => 'Harga jual nominal berada di bawah modal.']);
        }

        return [
            'product_id' => (int) $context->product_id,
            'product_name' => $context->product_name,
            'product_slug' => $context->product_slug,
            'category_id' => (int) $context->category_id,
            'category_name' => $context->category_name,
            'fulfillment_mode' => $context->fulfillment_mode,
            'margin_percent' => (string) $context->margin_percent,
            'package_id' => (int) $context->package_id,
            'package_code' => $context->package_code,
            'package_name' => $context->package_name,
            'nominal_value' => $context->nominal_value !== null ? (int) $context->nominal_value : null,
            'provider_mapping_id' => (int) $mapping->mapping_id,
            'provider_code' => $mapping->provider_code,
            'provider_sku' => $mapping->external_sku,
            'max_price_idr' => $mapping->max_price_idr !== null ? (int) $mapping->max_price_idr : null,
            'cost_idr' => $cost,
            'margin_idr' => $margin,
            'subtotal_idr' => $cost + $margin,
        ];
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
