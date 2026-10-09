<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\AdminAuditService;
use App\Services\AdminDigiflazzMonitorService;
use App\Services\AdminPermissionService;
use App\Services\DigiflazzAutoSources;
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
    public function index(
        Request $request,
        DigiflazzCatalogService $service,
        AdminDigiflazzMonitorService $monitor,
    ): Response {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'product' => ['nullable', 'string', 'max:255'],
            'health' => ['nullable', 'in:healthy,warning,critical'],
            'scope' => ['nullable', 'in:mapped,all'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);
        $filters = [
            'q' => trim((string) ($filters['q'] ?? '')),
            'category' => trim((string) ($filters['category'] ?? '')),
            'brand' => trim((string) ($filters['brand'] ?? '')),
            'product' => trim((string) ($filters['product'] ?? '')),
            'health' => (string) ($filters['health'] ?? ''),
            'scope' => (string) ($filters['scope'] ?? 'all'),
            'per_page' => (int) ($filters['per_page'] ?? 25),
        ];

        $provider = Provider::where('code', 'DIGIFLAZZ')->first();
        $providerId = $provider?->id;

        $base = DB::table('digiflazz_catalog_items as items')
            ->leftJoin('provider_mappings as mappings', function ($join) use ($providerId): void {
                $join->on('mappings.external_sku', '=', 'items.buyer_sku_code');
                if ($providerId) {
                    $join->where('mappings.provider_id', '=', $providerId);
                } else {
                    $join->whereRaw('1 = 0');
                }
            })
            ->leftJoin('product_packages as packages', 'packages.id', '=', 'mappings.product_package_id')
            ->leftJoin('products', 'products.id', '=', 'packages.product_id')
            ->when($filters['scope'] === 'mapped', fn ($query) => $query->whereNotNull('mappings.id'))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($nested) use ($like): void {
                    $nested->where('items.product_name', 'like', $like)
                        ->orWhere('items.buyer_sku_code', 'like', $like)
                        ->orWhere('items.seller_name', 'like', $like)
                        ->orWhere('items.brand', 'like', $like)
                        ->orWhere('products.name', 'like', $like)
                        ->orWhere('packages.name', 'like', $like);
                });
            })
            ->when($filters['category'] !== '', fn ($query) => $query->where('items.category', $filters['category']))
            ->when($filters['brand'] !== '', fn ($query) => $query->where('items.brand', $filters['brand']))
            ->when($filters['product'] !== '', function ($query) use ($filters): void {
                $query->where(function ($nested) use ($filters): void {
                    $nested->where('products.name', $filters['product'])
                        ->orWhere(function ($catalog) use ($filters): void {
                            $catalog->whereNull('products.id')->where('items.product_name', $filters['product']);
                        });
                });
            });

        $monitorSettings = $monitor->settings();
        $summaryRows = (clone $base)->get([
            'items.buyer_active', 'items.seller_active', 'items.unlimited_stock', 'items.stock',
            'items.start_cut_off', 'items.end_cut_off', 'items.price_idr', 'items.baseline_price_idr',
        ]);
        $summary = $monitor->summary($summaryRows, $monitorSettings);

        $this->applyHealthFilter($base, $filters['health'], $monitorSettings);

        $productOptions = (clone $base)
            ->reorder()
            ->selectRaw('COALESCE(products.name, items.product_name) as name')
            ->distinct()
            ->orderBy('name')
            ->pluck('name');

        $items = $base
            ->orderByRaw('COALESCE(products.name, items.product_name)')
            ->orderByRaw('COALESCE(packages.sort_order, 999999)')
            ->orderBy('items.product_name')
            ->select([
                'items.*',
                'mappings.id as mapping_id',
                'mappings.is_active as mapping_active',
                'packages.id as package_id',
                'packages.name as local_package_name',
                'products.id as product_id',
                'products.name as local_product_name',
            ])
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(function (object $item) use ($service, $monitor, $monitorSettings): array {
                $health = $monitor->health($item, $monitorSettings);

                return [
                    ...((array) $item),
                    ...$health,
                    'available' => $service->available($item),
                    'mapped' => $item->mapping_id !== null,
                    'mapping_active' => (bool) $item->mapping_active,
                    'multi' => (bool) ($item->multi ?? false),
                    'buyer_active' => (bool) $item->buyer_active,
                    'seller_active' => (bool) $item->seller_active,
                    'unlimited_stock' => (bool) $item->unlimited_stock,
                ];
            });

        $admin = $request->user('admin');
        $connection = $monitor->connection(app(AdminPermissionService::class)->allows($admin, 'reports.finance'));

        return Inertia::render('Admin/Digiflazz', [
            'items' => $items,
            'filters' => $filters,
            'categories' => DB::table('digiflazz_catalog_items')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category'),
            'brands' => DB::table('digiflazz_catalog_items')->where('brand', '!=', '')->distinct()->orderBy('brand')->pluck('brand'),
            'products' => $productOptions,
            'summary' => $summary,
            'connection' => $connection,
            'lastSyncedAt' => DB::table('digiflazz_catalog_items')->max('synced_at'),
            'catalogSync' => json_decode((string) DB::table('system_settings')->where('key', 'digiflazz.last_catalog_sync')->value('value'), true),
            'mappingCount' => ProviderMapping::where('provider_id', $providerId ?: 0)->count(),
            'autoSync' => (bool) (json_decode((string) DB::table('system_settings')->where('key', 'digiflazz.auto_sync')->value('value'), true) ?? true),
            'monitorSettings' => $monitorSettings,
            'providerActive' => (bool) $provider?->is_active,
            'recentTransactions' => $monitor->recentTransactions(),
            'canSeeBalance' => app(AdminPermissionService::class)->allows($admin, 'reports.finance'),
        ]);
    }

    private function applyHealthFilter($query, string $health, array $settings): void
    {
        if ($health === '') {
            return;
        }

        $critical = function ($nested): void {
            $nested->where('items.buyer_active', false)
                ->orWhere('items.seller_active', false)
                ->orWhere(function ($stock): void {
                    $stock->where('items.unlimited_stock', false)->where('items.stock', '<=', 0);
                });
        };
        $warning = function ($nested) use ($settings): void {
            $time = now('Asia/Jakarta')->format('H:i');
            $lowStockThreshold = (int) $settings['low_stock_threshold'];
            $priceWarningPercent = (float) $settings['price_warning_percent'];
            $nested->where(function ($stock) use ($lowStockThreshold): void {
                $stock->where('items.unlimited_stock', false)
                    ->whereBetween('items.stock', [1, $lowStockThreshold]);
            })->orWhereRaw(
                'items.baseline_price_idr > 0 AND ((CAST(items.price_idr AS DECIMAL(30, 4)) - CAST(items.baseline_price_idr AS DECIMAL(30, 4))) * 100) >= (CAST(items.baseline_price_idr AS DECIMAL(30, 4)) * ?)',
                [$priceWarningPercent]
            )
                ->orWhere(function ($cutoff) use ($time): void {
                    $cutoff->whereColumn('items.start_cut_off', '!=', 'items.end_cut_off')
                        ->where(function ($window) use ($time): void {
                            $window->where(function ($normal) use ($time): void {
                                $normal->whereColumn('items.start_cut_off', '<', 'items.end_cut_off')
                                    ->where('items.start_cut_off', '<=', $time)
                                    ->where('items.end_cut_off', '>', $time);
                            })->orWhere(function ($wrap) use ($time): void {
                                $wrap->whereColumn('items.start_cut_off', '>', 'items.end_cut_off')
                                    ->where(function ($clock) use ($time): void {
                                        $clock->where('items.start_cut_off', '<=', $time)
                                            ->orWhere('items.end_cut_off', '>', $time);
                                    });
                            });
                        });
                });
        };

        if ($health === 'critical') {
            $query->where($critical);

            return;
        }

        $query->whereNot($critical);
        if ($health === 'warning') {
            $query->where($warning);

            return;
        }

        $query->whereNot($warning);
    }

    public function settings(
        Request $request,
        AdminAuditService $audit,
        AdminDigiflazzMonitorService $monitor,
    ): RedirectResponse {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440', 'multiple_of:5'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'price_warning_percent' => ['sometimes', 'numeric', 'min:0.1', 'max:100'],
        ]);
        $monitorSettings = $monitor->settings();
        $before = [
            'enabled' => (bool) (json_decode((string) DB::table('system_settings')->where('key', 'digiflazz.auto_sync')->value('value'), true) ?? true),
            ...$monitorSettings,
        ];
        $map = [
            'digiflazz.auto_sync' => (bool) $data['enabled'],
            'digiflazz.auto_sync_interval_minutes' => (int) ($data['sync_interval_minutes'] ?? $monitorSettings['sync_interval_minutes']),
            'digiflazz.low_stock_threshold' => (int) ($data['low_stock_threshold'] ?? $monitorSettings['low_stock_threshold']),
            'digiflazz.price_warning_percent' => (float) ($data['price_warning_percent'] ?? $monitorSettings['price_warning_percent']),
        ];
        foreach ($map as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => $key], [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
        $audit->record($request, 'digiflazz.monitor.settings_updated', 'system_setting', 'digiflazz', $before, $data);

        return back()->with('status', 'Pengaturan monitor dan sinkron otomatis disimpan.');
    }

    public function syncProduct(Request $request, Product $product, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        abort_unless($product->fulfillment_mode === 'AUTO_PROVIDER', 422);
        $skus = ProviderMapping::whereIn('product_package_id', $product->packages()->pluck('id'))
            ->whereIn('provider_id', Provider::where('code', 'DIGIFLAZZ')->pluck('id'))
            ->whereNotNull('external_sku')->pluck('external_sku')->unique();
        if ($skus->isEmpty()) {
            throw ValidationException::withMessages(['sync' => 'Produk belum memiliki mapping Digiflazz.']);
        }
        // One complete request also refreshes availability of removed SKUs atomically.
        $count = $service->sync();
        $audit->record($request, 'digiflazz.product.synced', 'product', $product->id, null, ['count' => $count, 'skus' => $skus->values()->all()]);

        return back()->with('status', 'Harga dan ketersediaan nominal produk diperbarui.');
    }

    public function sync(Request $request, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['item_id' => ['nullable', 'integer', 'exists:digiflazz_catalog_items,id']]);
        $sku = isset($data['item_id']) ? DB::table('digiflazz_catalog_items')->where('id', $data['item_id'])->value('buyer_sku_code') : null;
        $count = $service->sync($sku);
        $audit->record($request, 'digiflazz.catalog.synced', 'provider', 'DIGIFLAZZ', null, ['count' => $count, 'sku' => $sku]);

        return back()->with('status', $sku !== null && $count === 0
            ? 'SKU sudah tidak ada di Digiflazz dan telah dihapus dari katalog.'
            : $count.' SKU berhasil disinkronkan. SKU yang sudah tidak ada dihapus dari katalog.');
    }

    public function syncMapping(Request $request, ProviderMapping $mapping, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        abort_unless(Provider::where('id', $mapping->provider_id)->where('code', 'DIGIFLAZZ')->exists(), 404);
        $before = $mapping->toArray();
        $count = $service->sync($mapping->external_sku);
        $audit->record($request, 'digiflazz.mapping.synced', 'provider_mapping', $mapping->id, $before, $mapping->fresh()->toArray());

        return back()->with('status', $count === 0
            ? 'SKU sudah tidak ada di Digiflazz. Katalog dihapus dan mapping dinonaktifkan.'
            : 'Harga nominal berhasil disinkronkan.');
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

    public function attachSource(Request $request, ProductPackage $package, DigiflazzCatalogService $service, DigiflazzCatalogImport $importer, AdminAuditService $audit): RedirectResponse
    {
        abort_unless($package->product->fulfillment_mode === 'AUTO_PROVIDER', 422);

        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:digiflazz_catalog_items,id'],
        ]);

        DB::transaction(function () use ($request, $package, $service, $importer, $audit, $data): void {
            $item = DB::table('digiflazz_catalog_items')->where('id', $data['item_id'])->lockForUpdate()->firstOrFail();
            if (! $service->available($item) || now()->diffInHours($item->synced_at, true) > 24) {
                throw ValidationException::withMessages([
                    'item_id' => 'SKU tidak tersedia atau data lebih dari 24 jam. Sinkronkan Digiflazz dahulu.',
                ]);
            }

            $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
            $existing = ProviderMapping::where('provider_id', $provider->id)
                ->where('external_sku', $item->buyer_sku_code)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'item_id' => $existing->product_package_id === $package->id
                        ? 'SKU ini sudah menjadi sumber fulfillment nominal tersebut.'
                        : 'SKU ini sudah digunakan oleh nominal lain.',
                ]);
            }

            try {
                $mapping = $importer->upsert(
                    $package,
                    (string) $item->buyer_sku_code,
                    (int) $item->price_idr,
                    (int) $item->price_idr
                );
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['item_id' => $exception->getMessage()]);
            }

            $priority = (int) ProviderMapping::where('product_package_id', $package->id)
                ->whereKeyNot($mapping->id)
                ->max('priority') + 1;
            $primaryConfig = ProviderMapping::where('product_package_id', $package->id)
                ->where('provider_id', $provider->id)
                ->whereKeyNot($mapping->id)
                ->orderBy('priority')
                ->value('fulfillment_config');

            $before = $mapping->toArray();
            $mapping->update([
                'priority' => $priority,
                'is_active' => false,
                'fulfillment_config' => is_string($primaryConfig)
                    ? json_decode($primaryConfig, true)
                    : $primaryConfig,
            ]);
            $audit->record(
                $request,
                'catalog.fulfillment_source.attached',
                'provider_mapping',
                $mapping->id,
                $before,
                $mapping->toArray()
            );
        }, 3);

        return back()->with('status', 'Sumber fulfillment ditambahkan dalam keadaan nonaktif. Periksa prioritas, format tujuan, dan batas harga sebelum mengaktifkannya.');
    }

    public function import(Request $request, Product $product, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        abort_unless($product->fulfillment_mode === 'AUTO_PROVIDER', 422);
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'item_ids.*' => ['required', 'integer', 'distinct', 'exists:digiflazz_catalog_items,id'],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'publish' => ['sometimes', 'boolean'],
            'auto_sources' => ['sometimes', 'boolean'],
            'customer_no_template' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $product, $service, $audit, $data): void {
            $product = $product->lockForUpdate()->findOrFail($product->id);
            $publish = (bool) ($data['publish'] ?? false);
            $template = $this->importCustomerTemplate($product, $data['customer_no_template'] ?? null, $publish);
            if ($publish && (! $product->category->is_active || ! Provider::where('code', 'DIGIFLAZZ')->where('is_active', true)->exists())) {
                throw ValidationException::withMessages(['publish' => 'Aktifkan kategori produk dan integrasi Digiflazz sebelum menjual.']);
            }
            $automatic = (bool) ($data['auto_sources'] ?? true);
            $selected = DB::table('digiflazz_catalog_items')->whereIn('id', $data['item_ids'])->lockForUpdate()->get();
            foreach ($selected as $item) {
                if (! $service->available($item) || now()->diffInHours($item->synced_at, true) > 24) {
                    throw ValidationException::withMessages(['item_ids' => 'SKU tidak tersedia atau data lebih dari 24 jam. Sinkronkan dahulu.']);
                }
                if ($product->package_tabs_enabled && ! in_array(trim((string) $item->type), $product->package_tabs ?? [], true)) {
                    throw ValidationException::withMessages(['item_ids' => 'Grup nominal belum tersedia pada tab produk. Tambahkan tab "'.$item->type.'" dahulu.']);
                }
            }
            $sources = app(DigiflazzAutoSources::class);
            $groups = $automatic
                ? $selected->groupBy(fn (object $item): string => $sources->key($item))
                : $selected->groupBy('buyer_sku_code');
            $catalog = $automatic ? $sources->catalog() : collect();
            foreach ($groups as $key => $items) {
                $sources->importGroup($product, $automatic ? $catalog->get($key) : $items, $template, $publish, (string) $data['margin_percent'], $automatic, $request);
            }
            $sources->orderProduct($product);
            if ($publish && ! $product->is_active) {
                $before = $product->toArray();
                $product->update(['is_active' => true]);
                $audit->record($request, 'catalog.product.updated', 'product', $product->id, $before, $product->fresh()->toArray());
            }
        }, 3);

        return back()->with('status', ($data['publish'] ?? false)
            ? 'Nominal berhasil diimpor dan sudah bisa dipilih di toko.'
            : 'Nominal disimpan sebagai draf.');
    }

    public function autoSources(Request $request, Product $product, DigiflazzAutoSources $sources): RedirectResponse
    {
        abort_unless($product->fulfillment_mode === 'AUTO_PROVIDER', 422);
        $added = $sources->enableProduct($product, $request);

        return back()->with('status', 'Pelengkapan cadangan selesai. '.$added.' sumber baru ditambahkan untuk nominal produk.');
    }

    private function importCustomerTemplate(Product $product, ?string $value, bool $publish): ?string
    {
        $fields = $product->fields()->orderBy('sort_order')->get();
        $template = trim((string) $value);
        if ($template === '' && $fields->count() === 1) {
            $template = '{{'.$fields->first()->field_key.'}}';
        }
        if ($publish && ($fields->isEmpty() || $template === '')) {
            throw ValidationException::withMessages(['customer_no_template' => 'Tentukan format ID tujuan agar nominal langsung bisa dijual.']);
        }
        if ($template === '') {
            return null;
        }
        preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $template, $matches);
        $references = $matches[1] ?? [];
        $remaining = preg_replace('/\{\{([a-z][a-z0-9_]*)\}\}/', '', $template);
        if ($references === [] || array_diff($references, $fields->pluck('field_key')->all())
            || str_contains($remaining, '{') || str_contains($remaining, '}')
            || ($publish && array_diff($fields->where('is_required', true)->pluck('field_key')->all(), $references))) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format ID tujuan harus memakai kolom pelanggan yang benar dan menyertakan semua kolom wajib.']);
        }

        return $template;
    }
}
