<?php

namespace App\Services;

use App\Models\FaqEntry;
use App\Models\HomeBanner;
use App\Models\NewsArticle;
use App\Models\ProductReview;
use App\Models\SitePopup;
use App\Models\StoreAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorefrontContentService
{
    public function shared(): array
    {
        $settings = $this->settings();
        $assets = $this->assets();

        return [
            'storeName' => $settings['store.name'] ?? 'LFAMILIA STORE',
            'tagline' => $settings['store.tagline'] ?? 'Top up favoritmu, sat set tanpa ribet.',
            'supportWhatsapp' => $settings['store.support_whatsapp'] ?? '',
            'instagramUrl' => $settings['store.instagram_url'] ?? '',
            'supportEmail' => $settings['store.email'] ?? '',
            'discordUrl' => $settings['store.discord_url'] ?? '',
            'supportUrl' => $settings['store.support_url'] ?? '',
            'supportHours' => $settings['store.business_hours'] ?? 'Setiap hari, 08.00–23.00 WIB',
            'supportWidgetEnabled' => (bool) ($settings['store.support_widget_enabled'] ?? true),
            'supportCtaEnabled' => (bool) ($settings['store.support_cta_enabled'] ?? true),
            'supportCtaLabel' => $settings['store.support_cta_label'] ?? 'BUTUH BANTUAN?',
            'supportCtaTitle' => $settings['store.support_cta_title'] ?? 'Tim LFAMILIA siap membantu.',
            'supportCtaBody' => $settings['store.support_cta_body']
                ?? 'Butuh bantuan memilih produk, pembayaran, atau mengecek status pesanan? Hubungi tim kami.',
            'supportCtaButton' => $settings['store.support_cta_button'] ?? 'Hubungi Kami',
            'footerDescription' => $settings['store.footer_description']
                ?? ($settings['store.tagline'] ?? 'Top up favoritmu, sat set tanpa ribet.'),
            'homeNewsTitle' => $settings['store.home_news_title'] ?? 'LFAMILIA NEWS',
            'homeNewsIntro' => $settings['store.home_news_intro'] ?? 'Info gaming, promo, dan update terbaru.',
            'assets' => $assets,
            'presentation' => app(CustomerPresentationService::class)->saved(),
        ];
    }

    public function settings(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return [];
        }

        return DB::table('system_settings')->where('key', 'like', 'store.%')->pluck('value', 'key')
            ->mapWithKeys(function ($value, $key): array {
                $decoded = json_decode((string) $value, true);

                return [$key => $decoded];
            })->all();
    }

    public function assets(): array
    {
        if (! Schema::hasTable('store_assets') || ! Schema::hasTable('media')) {
            return [];
        }

        return StoreAsset::where('is_active', true)->get()->mapWithKeys(fn (StoreAsset $asset): array => [
            $asset->key => [
                'url' => $asset->getFirstMediaUrl('image'),
                'target' => $asset->target_url,
            ],
        ])->all();
    }

    public function homeBanners()
    {
        if (! Schema::hasTable('home_banners')) {
            return collect();
        }

        return HomeBanner::where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (HomeBanner $banner): array => [
                ...$banner->only(
                    'id', 'title', 'subtitle', 'cta_label', 'cta_href',
                    'show_desktop', 'show_mobile', 'sort_order'
                ),
                'desktop_url' => $banner->getFirstMediaUrl('desktop'),
                'mobile_url' => $banner->getFirstMediaUrl('mobile'),
            ])
            ->filter(fn (array $banner): bool => $banner['desktop_url'] !== '' || $banner['mobile_url'] !== '')
            ->values();
    }

    public function popups()
    {
        if (! Schema::hasTable('site_popups')) {
            return collect();
        }

        return SitePopup::where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->limit(1)
            ->get([
                'id', 'title', 'body', 'dismiss_days', 'sort_order',
            ])->map(fn (SitePopup $popup): array => [
                ...$popup->only('id', 'title', 'body', 'dismiss_days', 'sort_order'),
                'image_url' => $popup->getFirstMediaUrl('image'),
            ]);
    }

    public function news(int $limit = 3)
    {
        if (! Schema::hasTable('news_articles')) {
            return collect();
        }

        return NewsArticle::where('is_active', true)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id')
            ->limit($limit)->get()
            ->map(fn (NewsArticle $article): array => [
                ...$article->only('id', 'slug', 'title', 'summary', 'source_label'),
                'published_at' => $article->published_at?->toIso8601String(),
                'cover_url' => $article->getFirstMediaUrl('image'),
            ]);
    }

    public function reviews(int $limit = 6)
    {
        if (! Schema::hasTable('product_reviews')) {
            return collect();
        }

        return ProductReview::query()
            ->join('products', 'products.id', '=', 'product_reviews.product_id')
            ->where('product_reviews.is_active', true)
            ->where(fn ($query) => $query->whereNull('product_reviews.published_at')
                ->orWhere('product_reviews.published_at', '<=', now()))
            ->orderByDesc('product_reviews.published_at')->orderByDesc('product_reviews.id')
            ->limit($limit)
            ->get([
                'product_reviews.id', 'product_reviews.display_name', 'product_reviews.rating',
                'product_reviews.body', 'product_reviews.published_at',
                'products.name as product_name', 'products.slug as product_slug',
            ])->map(fn ($review): array => [
                'id' => (int) $review->id,
                'display_name' => $review->display_name,
                'rating' => (int) $review->rating,
                'body' => $review->body,
                'product_name' => $review->product_name,
                'product_slug' => $review->product_slug,
                'published_at' => $review->published_at?->toIso8601String(),
            ]);
    }

    public function faqs()
    {
        if (! Schema::hasTable('faq_entries')) {
            return collect();
        }

        return FaqEntry::where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'question', 'answer']);
    }
}
