<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\CatalogPricingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan pengaturan harga global katalog dari panel admin: persen
 * penambah modal dan persen markup jual yang menjadi bawaan, sebelum
 * ditimpa pengaturan per produk atau per nominal.
 */
class AdminCatalogPricingController
{
    /**
     * Global pricing controls of the catalog redesign: the one-time cost uplift
     * that produces the admin store cost, and the sell markup applied to it.
     * Both are percentages and both are overridable per game and per nominal.
     */
    public function pricing(Request $request, AdminAuditService $audit, CatalogPricingSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'cost_uplift_percent' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'sell_markup_percent' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
        ]);

        DB::transaction(function () use ($request, $data, $audit, $settings): void {
            $before = [
                'cost_uplift_percent' => $settings->costUpliftPercent(),
                'sell_markup_percent' => $settings->sellMarkupPercent(),
            ];
            $settings->update($data['cost_uplift_percent'], $data['sell_markup_percent']);
            $audit->record($request, 'catalog.pricing.updated', 'system_setting', 'pricing', $before, [
                'cost_uplift_percent' => $settings->costUpliftPercent(),
                'sell_markup_percent' => $settings->sellMarkupPercent(),
            ]);
        });

        return back()->with('status', 'Pengaturan harga katalog disimpan.');
    }
}
