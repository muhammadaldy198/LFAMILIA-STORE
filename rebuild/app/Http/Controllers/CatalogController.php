<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductPackage;
use App\Models\ProductReview;
use App\Models\SavedGameAccount;
use App\Models\StoreAsset;
use App\Services\CheckoutPricing;
use App\Services\PaymentRoutingService;
use App\Services\StorefrontContentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController
{
    public function index(Request $request, StorefrontContentService $content): Response
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
                ...$product->only('name', 'slug', 'initials', 'accent_color', 'instant'),
                'category_name' => $product->category->name,
                'category_slug' => $product->category->slug,
                'image_url' => $product->getFirstMediaUrl('image'),
            ]);

        $popularProducts = Product::with('category')
            ->withCount(['reviews as active_reviews_count' => fn ($query) => $query->where('is_active', true)])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereHas('packages', fn ($query) => $query->where('is_active', true))
            ->orderByDesc('popular')
            ->orderByDesc('active_reviews_count')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Product $product): array => [
                ...$product->only('name', 'slug', 'popular', 'initials', 'accent_color', 'instant'),
                'category_name' => $product->category->name,
                'category_slug' => $product->category->slug,
                'image_url' => $product->getFirstMediaUrl('image'),
            ]);

        $assets = StoreAsset::where('is_active', true)->get()->keyBy('key');
        $banners = $content->homeBanners();
        if ($banners->isEmpty()) {
            $desktop = $assets->get('banner_desktop');
            $mobile = $assets->get('banner_mobile');
            if ($desktop || $mobile) {
                $banners = collect([[
                    'id' => null,
                    'title' => 'LFAMILIA STORE',
                    'subtitle' => null,
                    'cta_label' => null,
                    'cta_href' => $desktop?->target_url ?: $mobile?->target_url,
                    'show_desktop' => true,
                    'show_mobile' => true,
                    'sort_order' => 0,
                    'desktop_url' => $desktop?->getFirstMediaUrl('image') ?: $mobile?->getFirstMediaUrl('image'),
                    'mobile_url' => $mobile?->getFirstMediaUrl('image') ?: $desktop?->getFirstMediaUrl('image'),
                ]]);
            }
        }

        return Inertia::render('Catalog/Index', [
            'categories' => $categories,
            'products' => $products,
            'popularProducts' => $popularProducts,
            'filters' => $filters,
            'logoUrl' => $assets->get('logo')?->getFirstMediaUrl('image'),
            'faviconUrl' => $assets->get('favicon')?->getFirstMediaUrl('image'),
            'banners' => $banners,
            'popups' => $content->popups(),
            'news' => $content->news(3),
            'reviews' => $content->reviews(6),
        ]);
    }

    public function show(
        Request $request,
        string $slug,
        CheckoutPricing $pricing,
        PaymentRoutingService $paymentRouting,
        StorefrontContentService $content,
    ): Response {
        $product = Product::with('category')->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();

        $packages = ProductPackage::where('product_id', $product->id)
            ->where('is_active', true)
            ->orderByRaw('nominal_value IS NULL')
            ->orderBy('sort_order')->orderBy('nominal_value')->orderBy('id')->get()
            ->map(function (ProductPackage $package) use ($pricing): array {
                try {
                    $quote = $pricing->forPackage($package->id);
                    $available = true;
                    $price = $quote['subtotal_idr'];
                } catch (ValidationException) {
                    $available = false;
                    $price = null;
                }

                return [
                    ...$package->only('id', 'name', 'note', 'group_name', 'nominal_value'),
                    'image_url' => $package->getFirstMediaUrl('image'),
                    'is_available' => $available,
                    'price_idr' => $price,
                ];
            });

        $user = auth('web')->user();
        $reviews = ProductReview::where('product_id', $product->id)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at')->orderByDesc('id')->limit(30)
            ->get(['id', 'display_name', 'rating', 'title', 'body', 'published_at', 'created_at'])
            ->map(fn (ProductReview $review): array => [
                ...$review->only('id', 'display_name', 'rating', 'title', 'body'),
                'published_at' => $review->published_at?->toIso8601String(),
                'created_at' => $review->created_at?->toIso8601String(),
                'verified_purchase' => true,
            ]);
        $reviewStats = ProductReview::where('product_id', $product->id)->where('is_active', true)
            ->selectRaw('COUNT(*) as total, COALESCE(AVG(rating), 0) as average')->first();
        $savedAccounts = $user ? SavedGameAccount::where('user_id', $user->id)
            ->where('product_id', $product->id)->orderByDesc('id')->get()
            ->map(fn (SavedGameAccount $saved): array => [
                ...$saved->only('id', 'label', 'nickname'),
                'customer_input' => $saved->customer_input,
            ])->values() : collect();

        return Inertia::render('Catalog/Show', [
            'product' => [
                ...$product->only('id', 'name', 'publisher', 'slug', 'description', 'nickname_check_enabled',
                    'package_tabs_enabled', 'package_tabs'),
                'category_name' => $product->category->name,
                'category_slug' => $product->category->slug,
                'image_url' => $product->getFirstMediaUrl('image'),
                'banner_url' => $product->getFirstMediaUrl('banner'),
                'checkout_nominal_description' => $product->fulfillment_mode === 'MANUAL'
                    ? 'Pesanan diproses admin setelah pembayaran.'
                    : 'Pesanan diproses otomatis setelah pembayaran.',
            ],
            'packages' => $packages,
            'fields' => ProductInputField::where('product_id', $product->id)->orderBy('sort_order')
                ->get(['field_key', 'label', 'placeholder', 'type', 'is_required']),
            'customer' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'balance_idr' => (int) ($user->wallet?->balance_idr ?? 0),
            ] : null,
            'paymentChannels' => $paymentRouting->publicOrderChannels($user),
            'notices' => $product->notices()->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'title', 'body'])
                ->map(function ($notice) use ($product): array {
                    $zone = match ($product->manual_timezone) {
                        'Asia/Makassar' => 'WITA',
                        'Asia/Jayapura' => 'WIT',
                        default => 'WIB',
                    };
                    $replace = [
                        '{{jam_buka}}' => $product->manual_open_time ?: '-',
                        '{{jam_tutup}}' => $product->manual_close_time ?: '-',
                        '{{zona_waktu}}' => $zone,
                    ];

                    return [
                        'id' => (int) $notice->id,
                        'title' => strtr((string) $notice->title, $replace),
                        'body' => strtr((string) $notice->body, $replace),
                    ];
                })->values(),
            'savedAccounts' => $savedAccounts,
            'reviews' => $reviews,
            'reviewStats' => [
                'total' => (int) ($reviewStats?->total ?? 0),
                'average' => round((float) ($reviewStats?->average ?? 0), 1),
            ],
            'faqs' => $content->faqs()->take(6)->values(),
            'initialPackageId' => $request->filled('package')
                ? (string) ($packages->first(fn (array $item): bool => $item['is_available'] === true
                    && (
                        (string) $item['id'] === (string) $request->query('package')
                        || strcasecmp((string) $item['name'], (string) $request->query('package')) === 0
                    )
                )['id'] ?? '')
                : '',
            'faviconUrl' => StoreAsset::where('key', 'favicon')->where('is_active', true)
                ->first()?->getFirstMediaUrl('image'),
        ]);
    }
}
