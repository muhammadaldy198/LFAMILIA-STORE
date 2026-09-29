<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Models\StoreAsset;
use App\Services\CatalogAudit;
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

        return Inertia::render('Admin/Catalog', [
            'categories' => Category::orderBy('sort_order')->get()->map(fn (Category $category): array => [
                ...$category->only('id', 'name', 'slug', 'sort_order', 'is_active'),
                'image_url' => $category->getFirstMediaUrl('image'),
            ]),
            'products' => Product::with(['packages.mappings', 'fields'])->orderBy('sort_order')->get()
                ->map(fn (Product $product): array => [
                    ...$product->only('id', 'category_id', 'name', 'slug', 'description', 'fulfillment_mode',
                        'manual_instructions', 'margin_percent', 'sort_order', 'is_active'),
                    'image_url' => $product->getFirstMediaUrl('image'),
                    'banner_url' => $product->getFirstMediaUrl('banner'),
                    'fields' => $product->fields->sortBy('sort_order')->values()->toArray(),
                    'packages' => $product->packages->sortBy([
                        ['nominal_value', 'asc'], ['sort_order', 'asc'],
                    ])->values()->map(fn (ProductPackage $package): array => [
                        ...$package->only('id', 'code', 'name', 'nominal_value', 'sort_order', 'is_active'),
                        'image_url' => $package->getFirstMediaUrl('image'),
                        'mappings' => $package->mappings->map(fn (ProviderMapping $mapping): array => [
                            ...$mapping->only('id', 'provider_id', 'external_sku', 'cost_idr',
                                'max_price_idr', 'priority', 'is_active'),
                            'provider_code' => $providers->get($mapping->provider_id)?->code,
                        ])->all(),
                    ])->all(),
                ]),
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
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        $data['slug'] = Str::slug($data['name']);
        if (! $data['slug']) {
            throw ValidationException::withMessages(['name' => 'Nama kategori tidak valid.']);
        }
        if (Category::where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['name' => 'Kategori sudah ada.']);
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
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $category, $data, $audit): void {
            $before = $category->toArray();
            $category->update($data);
            $audit->record($request, 'catalog.category.updated', 'category', $category->id, $before, $category->toArray());
        });

        return back();
    }

    public function product(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fulfillment_mode' => ['required', Rule::in(['AUTO_PROVIDER', 'MANUAL'])],
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        $data['slug'] = Str::slug($data['name']);
        if (! $data['slug'] || Product::where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama/slug produk sudah dipakai atau tidak valid.']);
        }
        if ($data['fulfillment_mode'] !== 'MANUAL') {
            $data['manual_instructions'] = null;
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
            'description' => ['nullable', 'string', 'max:5000'],
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($product->fulfillment_mode !== 'MANUAL') {
            $data['manual_instructions'] = null;
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
            'nominal_value' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'cost_idr' => [$product->fulfillment_mode === 'MANUAL' ? 'required' : 'nullable',
                'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $product, $data, $audit): void {
            $package = $product->packages()->create([
                'code' => $data['code'],
                'name' => $data['name'],
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
            'nominal_value' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $package, $data, $audit): void {
            $before = $package->toArray();
            $package->update($data);
            $audit->record($request, 'catalog.package.updated', 'product_package', $package->id, $before, $package->toArray());
        });

        return back();
    }

    public function fields(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array', 'max:20'],
            'fields.*.field_key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(['text', 'tel', 'email'])],
            'fields.*.is_required' => ['required', 'boolean'],
        ]);
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
        ]);
        if ($provider->code !== 'MANUAL') {
            unset($data['cost_idr']);
        }

        DB::transaction(function () use ($request, $mapping, $data, $audit): void {
            $before = $mapping->toArray();
            $mapping->update($data);
            $audit->record($request, 'catalog.mapping.updated', 'provider_mapping', $mapping->id,
                $before, $mapping->toArray());
        });

        return back();
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
