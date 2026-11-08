<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\CatalogAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mengubah mapping provider sebuah paket dari panel admin: menukar urutan
 * prioritas antar sumber, atau menyimpan provider, SKU, dan harga modalnya.
 */
class AdminProviderMappingController
{
    public function mapping(Request $request, ProviderMapping $mapping, CatalogAudit $audit): RedirectResponse
    {
        // Naik/turun menukar prioritas dengan sumber tetangga, bukan menambah
        // angka: dua sumber berprioritas sama tidak akan pernah bergeser.
        if ($request->filled('direction')) {
            $direction = (int) $request->validate(['direction' => ['required', Rule::in(['-1', '1'])]])['direction'];
            DB::transaction(function () use ($request, $mapping, $audit, $direction): void {
                $siblings = ProviderMapping::where('product_package_id', $mapping->product_package_id)
                    ->orderBy('priority')->orderBy('id')->lockForUpdate()->get()->values();
                $index = $siblings->search(fn (ProviderMapping $row): bool => $row->id === $mapping->id);
                $neighbor = $siblings->get($index + $direction);
                if ($neighbor === null) {
                    return;
                }
                $before = ['a' => $mapping->toArray(), 'b' => $neighbor->toArray()];
                $a = $mapping->priority;
                $mapping->update(['priority' => $neighbor->priority]);
                $neighbor->update(['priority' => $a]);
                $audit->record($request, 'catalog.mapping.reordered', 'provider_mapping', $mapping->id,
                    $before, ['a' => $mapping->toArray(), 'b' => $neighbor->toArray()]);
            });

            return back();
        }

        $provider = Provider::findOrFail($mapping->provider_id);
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'cost_idr' => [$provider->code === 'MANUAL' ? 'required' : 'prohibited', 'integer', 'min:0', 'max:1000000000'],
            'is_manual_price' => ['prohibited'],
            'manual_price_idr' => ['prohibited'],
            'store_cost_idr' => [$provider->code === 'MANUAL' ? 'sometimes' : 'prohibited', 'integer', 'min:0', 'max:1000000000'],
        ]);
        if ($provider->code === 'MANUAL' && array_key_exists('cost_idr', $data)) {
            if (! array_key_exists('store_cost_idr', $data)
                && ($mapping->store_cost_idr === null || (int) $data['cost_idr'] !== (int) $mapping->cost_idr)) {
                $data['store_cost_idr'] = $data['cost_idr'];
            }
        } elseif ($provider->code !== 'MANUAL') {
            unset($data['cost_idr']);
        }

        if ($provider->code === 'DIGIFLAZZ') {
            if ($data['is_active']) {
                $product = Product::query()
                    ->join('product_packages', 'product_packages.product_id', '=', 'products.id')
                    ->where('product_packages.id', $mapping->product_package_id)
                    ->select('products.*')
                    ->firstOrFail();
                $template = trim((string) ($product->customer_no_template ?? ''));
                $fieldKeys = $product->fields()->pluck('field_key')->all();
                $requiredKeys = $product->fields()->where('is_required', true)->pluck('field_key')->all();
                preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
                $references = $matches[1] ?? [];
                if ($product->is_active && $product->fulfillment_mode === 'AUTO_PROVIDER'
                    && (($template !== '' && $references === [])
                        || (count($fieldKeys) > 1 && $template === '')
                        || ($template !== '' && array_diff($references, $fieldKeys))
                        || ($template !== '' && array_diff($requiredKeys, $references)))) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Produk harus memiliki format Digiflazz yang mencantumkan semua kolom wajib sebelum mapping diaktifkan.',
                    ]);
                }
            }
            // An explicit admin edit overrides any earlier sync-generated disable.
            $data['disabled_by_sync'] = false;
        }

        // Sumber manual tanpa modal tidak bisa dijual. Matikan nominalnya
        // sekalian supaya tidak tampil aktif tapi selalu gagal di checkout.
        $manualCostZero = $provider->code === 'MANUAL'
            && (int) ($data['store_cost_idr'] ?? $mapping->store_cost_idr ?? $mapping->cost_idr ?? 0) < 1;
        if ($manualCostZero) {
            $data['is_active'] = false;
        }

        DB::transaction(function () use ($request, $mapping, $data, $audit, $manualCostZero, $provider): void {
            if ($provider->code === 'DIGIFLAZZ' && $data['is_active']) {
                // Serialize concurrent activations for the same package.
                DB::table('product_packages')->where('id', $mapping->product_package_id)
                    ->lockForUpdate()->first();
                if (DB::table('provider_mappings')
                    ->where('product_package_id', $mapping->product_package_id)
                    ->where('provider_id', $provider->id)
                    ->where('id', '<>', $mapping->id)
                    ->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Nominal ini sudah memiliki SKU Digiflazz aktif. Nonaktifkan SKU lama terlebih dahulu.',
                    ]);
                }
            }
            $before = $mapping->toArray();
            $mapping->update($data);
            if ($manualCostZero) {
                DB::table('product_packages')->where('id', $mapping->product_package_id)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
            $audit->record($request, 'catalog.mapping.updated', 'provider_mapping', $mapping->id,
                $before, $mapping->toArray());
        });

        return back();
    }
}
