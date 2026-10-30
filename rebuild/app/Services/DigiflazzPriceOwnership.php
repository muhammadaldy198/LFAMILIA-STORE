<?php

namespace App\Services;

use App\Models\ProductPackage;
use Illuminate\Support\Facades\DB;

class DigiflazzPriceOwnership
{
    /**
     * Determine who owns a nominal's effective selling-price rule.
     * A historical Digiflazz mapping alone must not turn a local voucher
     * into a Digiflazz-priced package.
     *
     * @param  array<string, mixed>|null  $quote
     */
    public function usesDigiflazz(ProductPackage $package, ?array $quote = null): bool
    {
        // The server checkout decision is authoritative whenever the selected
        // source is available; it checks live SKU identity and voucher stock.
        if ($quote !== null && in_array($quote['provider_code'] ?? null, ['DIGIFLAZZ', 'VOUCHER_STOCK'], true)) {
            return $quote['provider_code'] === 'DIGIFLAZZ';
        }

        // For unavailable/draft nominals use the same mapping priority/cost/ID
        // ordering, without assuming any historical mapping is the active one.
        $fulfillmentMode = $package->product->fulfillment_mode;
        $active = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->where('mappings.product_package_id', $package->id)
            ->where('mappings.is_active', true)
            ->where('providers.is_active', true)
            ->where('providers.fulfillment_mode', $fulfillmentMode)
            ->whereIn('providers.code', ['DIGIFLAZZ', 'VOUCHER_STOCK'])
            ->whereRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr) > 0')
            ->orderBy('mappings.priority')
            ->orderByRaw('COALESCE(mappings.store_cost_idr, mappings.cost_idr)')
            ->orderBy('mappings.id')
            ->select('providers.code')
            ->first();

        if ($active !== null) {
            return $active->code === 'DIGIFLAZZ';
        }

        $digiflazz = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->where('mappings.product_package_id', $package->id)
            ->where('providers.code', 'DIGIFLAZZ')
            ->exists();
        $activeVoucher = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->where('mappings.product_package_id', $package->id)
            ->where('providers.code', 'VOUCHER_STOCK')
            ->where('mappings.is_active', true)
            ->exists();

        return $digiflazz && ! $activeVoucher;
    }
}
