<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DigiflazzCatalogImport
{
    /**
     * One-time store cost: seller reference plus the effective cost uplift
     * (nominal → game → global). Applied only when a mapping has no store
     * cost yet, so repeated sync/import never compounds the uplift.
     */
    public function initialStoreCost(?ProductPackage $package, Product $product, int $sellerPrice, ?string $override = null): int
    {
        // Override = kolom "Harga modal (%)" di pop-up impor, berlaku untuk
        // semua SKU dalam satu impor.
        $markup = $override
            ?? $package?->cost_uplift_percent
            ?? $product->cost_uplift_percent
            ?? $this->globalCostUpliftPercent();
        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', (string) $markup, $matches)) {
            throw new InvalidArgumentException('Persentase tambahan modal tidak valid.');
        }

        $units = ((int) $matches[1] * 10000) + (int) str_pad($matches[2] ?? '', 4, '0');
        if ($units > 100000000 || $sellerPrice > intdiv(PHP_INT_MAX - 1000000, 1000000 + $units)) {
            throw new InvalidArgumentException('Modal awal berada di luar batas aman.');
        }

        return intdiv(($sellerPrice * (1000000 + $units)) + 999999, 1000000);
    }

    private function globalCostUpliftPercent(): string
    {
        $stored = DB::table('system_settings')->where('key', 'pricing.cost_uplift_percent')->value('value');
        $decoded = $stored !== null ? json_decode((string) $stored, true) : null;

        return is_numeric($decoded) && (float) $decoded >= 0 && (float) $decoded <= 1000
            ? (string) $decoded
            : '2';
    }

    /**
     * Called only by a trusted provider sync in M8/M9; no customer or admin text field supplies the SKU.
     */
    public function upsert(ProductPackage $package, string $buyerSkuCode, int $costIdr, ?int $maxPriceIdr = null, ?string $costUplift = null): ProviderMapping
    {
        if ($package->product->fulfillment_mode !== 'AUTO_PROVIDER'
            || ! preg_match('/^[A-Za-z0-9._-]{1,120}$/', $buyerSkuCode)
            || $costIdr <= 0
            || ($maxPriceIdr !== null && $maxPriceIdr < $costIdr)) {
            throw new InvalidArgumentException('Mapping Digiflazz tidak valid.');
        }

        return DB::transaction(function () use ($package, $buyerSkuCode, $costIdr, $costUplift): ProviderMapping {
            // Serialize importer/attach operations on this nominal. A second
            // Digiflazz mapping must never be created by an alternate caller.
            ProductPackage::whereKey($package->id)->lockForUpdate()->firstOrFail();
            $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
            $mapping = ProviderMapping::where('provider_id', $provider->id)
                ->where('external_sku', $buyerSkuCode)->lockForUpdate()->first();

            if ($mapping && $mapping->product_package_id !== $package->id) {
                throw new InvalidArgumentException('SKU sudah digunakan oleh nominal lain.');
            }
            if (! $mapping && ProviderMapping::where('product_package_id', $package->id)
                ->where('provider_id', $provider->id)->exists()) {
                throw new InvalidArgumentException('Nominal Digiflazz hanya boleh memiliki satu SKU. Lepas mapping lama terlebih dahulu.');
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
            // Digiflazz supplies the only seller price. Historical accounting
            // columns mirror that value and never apply a second cost uplift.
            $mapping->cost_idr = $costIdr;
            $mapping->seller_reference_price_idr = $costIdr;
            $mapping->store_cost_idr = $costIdr;
            $mapping->max_price_idr = $costIdr;
            $mapping->is_manual_price = false;
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
