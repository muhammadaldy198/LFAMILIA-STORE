<?php

namespace App\Http\Controllers;

use App\Models\StoreAsset;
use App\Services\CatalogAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mengubah aset toko dari panel admin: status aktif dan tautan tujuannya.
 */
class AdminStoreAssetController
{
    public function asset(Request $request, StoreAsset $asset, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'target_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        DB::transaction(function () use ($request, $asset, $data, $audit): void {
            $before = $asset->toArray();
            $asset->update($data);
            $audit->record($request, 'catalog.asset.updated', 'store_asset', $asset->id,
                $before, $asset->toArray());
        });

        return back();
    }
}
