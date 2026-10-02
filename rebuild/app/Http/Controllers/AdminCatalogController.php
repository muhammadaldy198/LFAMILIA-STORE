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
    public function contentIndex(): Response
    {
        return Inertia::render('Admin/ProductContent', [
            'products' => Product::with(['packages', 'notices'])->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Product $product): array => [
                    ...$product->only(
                        'id', 'name', 'publisher', 'description', 'fulfillment_mode',
                        'manual_instructions', 'manual_open_time', 'manual_close_time', 'manual_timezone',
                        'is_active'
                    ),
                    'image_url' => $product->getFirstMediaUrl('image'),
                    'banner_url' => $product->getFirstMediaUrl('banner'),
                    'notices' => $product->notices->sortBy('sort_order')->values()->map(fn (ProductNotice $notice): array => [
                        ...$notice->only('id', 'title', 'body', 'sort_order', 'is_active'),
                    ])->all(),
                    'packages' => $product->packages->sortBy([
                        ['sort_order', 'asc'], ['nominal_value', 'asc'],
                    ])->values()->map(fn (ProductPackage $package): array => [
                        ...$package->only('id', 'name', 'note', 'group_name', 'is_active'),
                        'image_url' => $package->getFirstMediaUrl('image'),
                    ])->all(),
                ]),
        ]);
    }

    public function updateProductContent(
        Request $request,
        Product $product,
        CatalogAudit $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fulfillment_mode' => ['sometimes', Rule::in(['AUTO_PROVIDER', 'MANUAL'])],
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'manual_open_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_close_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_timezone' => ['nullable', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
        ]);
        if ($data['fulfillment_mode'] !== 'MANUAL') {
            $data['manual_instructions'] = null;
            $data['manual_open_time'] = null;
            $data['manual_close_time'] = null;
            $data['manual_timezone'] = 'Asia/Jakarta';
        }

        $before = $product->only(array_keys($data));
        $product->update($data);
        $audit->record($request, 'catalog.product.content_updated', 'product', $product->id, $before, $data);

        return back();
    }

    public function updatePackageContent(
        Request $request,
        ProductPackage $package,
        CatalogAudit $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:80'],
            'group_name' => ['nullable', 'string', 'max:120'],
        ]);
        $before = $package->only(array_keys($data));
        $package->update($data);
        $audit->record($request, 'catalog.package.content_updated', 'product_package', $package->id, $before, $data);

        return back();
    }

    public function index(): Response
    {
        $providers = Provider::all(['id', 'code', 'is_active'])->keyBy('id');

        return Inertia::render('Admin/Catalog', [
            'categories' => Category::orderBy('sort_order')->get()->map(fn (Category $category): array => [
                ...$category->only('id', 'name', 'slug', 'sort_order', 'is_active'),
                'image_url' => $category->getFirstMediaUrl('image'),
            ]),
            'products' => Product::with(['packages.mappings', 'fields', 'notices'])->orderBy('sort_order')->get()
                ->map(fn (Product $product): array => [
                    ...$product->only('id', 'category_id', 'name', 'publisher', 'slug', 'description', 'fulfillment_mode',
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
                        ])->all(),
                    ])->all(),
                ]),
            'defaultMargin' => json_decode((string) DB::table('system_settings')->where('key', 'catalog.default_margin_percent')->value('value'), true) ?? 0,
            'digiflazzItems' => DB::table('digiflazz_catalog_items')->orderBy('category')->orderBy('brand')->orderBy('product_name')->get()
                ->map(fn (object $item): array => [...((array) $item), 'available' => app(DigiflazzCatalogService::class)->available($item),
                    'mapped' => ProviderMapping::where('external_sku', $item->buyer_sku_code)->whereIn('provider_id', Provider::where('code', 'DIGIFLAZZ')->pluck('id'))->exists()]),
            'assets' => StoreAsset::orderBy('id')->get()->map(fn (StoreAsset $asset): array => [
                ...$asset->only('id', 'key', 'target_url', 'is_active'),
                'image_url' => $asset->getFirstMediaUrl('image'),
            ]),
        ]);
    }

    public function category(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
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
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['slug'] ??= $category->slug;

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
        $audit->record($request, 'catalog.category.deleted', 'category', $id, $before, null);

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
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
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
