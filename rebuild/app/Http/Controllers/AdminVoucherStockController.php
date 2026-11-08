<?php

namespace App\Http\Controllers;

use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\CatalogAudit;
use App\Services\VoucherStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menerima kode voucher dari admin untuk satu paket dan menyerahkannya ke
 * VoucherStockService. Stok kode hanya berlaku untuk produk otomatis
 * penyedia; pemakaian kode saat pesanan masuk bukan urusan class ini.
 */
class AdminVoucherStockController
{
    public function voucherStock(
        Request $request,
        ProductPackage $package,
        VoucherStockService $stock,
        CatalogAudit $audit
    ): RedirectResponse {
        $product = $package->product()->firstOrFail();
        if ($product->fulfillment_mode !== 'AUTO_PROVIDER') {
            throw ValidationException::withMessages([
                'stock_key' => 'Stok kode hanya dapat digunakan pada produk otomatis.',
            ]);
        }

        $data = $request->validate([
            'stock_key' => ['required', 'string', 'min:2', 'max:100'],
            'cost_idr' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'codes_text' => ['nullable', 'string', 'max:500000'],
        ]);
        $stockKey = $stock->normalizeKey($data['stock_key']);
        $provider = Provider::where('code', 'VOUCHER_STOCK')->firstOrFail();
        $result = ['received' => 0, 'imported' => 0, 'duplicates' => 0];

        DB::transaction(function () use (
            $request,
            $package,
            $provider,
            $data,
            $stockKey,
            $stock,
            $audit,
            &$result
        ): void {
            // Keep the Digiflazz rule when a formerly Digiflazz-only nominal
            // gains voucher stock. Otherwise later voucher price edits would
            // silently change Digiflazz's selling price on stock exhaustion.
            $lockedPackage = ProductPackage::whereKey($package->id)->lockForUpdate()->firstOrFail();
            if ($lockedPackage->digiflazz_markup_mode === null
                && $lockedPackage->mappings()->where('provider_id', Provider::where('code', 'DIGIFLAZZ')->value('id'))->exists()) {
                $packageBefore = $lockedPackage->toArray();
                $fixed = $lockedPackage->pricing_mode === 'FIXED';
                $lockedPackage->forceFill([
                    'digiflazz_markup_mode' => $fixed ? 'FIXED' : 'PERCENT',
                    'digiflazz_markup_percent' => $fixed ? null : ($lockedPackage->margin_percent ?? 12),
                    'digiflazz_markup_fixed_idr' => $fixed ? $lockedPackage->margin_fixed_idr : null,
                ])->save();
                $audit->record($request, 'catalog.package.updated', 'product_package',
                    $lockedPackage->id, $packageBefore, $lockedPackage->toArray());
            }

            $mapping = ProviderMapping::firstOrNew([
                'product_package_id' => $package->id,
                'provider_id' => $provider->id,
            ]);
            $before = $mapping->exists ? $mapping->toArray() : null;
            $mapping->fill([
                'external_sku' => 'stock-package-'.$package->id,
                'cost_idr' => $data['cost_idr'],
                'store_cost_idr' => $data['cost_idr'],
                'max_price_idr' => null,
                'fulfillment_config' => ['stock_key' => $stockKey],
                'priority' => $data['priority'],
                'is_active' => $data['is_active'],
            ])->save();

            if (trim((string) ($data['codes_text'] ?? '')) !== '') {
                $result = $stock->import($stockKey, (string) $data['codes_text']);
            }

            $audit->record(
                $request,
                'catalog.voucher_stock.updated',
                'provider_mapping',
                $mapping->id,
                $before,
                [
                    'mapping' => $mapping->toArray(),
                    'stock_import' => $result,
                ]
            );
        }, 3);

        $message = $result['received'] > 0
            ? 'Stok kode tersimpan. '.$result['imported'].' kode baru, '.$result['duplicates'].' duplikat dilewati.'
            : 'Pengaturan stok kode tersimpan.';

        return back()->with('status', $message);
    }
}
