<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\AdminAuditService;
use App\Services\AdminDigiflazzMonitorService;
use App\Services\AdminPermissionService;
use App\Services\CatalogNominalOrder;
use App\Services\DigiflazzCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman monitor Digiflazz di panel admin: tabel kesehatan produk
 * provider (stok, masa cutoff, selisih harga) beserta pengaturan monitor
 * dan sinkron otomatisnya. Menjalankan sinkron diurus
 * AdminDigiflazzSyncController; impor katalog diurus
 * AdminDigiflazzImportController.
 */
class AdminDigiflazzController
{
    public function index(
        Request $request,
        DigiflazzCatalogService $service,
        AdminDigiflazzMonitorService $monitor,
    ): Response {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu lintasan baca-tampil — query monitor dibangun bertahap
        // (gabung mapping, saring, urutkan), lalu status kesehatan tiap
        // baris disusun dalam konteks yang sama; menyebar langkahnya
        // akan memecah satu definisi tabel menjadi berkeping-keping.
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
            'items.is_present', 'items.buyer_active', 'items.seller_active', 'items.unlimited_stock', 'items.stock',
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

        // Apply natural alphanumeric order to the complete filtered set, not only the page.
        $natural = app(CatalogNominalOrder::class);
        $sorted = $base->select([
            'items.*',
            'mappings.id as mapping_id',
            'mappings.is_active as mapping_active',
            'packages.id as package_id',
            'packages.name as local_package_name',
            'packages.nominal_value as local_nominal_value',
            'products.id as product_id',
            'products.name as local_product_name',
        ])->get()->sort(function (object $a, object $b) use ($natural): int {
            $groupA = $a->local_product_name ?: ($a->brand ?: $a->product_name);
            $groupB = $b->local_product_name ?: ($b->brand ?: $b->product_name);
            $group = strnatcasecmp($groupA, $groupB);
            if ($group !== 0) {
                return $group;
            }
            $type = strnatcasecmp((string) $a->type, (string) $b->type);
            if ($type !== 0) {
                return $type;
            }
            $numberA = $a->local_nominal_value ?? $natural->value($a->product_name, $a->brand);
            $numberB = $b->local_nominal_value ?? $natural->value($b->product_name, $b->brand);

            return ($numberA ?? PHP_INT_MAX) <=> ($numberB ?? PHP_INT_MAX)
                ?: strnatcasecmp($a->local_package_name ?: $a->product_name, $b->local_package_name ?: $b->product_name)
                ?: strcmp($a->buyer_sku_code, $b->buyer_sku_code);
        })->values();
        $currentPage = max(1, (int) $request->query('page', 1));
        $items = (new LengthAwarePaginator(
            $sorted->forPage($currentPage, $filters['per_page'])->values(),
            $sorted->count(), $filters['per_page'], $currentPage,
            ['path' => $request->url(), 'query' => $request->query()],
        ))->through(function (object $item) use ($service, $monitor, $monitorSettings): array {
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
        // Alasan belum dipecah: satu definisi saringan kesehatan — syarat
        // stok dan jendela waktu cutoff yang bersarang membentuk satu
        // makna sehat, waspada, dan kritis; memisahkannya membuat
        // definisinya terbaca setengah-setengah.
        if ($health === '') {
            return;
        }

        $critical = function ($nested): void {
            $nested->where('items.is_present', false)->orWhere('items.buyer_active', false)
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
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:15', 'max:1440', 'multiple_of:5'],
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
}
