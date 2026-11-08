<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\AdminAuditService;
use App\Services\DigiflazzCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan sinkronisasi katalog Digiflazz dari panel admin:
 * sinkron penuh dan sinkron satu mapping provider. Pengaturan jadwal
 * dan ambang monitornya diurus AdminDigiflazzController.
 */
class AdminDigiflazzSyncController
{
    public function sync(Request $request, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['item_id' => ['nullable', 'integer', 'exists:digiflazz_catalog_items,id']]);
        $sku = isset($data['item_id']) ? DB::table('digiflazz_catalog_items')->where('id', $data['item_id'])->value('buyer_sku_code') : null;
        $count = $service->sync($sku);
        $audit->record($request, 'digiflazz.catalog.synced', 'provider', 'DIGIFLAZZ', null, ['count' => $count, 'sku' => $sku]);

        if ($sku !== null) {
            $status = $count === 0
                ? 'SKU tidak tersedia dari Digiflazz; data katalog dan mapping diperbarui sesuai riwayat transaksi.'
                : 'Harga dan ketersediaan SKU berhasil disinkronkan.';
        } else {
            $sync = json_decode((string) DB::table('system_settings')->where('key', 'digiflazz.last_catalog_sync')->value('value'), true) ?? [];
            $status = $count.' SKU berhasil disinkronkan. '
                .($sync['missing_marked_unavailable'] ?? 0).' mapping ber-riwayat ditandai tidak tersedia; '
                .($sync['mappings_deleted_without_history'] ?? 0).' mapping tanpa riwayat dibersihkan.';
        }

        return back()->with('status', $status);
    }

    public function syncMapping(Request $request, ProviderMapping $mapping, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        abort_unless(Provider::where('id', $mapping->provider_id)->where('code', 'DIGIFLAZZ')->exists(), 404);
        $before = $mapping->toArray();
        $count = $service->sync($mapping->external_sku);
        $after = $mapping->fresh()?->toArray() ?? [
            'deleted' => true,
            'external_sku' => $before['external_sku'] ?? null,
            'provider_id' => $before['provider_id'] ?? null,
        ];
        $audit->record($request, 'digiflazz.mapping.synced', 'provider_mapping', $mapping->id, $before, $after);

        return back()->with('status', $count === 0
            ? 'SKU tidak tersedia dari Digiflazz; data katalog dan mapping diperbarui sesuai riwayat transaksi.'
            : 'Harga nominal berhasil disinkronkan.');
    }
}
