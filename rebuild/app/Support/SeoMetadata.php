<?php

namespace App\Support;

use App\Models\HomeBanner;
use App\Models\StoreAsset;
use App\Services\StorefrontContentService;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Single SEO payload used by both Blade and hydrated Inertia pages. */
final class SeoMetadata
{
    public static function render(string $component, array $props = []): Response
    {
        $storefront = app(StorefrontContentService::class)->shared();

        return Inertia::render($component, [
            ...$props,
            'seo' => self::forPage($component, $props, $storefront),
        ]);
    }

    public static function forPage(string $component, array $props, array $storefront): array
    {
        $storeName = trim((string) ($storefront['storeName'] ?? 'LFAMILIA STORE')) ?: 'LFAMILIA STORE';
        $defaultDescription = trim((string) ($storefront['seoDescription'] ?? ''))
            ?: $storeName.' menyediakan top up game, voucher digital, pulsa, paket data, dan layanan digital.';
        $labels = [
            'Content/News' => 'Berita', 'Content/Faq' => 'Pertanyaan Umum',
            'Content/Contact' => 'Hubungi Kami', 'Content/Promo' => 'Promo',
            'Content/Tools' => 'LFAMILIA Tools', 'Content/Status' => 'Status Layanan',
            'Content/Leaderboard' => 'Leaderboard',
        ];
        $product = $component === 'Catalog/Show' ? (array) ($props['product'] ?? []) : [];
        $article = $component === 'Content/Article' ? (array) ($props['article'] ?? []) : [];
        $legal = $component === 'Content/Legal' ? (array) ($props['page'] ?? []) : [];
        $fallbackTitle = match ($component) {
            'Catalog/Index' => $storeName,
            'Catalog/Show' => trim((string) ($product['name'] ?? 'Produk')).' — LFAMILIA STORE',
            'Content/Article' => trim((string) ($article['title'] ?? 'Berita')).' — LFAMILIA STORE',
            'Content/Legal' => trim((string) ($legal['title'] ?? 'Informasi')).' — LFAMILIA STORE',
            'Content/Tool' => (match ($props['tool'] ?? '') {
                'win-rate' => 'Win Rate', 'zodiac' => 'Zodiac',
                'magic-wheel' => 'Magic Wheel', default => 'LFAMILIA Tools',
            }).' — LFAMILIA STORE',
            default => ($labels[$component] ?? 'LFAMILIA STORE').' — LFAMILIA STORE',
        };
        $entity = $product ?: ($article ?: $legal);
        $customTitle = trim((string) ($entity['seoTitle'] ?? $entity['seo_title']
            ?? ($component === 'Catalog/Index' ? ($storefront['seoTitle'] ?? '') : '')));
        $customDescription = trim((string) ($entity['seoDescription'] ?? $entity['seo_description'] ?? ''));
        $rawDescription = $customDescription ?: match ($component) {
            'Catalog/Show' => trim((string) ($product['description'] ?? ''))
                ?: 'Top up '.($product['name'] ?? 'produk digital').' di LFAMILIA STORE.',
            'Content/Article' => trim((string) ($article['summary'] ?? '')) ?: $defaultDescription,
            default => $defaultDescription,
        };
        $title = $customTitle ?: $fallbackTitle;
        $description = self::excerpt($rawDescription);
        $image = self::absoluteUrl((string) ($product['image_url'] ?? $article['cover_url'] ?? ''))
            ?: self::defaultImage();
        $canonical = $component === 'Catalog/Index' ? route('catalog.index') : url()->current();

        $structuredData = null;
        if ($component === 'Catalog/Index') {
            $structuredData = [
                '@context' => 'https://schema.org', '@type' => 'WebSite',
                'name' => $storeName, 'url' => route('catalog.index'), 'inLanguage' => 'id-ID',
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => route('catalog.index').'?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        } elseif ($component === 'Catalog/Show') {
            $structuredData = [
                '@context' => 'https://schema.org', '@type' => 'Product',
                'name' => (string) ($product['name'] ?? ''),
                'description' => $description, 'url' => $canonical,
            ];
            if ($image !== '') {
                $structuredData['image'] = [$image];
            }
            if (trim((string) ($product['publisher'] ?? '')) !== '') {
                $structuredData['brand'] = [
                    '@type' => 'Brand', 'name' => trim((string) $product['publisher']),
                ];
            }
            $prices = collect($props['packages'] ?? [])
                ->filter(fn ($package): bool => ($package['is_available'] ?? false) === true
                    && is_numeric($package['price_idr'] ?? null)
                    && (int) $package['price_idr'] > 0)
                ->pluck('price_idr')->map(fn ($price): int => (int) $price);
            if ($prices->isNotEmpty()) {
                $structuredData['offers'] = [
                    '@type' => 'AggregateOffer', 'priceCurrency' => 'IDR',
                    'lowPrice' => $prices->min(), 'highPrice' => $prices->max(),
                    'offerCount' => $prices->count(),
                    'availability' => 'https://schema.org/InStock',
                ];
            }
        }

        return [
            'title' => $title, 'description' => $description,
            'image' => $image, 'canonical' => $canonical,
            'type' => $component === 'Content/Article' ? 'article' : 'website',
            'siteName' => $storeName, 'structuredData' => $structuredData,
        ];
    }

    private static function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
        if (mb_strlen($value) <= 160) {
            return $value;
        }
        $prefix = mb_substr($value, 0, 161);
        $lastSpace = mb_strrpos($prefix, ' ');

        return rtrim(mb_substr($prefix, 0, $lastSpace !== false && $lastSpace > 0 ? $lastSpace : 160));
    }

    private static function absoluteUrl(string $value): string
    {
        $value = trim($value);
        if (Str::startsWith($value, ['https://', 'http://'])) {
            return $value;
        }

        return Str::startsWith($value, '/') ? url($value) : '';
    }

    /** Use existing official store artwork; newly generated media is 1200x630. */
    private static function defaultImage(): string
    {
        $assets = StoreAsset::query()->where('is_active', true)
            ->whereIn('key', ['og_image', 'banner_desktop', 'logo'])
            ->with('media')->get()->keyBy('key');

        foreach (['og_image', 'banner_desktop'] as $key) {
            if ($assets->has($key)) {
                $url = self::mediaUrl($assets->get($key), 'image');
                if ($url !== '') {
                    return $url;
                }
            }
        }

        $banner = HomeBanner::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->with('media')->get()
            ->first(fn (HomeBanner $item): bool => $item->getFirstMedia('desktop') !== null);
        if ($banner) {
            $url = self::mediaUrl($banner, 'desktop');
            if ($url !== '') {
                return $url;
            }
        }

        if ($assets->has('logo')) {
            $url = self::mediaUrl($assets->get('logo'), 'image');
            if ($url !== '') {
                return $url;
            }
        }

        // A checked-in official wordmark exists even on fresh installations.
        return asset('brand/lfamilia-footer-desktop-wordmark.jpg');
    }

    private static function mediaUrl($model, string $collection): string
    {
        return self::absoluteUrl(OptimizedMedia::url($model, $collection, 'og-share'));
    }
}
