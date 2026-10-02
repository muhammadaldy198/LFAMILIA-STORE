<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\AdminAuditService;
use App\Services\DigiflazzCatalogImport;
use App\Services\DigiflazzCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminDigiflazzController
{
    public function index(Request $request, DigiflazzCatalogService $service): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'brand' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:active,attention']]);
        $items = DB::table('digiflazz_catalog_items')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($nested) => $nested->where('product_name', 'like', '%'.$q.'%')->orWhere('buyer_sku_code', 'like', '%'.$q.'%')->orWhere('seller_name', 'like', '%'.$q.'%')))
            ->when($filters['brand'] ?? null, fn ($query, $brand) => $query->where('brand', $brand))
            ->when(($filters['status'] ?? '') === 'active', fn ($q) => $q->where('buyer_active', true)->where('seller_active', true)->where(fn ($q) => $q->where('unlimited_stock', true)->orWhere('stock', '>', 0)))
            ->when(($filters['status'] ?? '') === 'attention', fn ($q) => $q->where(fn ($q) => $q->where('buyer_active', false)->orWhere('seller_active', false)->orWhere(fn ($q) => $q->where('unlimited_stock', false)->where('stock', 0))->orWhereColumn('price_idr', '>', 'baseline_price_idr')))
            ->orderBy('brand')->orderBy('product_name')->paginate(25)->withQueryString()
            ->through(fn (object $item): array => [...((array) $item), 'available' => $service->available($item)]);
        return Inertia::render('Admin/Digiflazz', [
            'items' => $items, 'filters' => $filters,
            'brands' => DB::table('digiflazz_catalog_items')->distinct()->orderBy('brand')->pluck('brand'),
            'lastSyncedAt' => DB::table('digiflazz_catalog_items')->max('synced_at'),
            'mappingCount' => ProviderMapping::whereIn('provider_id', Provider::where('code', 'DIGIFLAZZ')->pluck('id'))->count(),
            'providerActive' => (bool) Provider::where('code', 'DIGIFLAZZ')->value('is_active'),
        ]);
    }

    public function sync(Request $request, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['item_id' => ['nullable', 'integer', 'exists:digiflazz_catalog_items,id']]);
        $sku = isset($data['item_id']) ? DB::table('digiflazz_catalog_items')->where('id', $data['item_id'])->value('buyer_sku_code') : null;
        $count = $service->sync($sku);
        $audit->record($request, 'digiflazz.catalog.synced', 'provider', 'DIGIFLAZZ', null, ['count' => $count, 'sku' => $sku]);
        return back()->with('status', $count.' SKU berhasil disinkronkan.');
    }

    public function syncMapping(Request $request, ProviderMapping $mapping, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        abort_unless(Provider::where('id', $mapping->provider_id)->where('code', 'DIGIFLAZZ')->exists(), 404);
        $before = $mapping->toArray();
        $service->sync($mapping->external_sku);
        $audit->record($request, 'digiflazz.mapping.synced', 'provider_mapping', $mapping->id, $before, $mapping->fresh()->toArray());
        return back()->with('status', 'Harga nominal berhasil disinkronkan.');
    }

    public function baseline(Request $request, int $id, AdminAuditService $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $audit): void {
            $item = DB::table('digiflazz_catalog_items')->where('id', $id)->lockForUpdate()->first();
            abort_unless($item, 404);
            DB::table('digiflazz_catalog_items')->where('id', $id)->update(['baseline_price_idr' => $item->price_idr, 'updated_at' => now()]);
            $audit->record($request, 'digiflazz.baseline.updated', 'digiflazz_catalog_item', $id, ['price_idr' => $item->baseline_price_idr], ['price_idr' => $item->price_idr]);
        });
        return back();
    }

    public function import(Request $request, Product $product, DigiflazzCatalogService $service, DigiflazzCatalogImport $importer, AdminAuditService $audit): RedirectResponse
    {
        abort_unless($product->fulfillment_mode === 'AUTO_PROVIDER', 422);
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['required', 'integer', 'distinct', 'exists:digiflazz_catalog_items,id'],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $product, $service, $importer, $audit, $data): void {
            $product->lockForUpdate()->findOrFail($product->id);
            $order = (int) $product->packages()->max('sort_order') + 1;
            foreach (DB::table('digiflazz_catalog_items')->whereIn('id', $data['item_ids'])->lockForUpdate()->get() as $item) {
                if (! $service->available($item) || now()->diffInHours($item->synced_at, true) > 24) {
                    throw ValidationException::withMessages(['item_ids' => 'SKU tidak tersedia atau data lebih dari 24 jam. Sinkronkan dahulu.']);
                }
                if (ProviderMapping::whereIn('provider_id', Provider::where('code', 'DIGIFLAZZ')->pluck('id'))->where('external_sku', $item->buyer_sku_code)->exists()) {
                    throw ValidationException::withMessages(['item_ids' => 'SKU '.$item->buyer_sku_code.' sudah dipakai nominal lain.']);
                }
                $package = ProductPackage::create([
                    'product_id' => $product->id, 'code' => 'DF_'.substr(hash('sha256', $item->buyer_sku_code), 0, 20),
                    'name' => $item->product_name, 'group_name' => $item->type ?: null,
                    'sort_order' => $order++, 'is_active' => false,
                    'pricing_mode' => 'PERCENT', 'margin_percent' => $data['margin_percent'],
                ]);
                $importer->upsert($package, $item->buyer_sku_code, (int) $item->price_idr, (int) $item->price_idr);
                $audit->record($request, 'catalog.package.imported', 'product_package', $package->id, null, $package->toArray());
            }
        }, 3);
        return back()->with('status', 'Nominal diimpor. Periksa input customer dan aktifkan nominal serta mapping untuk menjual.');
    }
}
