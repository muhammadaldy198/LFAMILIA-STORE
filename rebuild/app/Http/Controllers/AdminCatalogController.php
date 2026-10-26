<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductNotice;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Models\StoreAsset;
use App\Services\AdminAuditService;
use App\Services\CatalogAudit;
use App\Services\CatalogNominalOrder;
use App\Services\CatalogPricingSettings;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzCatalogService;
use App\Services\VoucherStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminCatalogController
{
    // Dipakai halaman Produk: data per halaman dari server, bukan disaring di browser.
    private function productRow(Product $product, $providers, array $stockCounts, array $buyablePackageIds): array
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
                'customer_no_template', 'manual_instructions', 'manual_open_time', 'manual_close_time', 'manual_timezone', 'margin_percent', 'sort_order', 'is_active', 'popular',
                'cost_uplift_percent',
                'nickname_check_enabled', 'nickname_game_code', 'nickname_user_field_key',
                'nickname_server_field_key'),
            'image_url' => $product->getFirstMediaUrl('image'),
            'banner_url' => $product->getFirstMediaUrl('banner'),
            'buyable_nominal_count' => $buyableCount,
            'storefront_status' => $storefrontStatus,
            'fields' => $product->fields->sortBy('sort_order')->values()->toArray(),
            'notices' => $product->notices->sortBy('sort_order')->values()->map(fn (ProductNotice $notice): array => [
                ...$notice->only('id', 'title', 'body', 'sort_order', 'is_active'),
            ])->all(),
            'packages' => $product->packages->sortBy([
                ['sort_order', 'asc'], ['nominal_value', 'asc'],
            ])->values()->map(fn (ProductPackage $package): array => [
                ...$package->only('id', 'code', 'name', 'note', 'group_name', 'nominal_value', 'sort_order', 'is_active', 'pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr', 'sell_markup_percent', 'cost_uplift_percent'),
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
        $products = Product::with(['category', 'media', 'packages.mappings', 'fields', 'notices'])
            ->when(($filters['tab'] ?? 'auto') === 'manual', fn ($query) => $query->where('fulfillment_mode', 'MANUAL'))
            ->when(($filters['tab'] ?? 'auto') !== 'manual', fn ($query) => $query->where('fulfillment_mode', '!=', 'MANUAL'))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['q']).'%';
                $query->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('publisher', 'like', $term));
            })
            ->when($filters['category'] ?? null, fn ($query, $id) => $query->where('category_id', (int) $id))
            ->when(($filters['status'] ?? '') === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? '') === 'inactive', fn ($query) => $query->where('is_active', false));
        ($filters['sort'] ?? 'order') === 'name'
            ? $products->orderBy('name')
            : $products->orderBy('sort_order')->orderBy('name');
        $products = $products->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1))->withQueryString();

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
        $stockCounts = app(VoucherStockService::class)->counts();
        $checkoutPreviews = collect(app(CheckoutPricing::class)
            ->forPackages(ProductPackage::query()->pluck('id')->map(fn ($id): int => (int) $id)->all(), true))
            ->map(fn (array $quote): int => (int) $quote['subtotal_idr'])
            ->all();

        return Inertia::render('Admin/Catalog', [
            'checkoutPreviews' => $checkoutPreviews,
            'autoProductOptions' => Product::where('fulfillment_mode', 'AUTO_PROVIDER')
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Product $product): array => ['id' => (int) $product->id, 'name' => $product->name]),
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
                'status' => $filters['status'] ?? '', 'sort' => $filters['sort'] ?? 'order', 'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'products' => $products->getCollection()->map(fn (Product $product): array => $this->productRow($product, $providers, $stockCounts, $buyablePackageIds))->all(),
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

    public function categories(): Response
    {
        return Inertia::render('Admin/Categories', [
            'categories' => Category::with('media')->withCount('products')->orderBy('sort_order')->get()
                ->map(fn (Category $category): array => [
                    ...$category->only('id', 'name', 'slug', 'icon', 'sort_order', 'is_active'),
                    'products_count' => (int) $category->products_count,
                    'image_url' => $category->getFirstMediaUrl('image'),
                ]),
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

    public function category(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'icon' => ['nullable', Rule::in(['gamepad', 'ticket', 'play', 'smartphone', 'zap', 'grid'])],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $data['icon'] = $data['icon'] ?? 'grid';
        if (! $data['slug']) {
            throw ValidationException::withMessages(['name' => 'Nama kategori tidak valid.']);
        }
        if (Category::where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => 'Alamat kategori sudah dipakai.']);
        }

        DB::transaction(function () use ($request, $data, $audit): void {
            $category = Category::create([...$data, 'is_active' => (bool) ($data['is_active'] ?? false)]);
            $audit->record($request, 'catalog.category.created', 'category', $category->id, null, $category->toArray());
        });

        return back();
    }

    public function updateCategory(Request $request, Category $category, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($category->id)],
            'icon' => ['sometimes', 'required', Rule::in(['gamepad', 'ticket', 'play', 'smartphone', 'zap', 'grid'])],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['slug'] ??= $category->slug;
        $data['icon'] ??= $category->icon;

        DB::transaction(function () use ($request, $category, $data, $audit): void {
            $before = $category->toArray();
            $category->update($data);
            $audit->record($request, 'catalog.category.updated', 'category', $category->id, $before, $category->toArray());
        });

        return back();
    }

    public function destroyCategory(Request $request, Category $category, CatalogAudit $audit): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->withErrors([
                'category' => 'Kategori masih memiliki produk. Pindahkan atau nonaktifkan produknya terlebih dahulu.',
            ]);
        }

        $before = $category->toArray();
        $id = $category->id;
        $category->clearMediaCollection('image');
        $category->delete();
        $audit->record($request, 'catalog.category.deleted', 'category', $id, $before, []);

        return back();
    }

    public function product(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fulfillment_mode' => ['required', Rule::in(['AUTO_PROVIDER', 'MANUAL'])],
            'customer_no_template' => ['nullable', 'string', 'max:500'],
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'manual_open_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_close_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_timezone' => ['nullable', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'sort_order' => ['required', 'integer', 'min:0'],
            // Satu formulir boleh membawa banyak baris nominal untuk produk manual.
            'packages' => ['sometimes', 'array', 'max:100'],
            'packages.*.name' => ['required', 'string', 'max:255'],
            'packages.*.code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'packages.*.note' => ['nullable', 'string', 'max:80'],
            'packages.*.group_name' => ['nullable', 'string', 'max:120'],
            'packages.*.nominal_value' => ['nullable', 'integer', 'min:0'],
            'packages.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'packages.*.cost_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);
        $rows = collect($data['packages'] ?? [])->values();
        unset($data['packages']);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        if (! $data['slug']) {
            throw ValidationException::withMessages(['name' => 'Nama produk tidak valid.']);
        }
        if (Product::where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => 'Alamat produk sudah dipakai.']);
        }
        if ($data['fulfillment_mode'] !== 'MANUAL') {
            $data['manual_instructions'] = null;
            $data['manual_open_time'] = null;
            $data['manual_close_time'] = null;
            $data['manual_timezone'] = 'Asia/Jakarta';
        } else {
            $data['manual_timezone'] = ($data['manual_timezone'] ?? null) ?: 'Asia/Jakarta';
        }

        if ($rows->isNotEmpty() && $data['fulfillment_mode'] !== 'MANUAL') {
            throw ValidationException::withMessages([
                'packages' => 'Baris nominal satu formulir hanya untuk produk manual. Produk otomatis mengambil nominal dari impor Digiflazz.',
            ]);
        }

        $duplicateNominals = $rows->whereNotNull('nominal_value')
            ->groupBy(fn (array $row): string => (string) $row['nominal_value'])
            ->filter(fn ($group): bool => $group->count() > 1)
            ->keys();
        if ($duplicateNominals->isNotEmpty()) {
            throw ValidationException::withMessages([
                'packages' => 'Nominal '.$duplicateNominals->implode(', ').' muncul lebih dari sekali di formulir ini. Gabungkan atau ubah nominalnya.',
            ]);
        }

        DB::transaction(function () use ($request, $data, $rows, $audit): void {
            $product = Product::create([...$data, 'is_active' => false]);
            $audit->record($request, 'catalog.product.created', 'product', $product->id, null, $product->toArray());

            if ($rows->isEmpty()) {
                return;
            }

            // Produk manual: modal toko tiap nominal disimpan pada mapping provider
            // MANUAL tanpa SKU provider asli, jadi Digiflazz tidak tersentuh.
            $manualProvider = Provider::where('code', 'MANUAL')->firstOrFail();
            $usedCodes = [];
            foreach ($rows as $index => $row) {
                $code = trim((string) ($row['code'] ?? '')) ?: Str::upper(Str::slug($row['name'], '_'));
                $code = Str::limit($code !== '' ? $code : 'NOMINAL', 80, '');
                $base = $code;
                $suffix = 2;
                while (in_array($code, $usedCodes, true)) {
                    $code = Str::limit($base, 76, '').'_'.$suffix;
                    $suffix++;
                }
                $usedCodes[] = $code;

                $package = $product->packages()->create([
                    'code' => $code,
                    'name' => $row['name'],
                    'note' => $row['note'] ?? null,
                    'group_name' => $row['group_name'] ?? null,
                    'nominal_value' => $row['nominal_value'] ?? null,
                    'sort_order' => $row['sort_order'] ?? $index,
                    // Nominal manual memakai margin persen produk; harga jual
                    // dihitung dari modal toko, bukan kolom markup legacy.
                    'pricing_mode' => 'PERCENT', 'margin_percent' => $data['margin_percent'],
                    'is_active' => false,
                ]);
                // Modal toko tidak di-uplift: uplift hanya berlaku saat impor provider.
                $mapping = ProviderMapping::create([
                    'product_package_id' => $package->id,
                    'provider_id' => $manualProvider->id,
                    'external_sku' => null,
                    'cost_idr' => $row['cost_idr'],
                    'store_cost_idr' => $row['cost_idr'],
                    'max_price_idr' => null,
                    'priority' => 0,
                    'is_active' => false,
                ]);
                $audit->record($request, 'catalog.mapping.created', 'provider_mapping', $mapping->id, null, $mapping->toArray());
                $audit->record($request, 'catalog.package.created', 'product_package', $package->id, null, $package->toArray());
            }
        });

        return back();
    }

    public function updateProduct(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($product->id)],
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fulfillment_mode' => ['sometimes', Rule::in(['AUTO_PROVIDER', 'MANUAL'])],
            'customer_no_template' => ['sometimes', 'nullable', 'string', 'max:500'],
            'initials' => ['nullable', 'string', 'max:4'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'instant' => ['sometimes', 'boolean'],
            'package_tabs_enabled' => ['sometimes', 'boolean'],
            'package_tabs' => ['sometimes', 'array', 'max:20'],
            'package_tabs.*' => ['required', 'string', 'min:1', 'max:60'],
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'manual_open_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_close_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_timezone' => ['nullable', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'cost_uplift_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'nickname_check_enabled' => ['sometimes', 'boolean'],
            'nickname_game_code' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'nickname_user_field_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'nickname_server_field_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
        ]);
        $templateSubmitted = array_key_exists('customer_no_template', $data);
        $template = trim((string) ($templateSubmitted
            ? $data['customer_no_template']
            : ($product->customer_no_template ?? '')));
        $fieldKeys = $product->fields()->pluck('field_key')->all();
        preg_match_all('/\{\{([^}]+)\}\}/', $template, $templateMatches);
        foreach ($templateMatches[1] ?? [] as $fieldKey) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $fieldKey) || ! in_array($fieldKey, $fieldKeys, true)) {
                throw ValidationException::withMessages(['customer_no_template' => 'Placeholder {{'.$fieldKey.'}} tidak cocok dengan field produk.']);
            }
        }
        $requiredFieldKeys = $product->fields()->where('is_required', true)->pluck('field_key')->all();
        $referencedKeys = $templateMatches[1] ?? [];
        if (preg_match('/\{(?!\{)|(?<!\})\}/', preg_replace('/\{\{[a-z][a-z0-9_]*\}\}/', '', $template) ?? $template)) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan mengandung kurung kurawal yang tidak berpasangan.']);
        }
        $hasDigiflazzMapping = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
            ->where('providers.code', 'DIGIFLAZZ')
            ->where('product_packages.product_id', $product->id)
            ->exists();
        if ($templateSubmitted && $data['is_active']
            && ($data['fulfillment_mode'] ?? $product->fulfillment_mode) === 'AUTO_PROVIDER'
            && $hasDigiflazzMapping
            && (($template !== '' && $referencedKeys === [])
                || ($product->fields()->count() > 1 && $template === '')
                || ($template !== '' && array_diff($requiredFieldKeys, $referencedKeys)))) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan harus mencantumkan semua kolom wajib produk dengan placeholder {{field_key}}.']);
        }
        if ($data['is_active'] && ! $product->is_active
            && ($data['fulfillment_mode'] ?? $product->fulfillment_mode) === 'AUTO_PROVIDER'
            && $hasDigiflazzMapping) {
            $mappingConfigs = DB::table('provider_mappings as mappings')
                ->join('providers', 'providers.id', '=', 'mappings.provider_id')
                ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
                ->where('product_packages.product_id', $product->id)
                ->where('providers.code', 'DIGIFLAZZ')
                ->where('mappings.is_active', true)
                ->pluck('mappings.fulfillment_config');
            foreach ($mappingConfigs as $config) {
                $config = is_string($config) ? (json_decode($config, true) ?: []) : (array) $config;
                $effectiveTemplate = $templateSubmitted
                    ? $template
                    : ($template !== '' ? $template : trim((string) data_get($config, 'customer_no_template')));
                preg_match_all('/\{\{([^}]+)\}\}/', $effectiveTemplate, $effectiveMatches);
                $effectiveReferences = $effectiveMatches[1] ?? [];
                if (($effectiveTemplate !== '' && array_diff($effectiveReferences, $fieldKeys))
                    || ($effectiveTemplate !== '' && $effectiveReferences === [])
                    || (count($fieldKeys) > 1 && $effectiveTemplate === '')
                    || ($effectiveTemplate !== '' && array_diff($requiredFieldKeys, $effectiveReferences))) {
                    throw ValidationException::withMessages(['customer_no_template' => 'Produk otomatis belum memiliki format tujuan yang valid untuk semua kolom wajib.']);
                }
            }
        }
        if ($templateSubmitted) {
            $data['customer_no_template'] = $template !== '' ? $template : null;
        }
        $data['slug'] ??= $product->slug;
        $data['fulfillment_mode'] ??= $product->fulfillment_mode;
        $data['package_tabs_enabled'] ??= $product->package_tabs_enabled;
        $data['package_tabs'] ??= $product->package_tabs ?? [];

        $tabs = collect($data['package_tabs'])->map(fn ($tab) => trim((string) $tab))
            ->filter()->values();
        if ($tabs->map(fn (string $tab): string => mb_strtolower($tab))->unique()->count() !== $tabs->count()) {
            throw ValidationException::withMessages(['package_tabs' => 'Nama tab nominal tidak boleh duplikat.']);
        }
        $data['package_tabs'] = $tabs->all();

        if ($data['package_tabs_enabled']) {
            if ($tabs->isEmpty()) {
                throw ValidationException::withMessages(['package_tabs' => 'Tambahkan minimal satu nama tab nominal.']);
            }
            $groups = $product->packages()->pluck('group_name')->map(fn ($group) => trim((string) $group));
            if ($groups->contains('')) {
                throw ValidationException::withMessages(['package_tabs' => 'Semua nominal harus memiliki grup sebelum tab nominal diaktifkan.']);
            }
            $missing = $groups->filter(fn (string $group): bool => ! $tabs->contains($group))->unique()->values();
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'package_tabs' => 'Grup nominal belum tercantum sebagai tab: '.$missing->implode(', ').'.',
                ]);
            }
        }

        if ($data['is_active'] && ! $product->packages()->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'Tambahkan minimal satu nominal sebelum produk diaktifkan.',
            ]);
        }

        if ($data['fulfillment_mode'] !== $product->fulfillment_mode
            && ($product->packages()->exists() || DB::table('orders')->where('product_id', $product->id)->exists())) {
            throw ValidationException::withMessages([
                'fulfillment_mode' => 'Jenis penanganan hanya dapat diubah sebelum produk memiliki nominal atau riwayat pesanan.',
            ]);
        }

        $categorySlug = (string) Category::whereKey($data['category_id'])->value('slug');
        if ($categorySlug === 'voucher' && ($data['nickname_check_enabled'] ?? false) === true) {
            throw ValidationException::withMessages([
                'nickname_check_enabled' => 'Produk voucher tidak memakai Kode Game Nickname.',
            ]);
        }

        if (($data['nickname_check_enabled'] ?? false) === true) {
            $fieldKeys = $product->fields()->pluck('field_key')->all();
            if (empty($data['nickname_game_code']) || empty($data['nickname_user_field_key'])) {
                throw ValidationException::withMessages([
                    'nickname_game_code' => 'Kode game dan kolom User ID wajib dipilih ketika cek nickname aktif.',
                ]);
            }

            $nicknameGame = NicknameGameCode::active()
                ->nicknameSupported()
                ->where('code', $data['nickname_game_code'])
                ->first();
            if (! $nicknameGame) {
                throw ValidationException::withMessages([
                    'nickname_game_code' => 'Pilih game aktif yang mendukung cek nickname di menu Validasi Akun.',
                ]);
            }

            if (! in_array($data['nickname_user_field_key'], $fieldKeys, true)) {
                throw ValidationException::withMessages([
                    'nickname_user_field_key' => 'Kolom User ID harus memakai kolom data pelanggan yang tersedia.',
                ]);
            }
            if ($nicknameGame->requires_server && empty($data['nickname_server_field_key'])) {
                throw ValidationException::withMessages([
                    'nickname_server_field_key' => 'Game ini memerlukan kolom Server / Zone.',
                ]);
            }
            if (! empty($data['nickname_server_field_key'])
                && ! in_array($data['nickname_server_field_key'], $fieldKeys, true)) {
                throw ValidationException::withMessages([
                    'nickname_server_field_key' => 'Kolom Server / Zone harus memakai kolom data pelanggan yang tersedia.',
                ]);
            }
        }
        if ($data['fulfillment_mode'] !== 'MANUAL') {
            $data['manual_instructions'] = null;
            $data['manual_open_time'] = null;
            $data['manual_close_time'] = null;
            $data['manual_timezone'] = 'Asia/Jakarta';
        } else {
            $data['manual_timezone'] = ($data['manual_timezone'] ?? null) ?: 'Asia/Jakarta';
        }

        DB::transaction(function () use ($request, $product, $data, $audit): void {
            $before = $product->toArray();
            $product->update($data);
            if (array_key_exists('customer_no_template', $data) && $data['customer_no_template'] === null) {
                $this->clearLegacyCustomerNoTemplates($product->id);
            }
            $audit->record($request, 'catalog.product.updated', 'product', $product->id, $before, $product->toArray());
        });

        return back();
    }

    private function clearLegacyCustomerNoTemplates(int $productId): void
    {
        $mappings = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
            ->where('providers.code', 'DIGIFLAZZ')
            ->where('product_packages.product_id', $productId)
            ->get(['mappings.id', 'mappings.fulfillment_config']);

        foreach ($mappings as $mapping) {
            $config = is_string($mapping->fulfillment_config)
                ? (json_decode($mapping->fulfillment_config, true) ?: [])
                : (array) $mapping->fulfillment_config;
            if (! array_key_exists('customer_no_template', $config)) {
                continue;
            }

            unset($config['customer_no_template']);
            DB::table('provider_mappings')->where('id', $mapping->id)->update([
                'fulfillment_config' => json_encode($config, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function destroyProduct(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        if (DB::table('orders')->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages([
                'product' => 'Produk memiliki riwayat pesanan dan tidak boleh dihapus. Nonaktifkan produk jika tidak ingin ditampilkan.',
            ]);
        }

        DB::transaction(function () use ($request, $product, $audit): void {
            $before = $product->toArray();
            $packages = $product->packages()->with('mappings')->get();
            foreach ($packages as $package) {
                $package->clearMediaCollection('image');
                $package->mappings()->delete();
                $package->delete();
            }
            $product->clearMediaCollection('image');
            $product->clearMediaCollection('banner');
            $id = $product->id;
            $product->delete();
            $audit->record($request, 'catalog.product.deleted', 'product', $id, $before, []);
        });

        return redirect()->route('admin.catalog.index')->with('status', 'Produk berhasil dihapus.');
    }

    public function destroyPackage(Request $request, ProductPackage $package, CatalogAudit $audit): RedirectResponse
    {
        if (DB::table('orders')->where('product_package_id', $package->id)->exists()) {
            throw ValidationException::withMessages([
                'package' => 'Nominal memiliki riwayat pesanan dan tidak boleh dihapus. Nonaktifkan nominal jika tidak ingin dijual.',
            ]);
        }

        DB::transaction(function () use ($request, $package, $audit): void {
            $before = $package->toArray();
            $id = $package->id;
            $package->clearMediaCollection('image');
            $package->mappings()->delete();
            $package->delete();
            $audit->record($request, 'catalog.package.deleted', 'product_package', $id, $before, []);
        });

        return back()->with('status', 'Nominal berhasil dihapus.');
    }

    public function duplicatePackage(Request $request, ProductPackage $package, CatalogAudit $audit): RedirectResponse
    {
        $product = $package->product()->firstOrFail();
        if ($product->fulfillment_mode !== 'MANUAL') {
            throw ValidationException::withMessages([
                'package' => 'Nominal otomatis tidak disalin agar SKU penyedia tidak terduplikasi. Gunakan Impor nominal Digiflazz untuk menambah nominal otomatis.',
            ]);
        }

        $manualProviderId = Provider::where('code', 'MANUAL')->value('id');
        $sourceMapping = $manualProviderId
            ? $package->mappings()->where('provider_id', $manualProviderId)->first()
            : null;
        if (! $sourceMapping) {
            throw ValidationException::withMessages(['package' => 'Modal nominal manual belum tersedia.']);
        }

        DB::transaction(function () use ($request, $product, $package, $sourceMapping, $audit): void {
            $baseCode = substr($package->code.'_COPY', 0, 70);
            $copyNumber = 1;
            do {
                $code = $baseCode.'_'.$copyNumber++;
            } while (ProductPackage::where('product_id', $product->id)->where('code', $code)->exists());

            $copy = $product->packages()->create([
                'code' => $code,
                'name' => $package->name.' (Salinan)',
                'note' => $package->note,
                'group_name' => $package->group_name,
                'nominal_value' => $package->nominal_value,
                'sort_order' => ((int) $product->packages()->max('sort_order')) + 1,
                'is_active' => false,
                'pricing_mode' => $package->pricing_mode,
                'margin_percent' => $package->margin_percent,
                'margin_fixed_idr' => $package->margin_fixed_idr,
                'sell_price_idr' => $package->sell_price_idr,
                'sell_markup_percent' => $package->sell_markup_percent,
                'cost_uplift_percent' => $package->cost_uplift_percent,
            ]);
            $mapping = $copy->mappings()->create([
                'provider_id' => $sourceMapping->provider_id,
                'external_sku' => null,
                'cost_idr' => $sourceMapping->cost_idr,
                'store_cost_idr' => $sourceMapping->store_cost_idr,
                'max_price_idr' => null,
                'priority' => $sourceMapping->priority,
                'is_active' => false,
            ]);
            if ($media = $package->getFirstMedia('image')) {
                $media->copy($copy, 'image');
            }
            $audit->record($request, 'catalog.package.duplicated', 'product_package', $copy->id,
                ['source_id' => $package->id], ['package' => $copy->toArray(), 'mapping' => $mapping->toArray()]);
        });

        return back()->with('status', 'Salinan nominal dibuat dalam keadaan nonaktif.');
    }

    public function package(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('product_packages', 'code')->where('product_id', $product->id)],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:80'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'nominal_value' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'cost_idr' => [$product->fulfillment_mode === 'MANUAL' ? 'required' : 'nullable',
                'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
            'pricing_mode' => ['sometimes', Rule::in(['PRODUCT_MARGIN', 'PERCENT', 'FIXED', 'SELL_PRICE'])],
            'margin_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4', 'required_if:pricing_mode,PERCENT'],
            'margin_fixed_idr' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000', 'required_if:pricing_mode,FIXED'],
            'sell_price_idr' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000', 'required_if:pricing_mode,SELL_PRICE'],
            'sell_markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
        ]);

        // Nominal otomatis hanya bisa dijual setelah punya sumber Digiflazz aktif.
        $wantsActive = (bool) ($data['is_active'] ?? false);
        $canActivate = $product->fulfillment_mode === 'MANUAL';
        $isActive = $wantsActive && $canActivate;
        $pricing = collect($data)->only([
            'pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr', 'sell_markup_percent',
        ])->filter(fn ($value): bool => $value !== null)->all();
        if (($pricing['pricing_mode'] ?? null) === 'SELL_PRICE'
            && (int) $pricing['sell_price_idr'] < (int) ($data['cost_idr'] ?? 0)) {
            throw ValidationException::withMessages(['sell_price_idr' => 'Harga jual tidak boleh di bawah modal.']);
        }
        if ($isActive && $product->fulfillment_mode === 'MANUAL' && (int) ($data['cost_idr'] ?? 0) < 1) {
            throw ValidationException::withMessages(['cost_idr' => 'Nominal aktif harus punya modal lebih dari Rp 0.']);
        }

        if ($product->package_tabs_enabled) {
            $group = trim((string) ($data['group_name'] ?? ''));
            $tabs = collect($product->package_tabs ?? []);
            if ($group === '' || ! $tabs->contains($group)) {
                throw ValidationException::withMessages([
                    'group_name' => 'Pilih grup yang tersedia pada tab nominal produk.',
                ]);
            }
        }

        DB::transaction(function () use ($request, $product, $data, $audit, $isActive, $pricing): void {
            $package = $product->packages()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'note' => $data['note'] ?? null,
                'group_name' => $data['group_name'] ?? null,
                'nominal_value' => $data['nominal_value'] ?? null,
                'sort_order' => $data['sort_order'],
                ...$pricing,
                'is_active' => $isActive,
            ]);
            if ($product->fulfillment_mode === 'MANUAL') {
                $provider = Provider::where('code', 'MANUAL')->firstOrFail();
                $mapping = ProviderMapping::create([
                    'product_package_id' => $package->id,
                    'provider_id' => $provider->id,
                    'external_sku' => null,
                    'cost_idr' => $data['cost_idr'],
                    'store_cost_idr' => $data['cost_idr'],
                    'max_price_idr' => null,
                    'priority' => 0,
                    'is_active' => $isActive,
                ]);
                $audit->record($request, 'catalog.mapping.created', 'provider_mapping',
                    $mapping->id, null, $mapping->toArray());
            }
            $audit->record($request, 'catalog.package.created', 'product_package', $package->id, null, $package->toArray());
        });

        return $wantsActive && ! $isActive
            ? back()->with('status', 'Nominal disimpan nonaktif. Aktifkan setelah sumber Digiflazz dipasang.')
            : back();
    }

    public function updatePackage(Request $request, ProductPackage $package, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('product_packages', 'code')->where('product_id', $package->product_id)->ignore($package->id)],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:80'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'nominal_value' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'pricing_mode' => ['sometimes', Rule::in(['PRODUCT_MARGIN', 'PERCENT', 'FIXED', 'SELL_PRICE'])],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4', 'required_if:pricing_mode,PERCENT'],
            'margin_fixed_idr' => ['nullable', 'integer', 'min:0', 'max:1000000000', 'required_if:pricing_mode,FIXED'],
            'sell_price_idr' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'required_if:pricing_mode,SELL_PRICE'],
            'sell_markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'cost_uplift_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'cost_idr' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'store_cost_idr' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $product = $package->product()->firstOrFail();
        // Nominal tersimpan tidak boleh hilang karena klien mengirim null tanpa
        // sengaja mengubahnya (form edit tidak punya input nominal_value).
        if (array_key_exists('nominal_value', $data)
            && $data['nominal_value'] === null && $package->nominal_value !== null) {
            unset($data['nominal_value']);
        }
        // Modal toko hidup di store_cost_idr. cost_idr hanya dibaca sebagai
        // fallback supaya form lama tetap jalan, tapi tidak pernah menimpanya.
        $newCost = array_key_exists('store_cost_idr', $data) && $data['store_cost_idr'] !== null
            ? (int) $data['store_cost_idr']
            : (array_key_exists('cost_idr', $data) && $data['cost_idr'] !== null ? (int) $data['cost_idr'] : null);
        if ($newCost !== null && $product->fulfillment_mode !== 'MANUAL') {
            throw ValidationException::withMessages([
                'cost_idr' => 'Modal hanya bisa diisi pada produk dengan penanganan manual.',
            ]);
        }
        $isActive = (bool) ($data['is_active'] ?? $package->is_active);
        // Nominal aktif tanpa modal > 0 tidak akan pernah lolos CheckoutPricing.
        $manualMapping = $product->fulfillment_mode === 'MANUAL'
            ? $package->mappings()->where('provider_id', Provider::where('code', 'MANUAL')->value('id'))->first()
            : null;
        $effectiveCost = $newCost ?? (int) ($manualMapping?->store_cost_idr ?? $manualMapping?->cost_idr ?? 0);
        if ($isActive && $product->fulfillment_mode === 'MANUAL' && $effectiveCost < 1) {
            // Modal nol tidak bisa dijual: nominalnya dimatikan, tapi modal lama
            // tidak ikut dihapus supaya bisa dinyalakan lagi tanpa isi ulang.
            $isActive = false;
            $newCost = null;
        }
        // Nominal otomatis hanya bisa dijual lewat sumber penyedia yang aktif.
        if ($isActive && $product->fulfillment_mode !== 'MANUAL'
            && ! $package->mappings()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'Aktifkan sumber Digiflazz nominal ini sebelum menjualnya.',
            ]);
        }
        if ($product->package_tabs_enabled) {
            $group = trim((string) ($data['group_name'] ?? $package->group_name ?? ''));
            $tabs = collect($product->package_tabs ?? []);
            if ($group === '' || ! $tabs->contains($group)) {
                throw ValidationException::withMessages([
                    'group_name' => 'Pilih grup yang tersedia pada tab nominal produk.',
                ]);
            }
        }

        $highestActiveCost = $package->mappings()->where('is_active', true)
            ->selectRaw('MAX(COALESCE(store_cost_idr, cost_idr)) as aggregate')->value('aggregate');
        if (($data['pricing_mode'] ?? $package->pricing_mode) === 'SELL_PRICE'
            && (int) ($data['sell_price_idr'] ?? $package->sell_price_idr) < (int) ($newCost ?? $highestActiveCost)) {
            throw ValidationException::withMessages(['sell_price_idr' => 'Harga jual tidak boleh di bawah modal.']);
        }
        $wasActive = (bool) $package->is_active;
        DB::transaction(function () use ($request, $package, $product, $isActive, $data, $audit, $newCost, $wasActive): void {
            $before = $package->toArray();
            // is_active memakai hasil hitungan server: nominal tanpa modal
            // dimatikan walau klien mengirim true.
            $package->update([
                ...collect($data)->except(['cost_idr', 'store_cost_idr', 'is_active'])->all(),
                'is_active' => $isActive,
            ]);
            // Admin menyalakan sendiri: penanda "dimatikan sinkron" dilepas supaya
            // sinkron berikutnya tidak menganggapnya miliknya.
            if ($isActive && $package->disabled_by_sync) {
                $package->update(['disabled_by_sync' => false]);
            }
            if ($product->fulfillment_mode === 'MANUAL') {
                // Modal nominal manual hidup di pemetaan provider, bukan di paket,
                // dan status aktif nominal harus ikut supaya CheckoutPricing melihatnya.
                $provider = Provider::where('code', 'MANUAL')->firstOrFail();
                $mapping = $package->mappings()->where('provider_id', $provider->id)->first()
                    ?? ProviderMapping::create([
                        'product_package_id' => $package->id,
                        'provider_id' => $provider->id,
                        'external_sku' => null,
                        'cost_idr' => $newCost ?? 0,
                        'store_cost_idr' => $newCost ?? 0,
                        'max_price_idr' => null,
                        'priority' => 0,
                        'is_active' => $isActive,
                    ]);
                $mappingBefore = $mapping->toArray();
                $mapping->update([
                    'cost_idr' => $newCost ?? $mapping->cost_idr,
                    'store_cost_idr' => $newCost ?? $mapping->store_cost_idr,
                    // Status sumber hanya ikut berubah kalau status nominalnya
                    // sendiri berubah; ganti nama nominal tidak boleh
                    // menghidupkan sumber yang sudah dimatikan admin.
                    ...($wasActive === $isActive ? [] : ['is_active' => $isActive]),
                ]);
                $audit->record($request, 'catalog.mapping.updated', 'provider_mapping', $mapping->id, $mappingBefore, $mapping->fresh()->toArray());
            }
            $audit->record($request, 'catalog.package.updated', 'product_package', $package->id, $before, $package->toArray());
        });

        return back();
    }

    public function storeNotice(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $this->noticeData($request);
        $notice = $product->notices()->create($data);
        $audit->record($request, 'catalog.notice.created', 'product_notice', $notice->id, null, $notice->toArray());

        return back();
    }

    public function updateNotice(Request $request, ProductNotice $notice, CatalogAudit $audit): RedirectResponse
    {
        $data = $this->noticeData($request);
        $before = $notice->toArray();
        $notice->update($data);
        $audit->record($request, 'catalog.notice.updated', 'product_notice', $notice->id, $before, $notice->toArray());

        return back();
    }

    public function destroyNotice(Request $request, ProductNotice $notice, CatalogAudit $audit): RedirectResponse
    {
        $before = $notice->toArray();
        $id = $notice->id;
        $notice->delete();
        $audit->record($request, 'catalog.notice.deleted', 'product_notice', $id, $before, null);

        return back();
    }

    public function fields(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'fields' => ['present', 'array', 'max:20'],
            'customer_no_template' => ['sometimes', 'nullable', 'string', 'max:500'],
            'fields.*.field_key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(['text', 'tel', 'email'])],
            'fields.*.is_required' => ['required', 'boolean'],
        ]);
        $keys = array_column($data['fields'], 'field_key');
        $template = trim((string) (array_key_exists('customer_no_template', $data)
            ? $data['customer_no_template']
            : ($product->customer_no_template ?? '')));
        preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
        $templateReferences = $matches[1] ?? [];
        $references = $templateReferences;
        if ($product->nickname_check_enabled) {
            $references = array_merge($references, array_filter([$product->nickname_user_field_key, $product->nickname_server_field_key]));
        }
        foreach ($matches[1] ?? [] as $fieldKey) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $fieldKey) || ! in_array($fieldKey, $keys, true)) {
                throw ValidationException::withMessages(['customer_no_template' => 'Placeholder {{'.$fieldKey.'}} tidak cocok dengan field produk.']);
            }
        }
        if (preg_match('/\{(?!\{)|(?<!\})\}/', preg_replace('/\{\{[a-z][a-z0-9_]*\}\}/', '', $template) ?? $template)) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan mengandung kurung kurawal yang tidak berpasangan.']);
        }
        if ($template === '') {
            $packageIds = $product->packages()->pluck('id');
            foreach (DB::table('provider_mappings as mappings')
                ->join('providers', 'providers.id', '=', 'mappings.provider_id')
                ->where('providers.code', 'DIGIFLAZZ')
                ->whereIn('mappings.product_package_id', $packageIds)
                ->pluck('mappings.fulfillment_config') as $config) {
                $config = is_string($config) ? (json_decode($config, true) ?: []) : (array) $config;
                preg_match_all('/\{\{([^}]+)\}\}/', (string) data_get($config, 'customer_no_template'), $mappingMatches);
                $references = array_merge($references, $mappingMatches[1] ?? []);
            }
        }
        if (array_diff($references, $keys)) {
            throw ValidationException::withMessages(['fields' => 'Kode kolom masih dipakai cek nickname atau template pengiriman. Ubah pengaturan tersebut dahulu.']);
        }
        $requiredKeys = collect($data['fields'])->filter(fn (array $field): bool => (bool) $field['is_required'])
            ->pluck('field_key')->all();
        $hasDigiflazzMapping = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
            ->where('providers.code', 'DIGIFLAZZ')
            ->where('product_packages.product_id', $product->id)
            ->exists();
        if ($product->is_active && $product->fulfillment_mode === 'AUTO_PROVIDER' && $hasDigiflazzMapping
            && (($template !== '' && $templateReferences === [])
                || (count($data['fields']) > 1 && $template === '')
                || ($requiredKeys !== [] && array_diff($requiredKeys, $templateReferences)))) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan harus memakai placeholder kolom produk dan mencantumkan semua kolom wajib.']);
        }
        DB::transaction(function () use ($request, $product, $data, $audit, $template): void {
            $before = $product->fields()->get()->toArray();
            $beforeTemplate = $product->customer_no_template;
            $product->customer_no_template = $template !== '' ? $template : null;
            $product->save();
            if (array_key_exists('customer_no_template', $data) && $data['customer_no_template'] === null) {
                $this->clearLegacyCustomerNoTemplates($product->id);
            }
            $product->refresh();
            $before = ['fields' => $before, 'customer_no_template' => $beforeTemplate];
            $product->fields()->delete();
            foreach ($data['fields'] as $index => $field) {
                ProductInputField::create([
                    ...$field, 'product_id' => $product->id, 'sort_order' => $index,
                ]);
            }
            $audit->record($request, 'catalog.fields.updated', 'product', $product->id,
                $before, [
                    'fields' => $product->fields()->get()->toArray(),
                    'customer_no_template' => $product->customer_no_template,
                ]);
        });

        return back();
    }

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
            'is_manual_price' => [$provider->code === 'DIGIFLAZZ' ? 'sometimes' : 'prohibited', 'boolean'],
            'manual_price_idr' => [$provider->code === 'DIGIFLAZZ' ? 'sometimes' : 'prohibited', 'integer', 'min:1', 'max:1000000000'],
            'store_cost_idr' => [$provider->code === 'MANUAL' || $provider->code === 'DIGIFLAZZ' ? 'sometimes' : 'prohibited', 'integer', $provider->code === 'MANUAL' ? 'min:0' : 'min:1', 'max:1000000000'],
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
            if (isset($data['store_cost_idr'])) {
                if ($mapping->seller_reference_price_idr !== null
                    && (int) $data['store_cost_idr'] < (int) $mapping->seller_reference_price_idr) {
                    throw ValidationException::withMessages([
                        'store_cost_idr' => 'Store cost tidak boleh di bawah harga referensi seller saat ini.',
                    ]);
                }
                $data['max_price_idr'] = (int) $data['store_cost_idr'];
            }

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
            $locking = (bool) ($data['is_manual_price'] ?? $mapping->is_manual_price);
            if ($locking && ! $mapping->is_manual_price && ! isset($data['manual_price_idr'])) {
                throw ValidationException::withMessages([
                    'manual_price_idr' => 'Isi harga manual saat mengaktifkan kunci harga.',
                ]);
            }
            if (isset($data['manual_price_idr']) && ! $locking) {
                throw ValidationException::withMessages([
                    'manual_price_idr' => 'Aktifkan kunci harga sebelum menentukan harga manual.',
                ]);
            }
            if ($locking && isset($data['manual_price_idr'])) {
                // One admin-supplied amount is both the fixed accounting cost
                // and per-source maximum. Never use an incoming seller price.
                $data['cost_idr'] = $data['manual_price_idr'];
                $data['store_cost_idr'] = $data['manual_price_idr'];
                $data['max_price_idr'] = $data['manual_price_idr'];
            } elseif ($mapping->is_manual_price && ! $locking) {
                // Unlocking returns this mapping to supplier-controlled pricing;
                // the next sync refreshes cost and ceiling from the live SKU.
                $data['store_cost_idr'] = null;
                $data['max_price_idr'] = null;
            }
            unset($data['manual_price_idr']);

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

    public function reorderPackages(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:1000'], 'ids.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($product, $data, $request, $audit): void {
            $before = $product->packages()->lockForUpdate()->orderBy('sort_order')->pluck('id')->all();
            $ids = array_map('intval', $data['ids']);
            $expected = $before;
            $actual = $ids;
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw ValidationException::withMessages(['ids' => 'Daftar nominal berubah. Muat ulang halaman.']);
            }
            foreach ($ids as $order => $id) {
                ProductPackage::where('id', $id)->update(['sort_order' => $order]);
            }
            $audit->record($request, 'catalog.packages.reordered', 'product', $product->id, $before, $ids);
        });

        return back();
    }

    /**
     * One-time, audited conversion of legacy dynamic Digiflazz prices.
     * Existing fixed/manual prices are never overwritten.
     */
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

    private function noticeData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

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
