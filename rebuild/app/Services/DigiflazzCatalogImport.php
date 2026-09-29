<?php

namespace App\Services;

use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DigiflazzCatalogImport
{
    /**
     * Called only by a trusted provider sync in M8/M9; no customer or admin text field supplies the SKU.
     */
    public function upsert(ProductPackage $package, string $buyerSkuCode, int $costIdr, ?int $maxPriceIdr = null): ProviderMapping
    {
        if ($package->product->fulfillment_mode !== 'AUTO_PROVIDER'
            || ! preg_match('/^[A-Za-z0-9._-]{1,120}$/', $buyerSkuCode)
            || $costIdr <= 0
            || ($maxPriceIdr !== null && $maxPriceIdr < $costIdr)) {
            throw new InvalidArgumentException('Mapping Digiflazz tidak valid.');
        }

        return DB::transaction(function () use ($package, $buyerSkuCode, $costIdr, $maxPriceIdr): ProviderMapping {
            $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
            $mapping = ProviderMapping::where('provider_id', $provider->id)
                ->where('external_sku', $buyerSkuCode)->lockForUpdate()->first();

            if ($mapping && $mapping->product_package_id !== $package->id) {
                throw new InvalidArgumentException('SKU sudah digunakan oleh nominal lain.');
            }

            if (! $mapping) {
                $mapping = new ProviderMapping([
                    'product_package_id' => $package->id,
                    'provider_id' => $provider->id,
                    'external_sku' => $buyerSkuCode,
                    'priority' => 0,
                    'is_active' => false,
                ]);
            }

            $before = $mapping->exists ? $mapping->toArray() : null;
            $mapping->cost_idr = $costIdr;
            $mapping->max_price_idr = $maxPriceIdr;
            $mapping->save();
            DB::table('audit_logs')->insert([
                'actor_type' => 'system',
                'actor_role' => 'PROVIDER_SYNC',
                'action' => 'catalog.mapping.synced',
                'target_type' => 'provider_mapping',
                'target_id' => (string) $mapping->id,
                'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after' => json_encode($mapping->toArray(), JSON_THROW_ON_ERROR),
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $mapping;
        });
    }
}
