<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductPackage;
use App\Models\StoreAsset;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'in:manual'],
        ]);
        $search = trim($filters['q'] ?? '');

        $categories = Category::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => [
                ...$category->only('name', 'slug'),
                'image_url' => $category->getFirstMediaUrl('image'),
            ]);

        $products = Product::with('category')->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereHas('packages', fn ($query) => $query->where('is_active', true))
            ->when(($filters['category'] ?? null), fn ($query, $slug) => $query->whereHas('category',
                fn ($category) => $category->where('slug', $slug)))
            ->when(($filters['mode'] ?? null) === 'manual', fn ($query) => $query->where('fulfillment_mode', 'MANUAL'))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('sort_order')->orderBy('name')->paginate(24)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                ...$product->only('name', 'slug'),
                'category_name' => $product->category->name,
                'image_url' => $product->getFirstMediaUrl('image'),
            ]);

        $assets = StoreAsset::where('is_active', true)->get()->keyBy('key');

        return Inertia::render('Catalog/Index', [
            'categories' => $categories,
            'products' => $products,
            'filters' => $filters,
            'logoUrl' => $assets->get('logo')?->getFirstMediaUrl('image'),
            'bannerUrl' => $assets->get('banner_desktop')?->getFirstMediaUrl('image'),
            'mobileBannerUrl' => $assets->get('banner_mobile')?->getFirstMediaUrl('image'),
            'popupUrl' => $assets->get('popup')?->getFirstMediaUrl('image'),
            'faviconUrl' => $assets->get('favicon')?->getFirstMediaUrl('image'),
            'bannerTarget' => $assets->get('banner_desktop')?->target_url,
        ]);
    }

    public function show(string $slug): Response
    {
        $product = Product::with('category')->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();

        $packages = ProductPackage::where('product_id', $product->id)
            ->where('is_active', true)
            ->orderByRaw('nominal_value IS NULL')
            ->orderBy('nominal_value')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ProductPackage $package): array => [
                ...$package->only('id', 'name', 'nominal_value'),
                'image_url' => $package->getFirstMediaUrl('image'),
            ]);

        return Inertia::render('Catalog/Show', [
            'product' => [
                ...$product->only('name', 'slug', 'description'),
                'category_name' => $product->category->name,
                'image_url' => $product->getFirstMediaUrl('image'),
                'banner_url' => $product->getFirstMediaUrl('banner'),
            ],
            'packages' => $packages,
            'fields' => ProductInputField::where('product_id', $product->id)->orderBy('sort_order')
                ->get(['field_key', 'label', 'type', 'is_required']),
            'faviconUrl' => StoreAsset::where('key', 'favicon')->where('is_active', true)
                ->first()?->getFirstMediaUrl('image'),
        ]);
    }
}
