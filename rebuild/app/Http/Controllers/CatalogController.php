<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductPackage;
use App\Models\ProductReview;
use App\Models\SavedGameAccount;
use App\Models\StoreAsset;
use App\Services\CatalogNominalOrder;
use App\Services\CheckoutPricing;
use App\Services\OperationalSettings;
use App\Services\PaymentRoutingService;
use App\Services\StorefrontContentService;
use App\Support\OptimizedMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Response;
use App\Support\SeoMetadata;

class CatalogController
{
    /**
     * D12: pencarian mencakup nama produk, deskripsi, nama kategori,
     * dan nama paket (bukan cuma nama produk).
     *
     * Nama paket HANYA dicocokkan pada paket yang benar-benar bisa
     * dibeli (set buyablePackageIds): pencocokan dengan syarat
     * is_active saja membuat produk lolos untuk query yang hanya
     * cocok dengan nama paket TAK TERBELI — produk itu tetap lolos
     * syarat buyableProductIds lewat paket LAIN yang terbeli, dan
     * nominal yang sengaja disembunyikan di halaman produk muncul
     * di hasil pencarian. Nama produk / deskripsi / kategori tidak
     * dibatasi buyable (tidak berubah).
     */
    private function searchScope(Builder $query, string $needle): void
    {
        $query->where(function (Builder $nested) use ($needle): void {
            $nested->where('name', 'like', '%'.$needle.'%')
                ->orWhere('description', 'like', '%'.$needle.'%')
                ->orWhereHas('category', fn (Builder $category) => $category
                    ->where('name', 'like', '%'.$needle.'%'))
                ->orWhereHas('packages', fn (Builder $package) => $package
                    ->where('is_active', true)
                    ->whereIn('id', $this->buyablePackageIds())
                    ->where('name', 'like', '%'.$needle.'%'));
        });
    }

    /**
     * D11: produk hanya tampil bila minimal satu paketnya benar-benar
     * bisa dibeli. Keterbelian diputuskan CheckoutPricing::forPackages —
     * cek yang sama dengan yang dipakai checkout (bukan cuma paket aktif).
     *
     * Keterbelian per produk TIDAK bergantung filter request (kategori /
     * pencarian / mode) — filter itu diterapkan terpisah oleh query
     * pemanggil. Karena itu set global dihitung sekali per request dan
     * dipakai ulang listing + popular (sebelumnya forPackages
     * menghargai seluruh katalog 2x per request index). Memo ini hidup
     * di instance controller (resolved per request), jadi tidak ada
     * risiko basi antar-request; checkout tetap menghargai ulang
     * langsung (otoritatif), listing hanya discovery.
     *
     * @return array<int>
     */
    private ?array $buyableProductIdsMemo = null;

    private ?array $buyablePackageIdsMemo = null;

    /**
     * ID paket yang benar-benar bisa dibeli (punya quote dari
     * CheckoutPricing::forPackages). Dihitung sekali per request
     * bersama buyableProductIds — satu panggilan forPackages untuk
     * keduanya, dipakai listing + search + pencocokan nama paket.
     *
     * @return array<int>
     */
    private function buyablePackageIds(): array
    {
        if ($this->buyablePackageIdsMemo !== null) {
            return $this->buyablePackageIdsMemo;
        }

        $packageIds = ProductPackage::where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->pluck('id')
            ->all();
        if ($packageIds === []) {
            return $this->buyablePackageIdsMemo = [];
        }
        $quotes = app(CheckoutPricing::class)->forPackages($packageIds);

        return $this->buyablePackageIdsMemo = array_keys($quotes);
    }

    private function buyableProductIds(): array
    {
        if ($this->buyableProductIdsMemo !== null) {
            return $this->buyableProductIdsMemo;
        }

        $buyablePackageIds = $this->buyablePackageIds();
        if ($buyablePackageIds === []) {
            return $this->buyableProductIdsMemo = [];
        }

        return $this->buyableProductIdsMemo = ProductPackage::whereIn('id', $buyablePackageIds)
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();
    }

    public function index(Request $request, StorefrontContentService $content): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'in:manual'],
        ]);
        $search = trim($filters['q'] ?? '');

        $categories = Category::with('media')->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => [
                ...$category->only('name', 'slug', 'icon'),
                'image_url' => OptimizedMedia::url($category, 'image', 'card'),
            ]);

        $baseProducts = Product::with(['category', 'media'])->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereHas('packages', fn ($query) => $query->where('is_active', true))
            ->when(($filters['category'] ?? null), fn ($query, $slug) => $query->whereHas('category',
                fn ($category) => $category->where('slug', $slug)))
            ->when(($filters['mode'] ?? null) === 'manual', fn ($query) => $query->where('fulfillment_mode', 'MANUAL'))
            ->when($search !== '', fn ($query) => $this->searchScope($query, $search));

        // Natural name order is calculated before pagination so numbered names
        // (5, 20, 1100) do not move between pages in SQL string order.
        $buyable = $baseProducts->whereIn('id', $this->buyableProductIds());
        $orderedIds = (clone $buyable)->withoutEagerLoads()->reorder()->get(['id', 'name'])
            ->sort(fn (Product $a, Product $b): int => strnatcasecmp($a->name, $b->name)
                ?: ($a->id <=> $b->id))->pluck('id')->values();
        $perPage = 24;
        $currentPage = max(1, (int) $request->query('page', 1));
        $pageIds = $orderedIds->forPage($currentPage, $perPage)->all();
        $positions = array_flip($pageIds);
        $pageProducts = Product::with(['category', 'media'])->whereIn('id', $pageIds)->get()
            ->sortBy(fn (Product $row): int => $positions[$row->id])->values();
        $products = (new LengthAwarePaginator($pageProducts, $orderedIds->count(), $perPage, $currentPage, [
            'path' => $request->url(), 'query' => $request->query(),
        ]))->through(fn (Product $product): array => [
            ...$product->only('name', 'slug', 'initials', 'accent_color', 'instant'),
            'category_name' => $product->category->name,
            'category_slug' => $product->category->slug,
            'image_url' => OptimizedMedia::url($product, 'image', 'card'),
        ]);

        $basePopular = Product::with(['category', 'media'])
            ->withCount(['reviews as active_reviews_count' => fn ($query) => $query->where('is_active', true)])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereHas('packages', fn ($query) => $query->where('is_active', true));
        $popularProducts = $basePopular
            ->whereIn('id', $this->buyableProductIds())
            ->orderByDesc('popular')
            ->orderByDesc('active_reviews_count')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Product $product): array => [
                ...$product->only('name', 'slug', 'popular', 'initials', 'accent_color', 'instant'),
                'publisher' => trim((string) $product->publisher) ?: null,
                'category_name' => $product->category->name,
                'category_slug' => $product->category->slug,
                'image_url' => OptimizedMedia::url($product, 'image', 'card'),
            ]);

        $assets = StoreAsset::with('media')->where('is_active', true)->get()->keyBy('key');
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
                    'desktop_url' => ($desktop ? OptimizedMedia::url($desktop, 'image', 'display') : '') ?: ($mobile ? OptimizedMedia::url($mobile, 'image', 'display') : ''),
                    'mobile_url' => ($mobile ? OptimizedMedia::url($mobile, 'image', 'display') : '') ?: ($desktop ? OptimizedMedia::url($desktop, 'image', 'display') : ''),
                ]]);
            }
        }

        return SeoMetadata::render('Catalog/Index', [
            'categories' => $categories,
            'products' => $products,
            'popularProducts' => $popularProducts,
            'filters' => $filters,
            'logoUrl' => ($assets->get('logo') ? OptimizedMedia::url($assets->get('logo'), 'image', 'icon') : ''),
            'faviconUrl' => $assets->get('favicon')?->getFirstMediaUrl('image'),
            'banners' => $banners,
            'popups' => $content->popups(),
            'news' => $content->news(3),
            'reviews' => $content->reviews(6),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['required', 'string', 'max:80']]);
        $needle = trim($filters['q']);

        if ($needle === '') {
            return response()->json(['products' => []]);
        }

        $baseProducts = Product::with(['category', 'media'])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereHas('packages', fn ($query) => $query->where('is_active', true));
        $this->searchScope($baseProducts, $needle);
        $products = $baseProducts
            ->whereIn('id', $this->buyableProductIds())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Product $product): array => [
                'name' => $product->name,
                'slug' => $product->slug,
                'category_name' => $product->category->name,
                'image_url' => OptimizedMedia::url($product, 'image', 'card'),
            ])
            ->values();

        return response()->json(['products' => $products]);
    }

    public function show(
        Request $request,
        string $slug,
        CheckoutPricing $pricing,
        PaymentRoutingService $paymentRouting,
        StorefrontContentService $content,
    ): Response {
        $product = Product::with(['category', 'media', 'packageGroups.media'])->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();

        $packageRows = ProductPackage::with('media')->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderByRaw('nominal_value IS NULL')
            ->orderBy('sort_order')->orderBy('nominal_value')->orderBy('id')->get();
        $previewPrices = $pricing->forPackages($packageRows->pluck('id')->all());
        $packages = app(CatalogNominalOrder::class)->sort($packageRows)
            ->map(function (ProductPackage $package) use ($previewPrices): array {
                $quote = $previewPrices[$package->id] ?? null;
                $available = $quote !== null;
                $price = $quote['subtotal_idr'] ?? null;

                return [
                    ...$package->only('id', 'name', 'note', 'group_name', 'nominal_value'),
                    'image_url' => OptimizedMedia::url($package, 'image', 'card'),
                    'is_available' => $available,
                    'price_idr' => $price,
                ];
            })
            // D11: nominal yang tidak bisa dibeli tidak tampil sama sekali.
            ->filter(fn (array $package): bool => $package['is_available'])
            ->values();

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

        return SeoMetadata::render('Catalog/Show', [
            'product' => [
                ...$product->only('id', 'name', 'publisher', 'slug', 'description', 'nickname_check_enabled',
                    'package_tabs_enabled', 'package_tabs'),
                'category_name' => $product->category->name,
                'category_slug' => $product->category->slug,
                'image_url' => OptimizedMedia::url($product, 'image', 'detail'),
                'image_card_url' => OptimizedMedia::url($product, 'image', 'card'),
                'banner_url' => OptimizedMedia::url($product, 'banner', 'banner-display'),
                'checkout_nominal_description' => $product->fulfillment_mode === 'MANUAL'
                    ? 'Pesanan diproses admin setelah pembayaran.'
                    : 'Pesanan diproses otomatis setelah pembayaran.',
            ],
            'packages' => $packages,
            'packageGroups' => $product->packageGroups->map(fn ($group): array => [
                'name' => $group->name,
                'image_url' => OptimizedMedia::url($group, 'image', 'card'),
                'sort_order' => $group->sort_order,
            ])->all(),
            'fields' => ProductInputField::where('product_id', $product->id)->orderBy('sort_order')
                ->get(['field_key', 'label', 'placeholder', 'type', 'is_required']),
            'customer' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'balance_idr' => (int) ($user->wallet?->balance_idr ?? 0),
            ] : null,
            'paymentChannels' => $paymentRouting->publicOrderChannels($user),
            'checkoutReservationMinutes' => app(OperationalSettings::class)->value('checkout_reservation_minutes'),
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
