<?php

namespace App\Services;

use App\Models\FaqEntry;
use App\Models\NewsArticle;
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
            'footerDescription' => $settings['store.footer_description']
                ?? 'Top up game dan produk digital dengan alur transaksi yang cepat, aman, dan transparan.',
            'homeNewsTitle' => $settings['store.home_news_title'] ?? 'LFAMILIA NEWS',
            'homeNewsIntro' => $settings['store.home_news_intro'] ?? 'Info gaming, promo, dan update terbaru.',
            'assets' => $assets,
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

    public function faqs()
    {
        if (! Schema::hasTable('faq_entries')) {
            return collect();
        }

        return FaqEntry::where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'question', 'answer']);
    }
}
