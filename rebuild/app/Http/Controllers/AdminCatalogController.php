<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductNotice;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Models\StoreAsset;
use App\Services\AdminAuditService;
use App\Services\CatalogAudit;
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
    public function index(): Response
    {
        $providers = Provider::all(['id', 'code', 'is_active'])->keyBy('id');
        $stockCounts = app(VoucherStockService::class)->counts();

        return Inertia::render('Admin/Catalog', [
            'categories' => Category::orderBy('sort_order')->get()->map(fn (Category $category): array => [
                ...$category->only('id', 'name', 'slug', 'icon', 'sort_order', 'is_active'),
                'image_url' => $category->getFirstMediaUrl('image'),
            ]),
            'products' => Product::with(['packages.mappings', 'fields', 'notices'])->orderBy('sort_order')->get()
                ->map(fn (Product $product): array => [
                    ...$product->only('id', 'category_id', 'name', 'publisher', 'slug', 'description', 'fulfillment_mode',
                        'initials', 'accent_color', 'instant', 'package_tabs_enabled', 'package_tabs',
                        'manual_instructions', 'manual_open_time', 'manual_close_time', 'manual_timezone', 'margin_percent', 'sort_order', 'is_active',
                        'nickname_check_enabled', 'nickname_game_code', 'nickname_user_field_key',
                        'nickname_server_field_key'),
                    'image_url' => $product->getFirstMediaUrl('image'),
                    'banner_url' => $product->getFirstMediaUrl('banner'),
                    'fields' => $product->fields->sortBy('sort_order')->values()->toArray(),
                    'notices' => $product->notices->sortBy('sort_order')->values()->map(fn (ProductNotice $notice): array => [
                        ...$notice->only('id', 'title', 'body', 'sort_order', 'is_active'),
                    ])->all(),
                    'packages' => $product->packages->sortBy([
                        ['sort_order', 'asc'], ['nominal_value', 'asc'],
                    ])->values()->map(fn (ProductPackage $package): array => [
                        ...$package->only('id', 'code', 'name', 'note', 'group_name', 'nominal_value', 'sort_order', 'is_active', 'pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr'),
                        'image_url' => $package->getFirstMediaUrl('image'),
                        'mappings' => $package->mappings->map(fn (ProviderMapping $mapping): array => [
                            ...$mapping->only('id', 'provider_id', 'external_sku', 'cost_idr',
                                'max_price_idr', 'priority', 'is_active'),
                            'provider_code' => $providers->get($mapping->provider_id)?->code,
                            'customer_no_template' => data_get($mapping->fulfillment_config, 'customer_no_template'),
                            'stock_key' => $providers->get($mapping->provider_id)?->code === 'VOUCHER_STOCK'
                                ? data_get($mapping->fulfillment_config, 'stock_key') : null,
                            'stock_counts' => $providers->get($mapping->provider_id)?->code === 'VOUCHER_STOCK'
                                ? ($stockCounts[(string) data_get($mapping->fulfillment_config, 'stock_key', '')]
                                    ?? ['available' => 0, 'reserved' => 0, 'delivered' => 0, 'void' => 0, 'total' => 0])
                                : null,
                        ])->all(),
                    ])->all(),
                ]),
            'defaultMargin' => json_decode((string) DB::table('system_settings')->where('key', 'catalog.default_margin_percent')->value('value'), true) ?? 0,
            'digiflazzItems' => DB::table('digiflazz_catalog_items')->orderBy('category')->orderBy('brand')->orderBy('product_name')->get()
                ->map(fn (object $item): array => [...((array) $item), 'available' => app(DigiflazzCatalogService::class)->available($item),
                    'mapped' => ProviderMapping::where('external_sku', $item->buyer_sku_code)->whereIn('provider_id', Provider::where('code', 'DIGIFLAZZ')->pluck('id'))->exists()]),
        ]);
    }

    public function category(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'icon' => ['nullable', Rule::in(['gamepad', 'ticket', 'play', 'smartphone', 'zap', 'grid'])],
            'sort_order' => ['required', 'integer', 'min:0'],
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
            $category = Category::create([...$data, 'is_active' => false]);
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
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'manual_open_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_close_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_timezone' => ['nullable', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
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

        DB::transaction(function () use ($request, $data, $audit): void {
            $product = Product::create([...$data, 'is_active' => false]);
            $audit->record($request, 'catalog.product.created', 'product', $product->id, null, $product->toArray());
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
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'nickname_check_enabled' => ['sometimes', 'boolean'],
            'nickname_game_code' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'nickname_user_field_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'nickname_server_field_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
        ]);
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
                    'nickname_game_code' => 'Game code dan field User ID wajib diisi ketika cek nickname aktif.',
                ]);
            }
            if (! in_array($data['nickname_user_field_key'], $fieldKeys, true)) {
                throw ValidationException::withMessages([
                    'nickname_user_field_key' => 'Field User ID harus memakai field produk yang tersedia.',
                ]);
            }
            if (! empty($data['nickname_server_field_key'])
                && ! in_array($data['nickname_server_field_key'], $fieldKeys, true)) {
                throw ValidationException::withMessages([
                    'nickname_server_field_key' => 'Field Server harus memakai field produk yang tersedia.',
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
            $audit->record($request, 'catalog.product.updated', 'product', $product->id, $before, $product->toArray());
        });

        return back();
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
            ]);
            $mapping = $copy->mappings()->create([
                'provider_id' => $sourceMapping->provider_id,
                'external_sku' => null,
                'cost_idr' => $sourceMapping->cost_idr,
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
                'integer', 'min:0'],
        ]);

        if ($product->package_tabs_enabled) {
            $group = trim((string) ($data['group_name'] ?? ''));
            $tabs = collect($product->package_tabs ?? []);
            if ($group === '' || ! $tabs->contains($group)) {
                throw ValidationException::withMessages([
                    'group_name' => 'Pilih grup yang tersedia pada tab nominal produk.',
                ]);
            }
        }

        DB::transaction(function () use ($request, $product, $data, $audit): void {
            $package = $product->packages()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'note' => $data['note'] ?? null,
                'group_name' => $data['group_name'] ?? null,
                'nominal_value' => $data['nominal_value'] ?? null,
                'sort_order' => $data['sort_order'],
                'is_active' => false,
            ]);
            if ($product->fulfillment_mode === 'MANUAL') {
                $provider = Provider::where('code', 'MANUAL')->firstOrFail();
                $mapping = ProviderMapping::create([
                    'product_package_id' => $package->id,
                    'provider_id' => $provider->id,
                    'external_sku' => null,
                    'cost_idr' => $data['cost_idr'],
                    'max_price_idr' => null,
                    'priority' => 0,
                    'is_active' => false,
                ]);
                $audit->record($request, 'catalog.mapping.created', 'provider_mapping',
                    $mapping->id, null, $mapping->toArray());
            }
            $audit->record($request, 'catalog.package.created', 'product_package', $package->id, null, $package->toArray());
        });

        return back();
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
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:1000', 'required_if:pricing_mode,PERCENT'],
            'margin_fixed_idr' => ['nullable', 'integer', 'min:0', 'max:1000000000', 'required_if:pricing_mode,FIXED'],
            'sell_price_idr' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'required_if:pricing_mode,SELL_PRICE'],
        ]);

        $product = $package->product()->firstOrFail();
        if ($product->package_tabs_enabled) {
            $group = trim((string) ($data['group_name'] ?? $package->group_name ?? ''));
            $tabs = collect($product->package_tabs ?? []);
            if ($group === '' || ! $tabs->contains($group)) {
                throw ValidationException::withMessages([
                    'group_name' => 'Pilih grup yang tersedia pada tab nominal produk.',
                ]);
            }
        }

        if (($data['pricing_mode'] ?? $package->pricing_mode) === 'SELL_PRICE'
            && (int) ($data['sell_price_idr'] ?? $package->sell_price_idr) < (int) $package->mappings()->max('cost_idr')) {
            throw ValidationException::withMessages(['sell_price_idr' => 'Harga jual tidak boleh di bawah modal.']);
        }
        DB::transaction(function () use ($request, $package, $data, $audit): void {
            $before = $package->toArray();
            $package->update($data);
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
            'fields.*.field_key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(['text', 'tel', 'email'])],
            'fields.*.is_required' => ['required', 'boolean'],
        ]);
        $keys = array_column($data['fields'], 'field_key');
        $references = [];
        if ($product->nickname_check_enabled) {
            $references = array_filter([$product->nickname_user_field_key, $product->nickname_server_field_key]);
        }
        foreach (ProviderMapping::whereIn('product_package_id', $product->packages()->pluck('id'))->get() as $mapping) {
            preg_match_all('/\{\{([^}]+)\}\}/', (string) data_get($mapping->fulfillment_config, 'customer_no_template'), $matches);
            $references = array_merge($references, $matches[1] ?? []);
        }
        if (array_diff($references, $keys)) {
            throw ValidationException::withMessages(['fields' => 'Kode kolom masih dipakai cek nickname atau template pengiriman. Ubah pengaturan tersebut dahulu.']);
        }
        DB::transaction(function () use ($request, $product, $data, $audit): void {
            $before = $product->fields()->get()->toArray();
            $product->fields()->delete();
            foreach ($data['fields'] as $index => $field) {
                ProductInputField::create([
                    ...$field, 'product_id' => $product->id, 'sort_order' => $index,
                ]);
            }
            $audit->record($request, 'catalog.fields.updated', 'product', $product->id,
                $before, $product->fields()->get()->toArray());
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
        $provider = Provider::findOrFail($mapping->provider_id);
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'cost_idr' => [$provider->code === 'MANUAL' ? 'required' : 'prohibited', 'integer', 'min:0'],
            'customer_no_template' => [$provider->code === 'DIGIFLAZZ' ? 'nullable' : 'prohibited', 'string', 'max:500'],
        ]);
        if ($provider->code !== 'MANUAL') {
            unset($data['cost_idr']);
        }

        if ($provider->code === 'DIGIFLAZZ') {
            $template = trim((string) ($data['customer_no_template'] ?? ''));
            $productId = DB::table('product_packages')->where('id', $mapping->product_package_id)
                ->value('product_id');
            $fieldKeys = DB::table('product_input_fields')->where('product_id', $productId)
                ->pluck('field_key')->all();

            preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
            foreach ($matches[1] ?? [] as $fieldKey) {
                if (! preg_match('/^[a-z][a-z0-9_]*$/', $fieldKey)
                    || ! in_array($fieldKey, $fieldKeys, true)) {
                    throw ValidationException::withMessages([
                        'customer_no_template' => 'Placeholder {{'.$fieldKey.'}} tidak cocok dengan field produk.',
                    ]);
                }
            }

            if ($data['is_active'] && count($fieldKeys) > 1 && $template === '') {
                throw ValidationException::withMessages([
                    'customer_no_template' => 'Template customer_no wajib untuk produk dengan lebih dari satu field.',
                ]);
            }

            $data['fulfillment_config'] = $template === ''
                ? null : ['customer_no_template' => $template];
            unset($data['customer_no_template']);
        }

        DB::transaction(function () use ($request, $mapping, $data, $audit): void {
            $before = $mapping->toArray();
            $mapping->update($data);
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

    public function globalMargin(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['margin_percent' => ['required', 'numeric', 'min:0', 'max:1000']]);
        DB::transaction(function () use ($request, $data, $audit): void {
            $before = Product::where('fulfillment_mode', 'AUTO_PROVIDER')->pluck('margin_percent', 'id')->all();
            Product::where('fulfillment_mode', 'AUTO_PROVIDER')->update(['margin_percent' => $data['margin_percent']]);
            DB::table('system_settings')->updateOrInsert(['key' => 'catalog.default_margin_percent'], [
                'value' => json_encode($data['margin_percent']), 'updated_at' => now(), 'created_at' => now(),
            ]);
            $audit->record($request, 'catalog.margin.global_updated', 'product', 'all', $before, $data);
        });

        return back();
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
