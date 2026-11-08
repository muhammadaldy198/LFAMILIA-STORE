<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductNotice;
use App\Models\ProductPackage;
use App\Models\ProductPackageGroup;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\CatalogAudit;
use App\Services\CatalogNominalOrder;
use App\Services\CatalogPricingSettings;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzCatalogService;
use App\Services\DigiflazzPriceOwnership;
use App\Services\VoucherStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman daftar katalog admin: menampilkan produk beserta status paket,
 * mapping, dan stoknya, plus saklar auto-sync Digiflazz.
 * Mengubah kategori, produk, paket, mapping, dan harga bukan urusan class ini —
 * masing-masing punya controller katalognya sendiri.
 */
class AdminCatalogController
{
    // Dipakai halaman Produk: data per halaman dari server, bukan disaring di browser.
    private function productRow(Product $product, $providers, array $stockCounts, array $buyablePackageIds, array $priceOwners): array
    {
        $activePackages = $product->packages->where('is_active', true);
        $buyableCount = $activePackages->filter(
            fn (ProductPackage $package): bool => isset($buyablePackageIds[(int) $package->id])
        )->count();
        $activeMappings = $activePackages->flatMap(
            fn (ProductPackage $package) => $package->mappings->where('is_active', true)
        );
        $hasEnabledProvider = $activeMappings->contains(
            fn (ProviderMapping $mapping): bool => (bool) ($providers->get($mapping->provider_id)?->is_active)
        );
        $storefrontStatus = match (true) {
            ! $product->is_active => 'Produk nonaktif',
            ! (bool) $product->category?->is_active => 'Kategori nonaktif',
            $activePackages->isEmpty() => 'Belum ada nominal aktif',
            $activeMappings->isEmpty() => 'Sumber nominal belum aktif',
            ! $hasEnabledProvider => 'Provider pengiriman nonaktif',
            $buyableCount === 0 => 'Nominal belum bisa dibeli: periksa modal, harga, atau stok',
            default => 'Tampil di toko',
        };

        return [
            ...$product->only('id', 'category_id', 'name', 'publisher', 'slug', 'description', 'fulfillment_mode',
                'initials', 'accent_color', 'instant', 'package_tabs_enabled', 'package_tabs',
                'customer_no_template', 'manual_instructions', 'manual_open_time', 'manual_close_time', 'manual_timezone', 'sort_order', 'is_active', 'popular',
                'cost_uplift_percent',
                'nickname_check_enabled', 'nickname_game_code', 'nickname_user_field_key',
                'nickname_server_field_key', 'nickname_required_region'),
            'image_url' => $product->getFirstMediaUrl('image'),
            'banner_url' => $product->getFirstMediaUrl('banner'),
            'buyable_nominal_count' => $buyableCount,
            'storefront_status' => $storefrontStatus,
            'fields' => $product->fields->sortBy('sort_order')->values()->toArray(),
            'notices' => $product->notices->sortBy('sort_order')->values()->map(fn (ProductNotice $notice): array => [
                ...$notice->only('id', 'title', 'body', 'sort_order', 'is_active'),
            ])->all(),
            'package_groups' => $product->packageGroups->map(fn (ProductPackageGroup $group): array => [
                ...$group->only('id', 'name', 'sort_order'),
                'image_url' => $group->getFirstMediaUrl('image'),
            ])->all(),
            'packages' => $product->packages->sortBy([
                ['sort_order', 'asc'], ['nominal_value', 'asc'],
            ])->values()->map(fn (ProductPackage $package): array => [
                ...$package->only('id', 'code', 'name', 'note', 'group_name', 'nominal_value', 'sort_order', 'is_active', 'pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr', 'sell_markup_percent', 'cost_uplift_percent', 'legacy_voucher_sell_price_idr',
                    'digiflazz_markup_mode', 'digiflazz_markup_percent', 'digiflazz_markup_fixed_idr'),
                'is_digiflazz_pricing' => $priceOwners[(int) $package->id] ?? false,
                'image_url' => $package->getFirstMediaUrl('image'),
                'mappings' => $package->mappings->map(fn (ProviderMapping $mapping): array => [
                    ...$mapping->only('id', 'provider_id', 'external_sku', 'cost_idr',
                        'max_price_idr', 'is_manual_price', 'priority', 'is_active', 'disabled_by_sync',
                        'store_cost_idr', 'seller_reference_price_idr'),
                    'provider_code' => $providers->get($mapping->provider_id)?->code,
                    'provider_name' => $providers->get($mapping->provider_id)?->display_name ?: $providers->get($mapping->provider_id)?->code,
                    'customer_no_template' => data_get($mapping->fulfillment_config, 'customer_no_template'),
                    'stock_key' => $providers->get($mapping->provider_id)?->code === 'VOUCHER_STOCK'
                        ? data_get($mapping->fulfillment_config, 'stock_key') : null,
                    'stock_counts' => $providers->get($mapping->provider_id)?->code === 'VOUCHER_STOCK'
                        ? ($stockCounts[(string) data_get($mapping->fulfillment_config, 'stock_key', '')]
                            ?? ['available' => 0, 'reserved' => 0, 'delivered' => 0, 'void' => 0, 'total' => 0])
                        : null,
                ])->all(),
            ])->all(),
        ];
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['auto', 'manual'])],
            'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(['order', 'name'])],
            'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $products = Product::with(['category', 'media', 'packages.mappings', 'fields', 'notices', 'packageGroups.media'])
            ->when(($filters['tab'] ?? 'auto') === 'manual', fn ($query) => $query->where('fulfillment_mode', 'MANUAL'))
            ->when(($filters['tab'] ?? 'auto') !== 'manual', fn ($query) => $query->where('fulfillment_mode', '!=', 'MANUAL'))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['q']).'%';
                $query->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('publisher', 'like', $term));
            })
            ->when($filters['category'] ?? null, fn ($query, $id) => $query->where('category_id', (int) $id))
            ->when(($filters['status'] ?? '') === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? '') === 'inactive', fn ($query) => $query->where('is_active', false));
        $perPage = (int) ($filters['per_page'] ?? 25);
        $currentPage = (int) ($filters['page'] ?? 1);
        if (($filters['sort'] ?? 'name') === 'name') {
            // Sort natural BEFORE paging: Product 5 must precede Product 20 and Product 1100.
            // Only retrieve ID + name for the full set; load heavyweight relations for one page.
            $orderedIds = (clone $products)->withoutEagerLoads()->reorder()->get(['id', 'name'])
                ->sort(fn (Product $a, Product $b): int => strnatcasecmp($a->name, $b->name)
                    ?: ($a->id <=> $b->id))->pluck('id')->values();
            $pageIds = $orderedIds->forPage($currentPage, $perPage)->all();
            $positions = array_flip($pageIds);
            $pageProducts = Product::with(['category', 'media', 'packages.mappings', 'fields', 'notices', 'packageGroups.media'])
                ->whereIn('id', $pageIds)->get()
                ->sortBy(fn (Product $row): int => $positions[$row->id])->values();
            $products = new LengthAwarePaginator($pageProducts, $orderedIds->count(), $perPage, $currentPage, [
                'path' => $request->url(), 'query' => $request->query(),
            ]);
        } else {
            $products = $products->orderBy('sort_order')->orderBy('name')->orderBy('id')
                ->paginate($perPage, ['*'], 'page', $currentPage)->withQueryString();
        }

        // Status toko memakai rule checkout yang sama, hanya untuk nominal pada halaman ini.
        // Pratinjau admin di bawah tetap boleh membaca nominal nonaktif.
        $pagePackageIds = $products->getCollection()
            ->flatMap(fn (Product $product) => $product->packages->where('is_active', true)->pluck('id'))
            ->map(fn ($id): int => (int) $id)->all();
        $buyablePackageIds = array_fill_keys(
            array_keys(app(CheckoutPricing::class)->forPackages($pagePackageIds)),
            true
        );

        $providers = Provider::all(['id', 'code', 'display_name', 'is_active'])->keyBy('id');
        $digiflazzProviderId = $providers->firstWhere('code', 'DIGIFLAZZ')?->id;
        $stockCounts = app(VoucherStockService::class)->counts();
        $previewQuotes = app(CheckoutPricing::class)
            ->forPackages(ProductPackage::query()->pluck('id')->map(fn ($id): int => (int) $id)->all(), true);
        $checkoutPreviews = collect($previewQuotes)
            ->map(fn (array $quote): int => (int) $quote['subtotal_idr'])->all();
        $priceOwnership = app(DigiflazzPriceOwnership::class);
        $priceOwners = [];
        foreach ($products->getCollection() as $rowProduct) {
            foreach ($rowProduct->packages as $rowPackage) {
                $priceOwners[(int) $rowPackage->id] = $priceOwnership->usesDigiflazz(
                    $rowPackage, $previewQuotes[(int) $rowPackage->id] ?? null
                );
            }
        }

        return Inertia::render('Admin/Catalog', [
            'checkoutPreviews' => $checkoutPreviews,
            'autoProductOptions' => Product::where('fulfillment_mode', 'AUTO_PROVIDER')
                ->with(['packages' => fn ($query) => $query->select(
                    'id', 'product_id', 'nominal_value', 'group_name', 'pricing_mode', 'margin_percent', 'margin_fixed_idr'
                ), 'packages.mappings' => fn ($query) => $query->select('id', 'product_package_id', 'provider_id'),
                    'packageGroups',
                    'fields' => fn ($query) => $query->orderBy('sort_order')->select('id', 'product_id', 'field_key', 'is_required')])
                ->get(['id', 'name', 'slug', 'customer_no_template'])
                ->sort(fn (Product $a, Product $b): int => strnatcasecmp($a->name, $b->name) ?: ($a->id <=> $b->id))
                ->values()->map(fn (Product $product): array => [
                    ...$product->only('id', 'name', 'slug', 'customer_no_template'),
                    'fields' => $product->fields->map(fn (ProductInputField $field): array => $field->only('field_key', 'is_required'))->all(),
                    'package_groups' => $product->packageGroups->map(fn (ProductPackageGroup $group): array => $group->only('id', 'name', 'sort_order'))->all(),
                    'nominals' => $product->packages
                        ->filter(fn (ProductPackage $package): bool => $package->nominal_value !== null
                            && ! $package->mappings->contains('provider_id', $digiflazzProviderId))
                        ->values()->map(fn (ProductPackage $package): array => $package->only(
                            'nominal_value', 'group_name', 'pricing_mode', 'margin_percent', 'margin_fixed_idr'
                        ))->all(),
                ]),
            'categories' => Category::with('media')->orderBy('sort_order')->get()->map(fn (Category $category): array => [
                ...$category->only('id', 'name', 'slug', 'icon', 'sort_order', 'is_active'),
                'image_url' => $category->getFirstMediaUrl('image'),
            ]),
            'nicknameGameCodes' => NicknameGameCode::active()
                ->nicknameSupported()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'requires_server', 'requires_region_check']),
            'productFilters' => [
                'tab' => $filters['tab'] ?? 'auto', 'q' => $filters['q'] ?? '', 'category' => $filters['category'] ?? '',
                'status' => $filters['status'] ?? '', 'sort' => $filters['sort'] ?? 'name', 'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'products' => $products->getCollection()->map(fn (Product $product): array => $this->productRow($product, $providers, $stockCounts, $buyablePackageIds, $priceOwners))->all(),
            'productPage' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'pricingSettings' => [
                'cost_uplift_percent' => app(CatalogPricingSettings::class)->costUpliftPercent(),
                'sell_markup_percent' => app(CatalogPricingSettings::class)->sellMarkupPercent(),
            ],
            // Status sinkron otomatis, supaya tab Sumber tidak menampilkan tombol
            // sinkron yang tidak pernah jalan.
            ...$this->autoSyncSettings(),
            'digiflazzItems' => (function () {
                // N+1 fix: preload mapped SKUs sekali, bukan per item
                $digiflazzIds = Provider::where('code', 'DIGIFLAZZ')->pluck('id');
                $mappedSkus = ProviderMapping::whereIn('provider_id', $digiflazzIds)->pluck('external_sku')->flip()->all();

                return DB::table('digiflazz_catalog_items')->orderBy('category')->orderBy('brand')->orderBy('product_name')->get()
                    ->map(fn (object $item): array => [...((array) $item), 'available' => app(DigiflazzCatalogService::class)->available($item),
                        'mapped' => isset($mappedSkus[$item->buyer_sku_code]),
                        'nominal_value' => app(CatalogNominalOrder::class)->value($item->product_name, $item->brand)]);
            })(),
        ]);
    }

    /**
     * Saklar sinkron otomatis dipakai di tab Sumber (izin katalog), jadi tidak
     * boleh menuntut izin integrasi provider seperti halaman Digiflazz.
     */
    public function autoSync(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $before = $this->autoSyncSettings();
        DB::table('system_settings')->updateOrInsert(['key' => 'digiflazz.auto_sync'], [
            'value' => json_encode((bool) $data['enabled'], JSON_THROW_ON_ERROR),
            'updated_by_admin_id' => $request->user('admin')->id,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
        $audit->record($request, 'digiflazz.auto_sync.updated', 'system_setting', 'digiflazz.auto_sync',
            $before, ['enabled' => (bool) $data['enabled']]);

        return back()->with('status', $data['enabled'] ? 'Sinkron otomatis dinyalakan.' : 'Sinkron otomatis dimatikan.');
    }

    /** @return array{autoSync: bool, syncIntervalMinutes: int} */
    private function autoSyncSettings(): array
    {
        $stored = DB::table('system_settings')
            ->whereIn('key', ['digiflazz.auto_sync', 'digiflazz.auto_sync_interval_minutes'])
            ->pluck('value', 'key');

        return [
            'autoSync' => (bool) (json_decode((string) $stored->get('digiflazz.auto_sync'), true) ?? true),
            'syncIntervalMinutes' => max(15, (int) (json_decode((string) $stored->get('digiflazz.auto_sync_interval_minutes'), true) ?? 15)),
        ];
    }
}
