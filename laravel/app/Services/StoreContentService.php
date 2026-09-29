<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreContentService
{
    /** @return array<string,mixed> */
    public function storefront(bool $public = true): array
    {
        $row = DB::table('store_settings')->where('id', 1)->first();

        $settings = [
            'storeName' => (string) ($row?->store_name ?? 'LFAMILIA STORE'),
            'storeShortName' => (string) ($row?->store_short_name ?? 'LF'),
            'tagline' => (string) ($row?->tagline ?? 'Top up game dan produk digital'),
            'logoUrl' => $row?->logo_url,
            'announcement' => $row?->announcement,
            'bannerEnabled' => (bool) ($row?->banner_enabled ?? true),
            'bannerEyebrow' => (string) ($row?->banner_eyebrow ?? 'LFAMILIA STORE'),
            'bannerTitle' => (string) ($row?->banner_title ?? 'Top up lebih praktis'),
            'bannerHighlight' => (string) ($row?->banner_highlight ?? 'dan aman'),
            'bannerDescription' => (string) ($row?->banner_description ?? 'Pilih produk dan metode pembayaran yang tersedia.'),
            'bannerImageUrl' => $row?->banner_image_url,
            'bannerCtaLabel' => (string) ($row?->banner_cta_label ?? 'Lihat produk'),
            'bannerCtaHref' => $this->safeNavigation((string) ($row?->banner_cta_href ?? '/#produk'), '/#produk'),
            'supportWhatsapp' => $row?->support_whatsapp,
            'supportEmail' => $row?->support_email,
            'instagramUrl' => $this->safeHttp($row?->instagram_url),
            'discordUrl' => $this->safeHttp($row?->discord_url),
            'supportHours' => (string) ($row?->support_hours ?? 'Setiap hari'),
            'supportWidgetEnabled' => (bool) ($row?->support_widget_enabled ?? true),
        ];

        if ($public) {
            foreach ([
                'storeName', 'storeShortName', 'tagline', 'announcement', 'bannerEyebrow',
                'bannerTitle', 'bannerHighlight', 'bannerDescription', 'bannerCtaLabel', 'supportHours',
            ] as $key) {
                if (is_string($settings[$key] ?? null)) {
                    $settings[$key] = $this->neutralize($settings[$key]);
                }
            }
        }

        return $settings;
    }

    /** @param array<string,mixed> $input */
    public function saveStorefront(array $input, string $role): void
    {
        $current = $this->storefront(false);
        if ($role === 'staff') {
            foreach ([
                'storeName', 'storeShortName', 'tagline', 'logoUrl',
                'supportWhatsapp', 'supportEmail', 'instagramUrl', 'discordUrl',
            ] as $protected) {
                $input[$protected] = $current[$protected] ?? null;
            }
        }

        DB::table('store_settings')->updateOrInsert(
            ['id' => 1],
            [
                'store_name' => $input['storeName'],
                'store_short_name' => $input['storeShortName'],
                'tagline' => $input['tagline'],
                'logo_url' => $input['logoUrl'] ?: null,
                'announcement' => $input['announcement'] ?: null,
                'banner_enabled' => $input['bannerEnabled'] ? 1 : 0,
                'banner_eyebrow' => $input['bannerEyebrow'],
                'banner_title' => $input['bannerTitle'],
                'banner_highlight' => $input['bannerHighlight'],
                'banner_description' => $input['bannerDescription'],
                'banner_image_url' => $input['bannerImageUrl'] ?: null,
                'banner_cta_label' => $input['bannerCtaLabel'],
                'banner_cta_href' => $input['bannerCtaHref'],
                'support_whatsapp' => $input['supportWhatsapp'] ?: null,
                'support_email' => $input['supportEmail'] ?: null,
                'instagram_url' => $input['instagramUrl'] ?: null,
                'discord_url' => $input['discordUrl'] ?: null,
                'support_hours' => $input['supportHours'],
                'support_widget_enabled' => $input['supportWidgetEnabled'] ? 1 : 0,
                'updated_at' => now(),
            ],
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(bool $includeInactive = false): array
    {
        $query = DB::table('product_categories');
        if (!$includeInactive) {
            $query->where('is_active', 1);
        }

        return $query->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'icon' => (string) $row->icon,
                'isActive' => (bool) $row->is_active,
                'sortOrder' => (int) $row->sort_order,
            ])->all();
    }

    /** @param array<string,mixed> $input */
    public function saveCategory(array $input): int
    {
        $values = [
            'slug' => $input['slug'],
            'name' => $input['name'],
            'icon' => $input['icon'],
            'is_active' => $input['isActive'] ? 1 : 0,
            'sort_order' => (int) $input['sortOrder'],
            'updated_at' => now(),
        ];

        if (!empty($input['id'])) {
            DB::table('product_categories')->where('id', $input['id'])->update($values);
            return (int) $input['id'];
        }

        return (int) DB::table('product_categories')->insertGetId([
            ...$values,
            'created_at' => now(),
        ]);
    }

    public function deleteCategory(int $id): void
    {
        $row = DB::table('product_categories')->where('id', $id)->first(['slug']);
        if (!$row) {
            return;
        }
        if (DB::table('products')->where('category', $row->slug)->exists()) {
            throw new RuntimeException('Kategori masih digunakan produk dan tidak dapat dihapus.');
        }
        DB::table('product_categories')->where('id', $id)->delete();
    }

    /** @return array<int,array<string,mixed>> */
    public function faqs(bool $includeInactive = false, bool $public = false): array
    {
        $query = DB::table('faq_entries');
        if (!$includeInactive) {
            $query->where('is_active', 1);
        }

        return $query->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'question' => $public ? $this->neutralize((string) $row->question) : (string) $row->question,
                'answer' => $public ? $this->neutralize((string) $row->answer) : (string) $row->answer,
                'isActive' => (bool) $row->is_active,
                'sortOrder' => (int) $row->sort_order,
            ])->all();
    }

    /** @param array<string,mixed> $input */
    public function saveFaq(array $input): int
    {
        $values = [
            'question' => $input['question'],
            'answer' => $input['answer'],
            'is_active' => $input['isActive'] ? 1 : 0,
            'sort_order' => (int) $input['sortOrder'],
            'updated_at' => now(),
        ];

        if (!empty($input['id'])) {
            DB::table('faq_entries')->where('id', $input['id'])->update($values);
            return (int) $input['id'];
        }

        return (int) DB::table('faq_entries')->insertGetId([
            ...$values, 'created_at' => now(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function banners(bool $includeInactive = false): array
    {
        $query = DB::table('home_banners');
        if (!$includeInactive) {
            $query->where('is_active', 1);
        }

        return $query->orderBy('sort_order')->orderBy('id')->get()
            ->map(function ($row) {
                $images = $this->decodeBannerImages((string) $row->image_url);
                return [
                    'id' => (int) $row->id,
                    'title' => (string) $row->title,
                    'subtitle' => (string) $row->subtitle,
                    ...$images,
                    'ctaLabel' => (string) $row->cta_label,
                    'ctaHref' => $this->safeNavigation((string) $row->cta_href, '/#produk'),
                    'isActive' => (bool) $row->is_active,
                    'sortOrder' => (int) $row->sort_order,
                ];
            })->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function popups(bool $includeInactive = false): array
    {
        $query = DB::table('site_popups');
        if (!$includeInactive) {
            $query->where('is_active', 1);
        }

        return $query->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'body' => (string) $row->body,
                'primaryLabel' => $row->primary_label,
                'primaryHref' => $this->safeNavigation($row->primary_href),
                'secondaryLabel' => $row->secondary_label,
                'secondaryHref' => $this->safeNavigation($row->secondary_href),
                'dismissDays' => (int) $row->dismiss_days,
                'isActive' => (bool) $row->is_active,
                'sortOrder' => (int) $row->sort_order,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function news(bool $includeDrafts = false): array
    {
        $query = DB::table('news_articles');
        if (!$includeDrafts) {
            $query->where('is_published', 1)
                ->where(function ($scheduled) {
                    $scheduled->whereNull('published_at')->orWhere('published_at', '<=', now());
                });
        }

        return $query->orderBy('sort_order')
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'title' => (string) $row->title,
                'summary' => (string) $row->summary,
                'body' => (string) $row->body,
                'coverUrl' => $row->cover_url,
                'isPublished' => (bool) $row->is_published,
                'publishedAt' => $row->published_at,
                'sortOrder' => (int) $row->sort_order,
            ])->all();
    }

    /** @param array<string,mixed> $input */
    public function saveContent(string $kind, array $input): int
    {
        if ($kind === 'banner') {
            $values = [
                'title' => $input['title'],
                'subtitle' => $input['subtitle'],
                'image_url' => $this->encodeBannerImages(
                    $input['imageUrl'],
                    $input['mobileImageUrl'] ?? '',
                    (bool) $input['showDesktop'],
                    (bool) $input['showMobile'],
                ),
                'cta_label' => $input['ctaLabel'],
                'cta_href' => $input['ctaHref'],
                'is_active' => $input['isActive'] ? 1 : 0,
                'sort_order' => (int) $input['sortOrder'],
                'updated_at' => now(),
            ];
            return $this->saveRow('home_banners', $values, $input['id'] ?? null);
        }

        if ($kind === 'popup') {
            $values = [
                'title' => $input['title'],
                'body' => $input['body'],
                'primary_label' => $input['primaryLabel'] ?: null,
                'primary_href' => $input['primaryHref'] ?: null,
                'secondary_label' => $input['secondaryLabel'] ?: null,
                'secondary_href' => $input['secondaryHref'] ?: null,
                'dismiss_days' => (int) $input['dismissDays'],
                'is_active' => $input['isActive'] ? 1 : 0,
                'sort_order' => (int) $input['sortOrder'],
                'updated_at' => now(),
            ];
            return $this->saveRow('site_popups', $values, $input['id'] ?? null);
        }

        if ($kind === 'news') {
            $values = [
                'slug' => $input['slug'],
                'title' => $input['title'],
                'summary' => $input['summary'],
                'body' => $input['body'],
                'cover_url' => $input['coverUrl'] ?: null,
                'is_published' => $input['isPublished'] ? 1 : 0,
                'published_at' => $input['publishedAt'] ?: null,
                'sort_order' => (int) $input['sortOrder'],
                'updated_at' => now(),
            ];
            return $this->saveRow('news_articles', $values, $input['id'] ?? null);
        }

        throw new RuntimeException('Jenis konten tidak valid.');
    }

    public function deleteContent(string $kind, int $id): void
    {
        $table = match ($kind) {
            'banner' => 'home_banners',
            'popup' => 'site_popups',
            'news' => 'news_articles',
            default => throw new RuntimeException('Jenis konten tidak valid.'),
        };
        DB::table($table)->where('id', $id)->delete();
    }

    private function saveRow(string $table, array $values, mixed $id): int
    {
        if ($id) {
            DB::table($table)->where('id', (int) $id)->update($values);
            return (int) $id;
        }

        return (int) DB::table($table)->insertGetId([
            ...$values, 'created_at' => now(),
        ]);
    }

    private function encodeBannerImages(string $desktop, string $mobile, bool $showDesktop, bool $showMobile): string
    {
        $mobile = trim($mobile);
        if (($mobile === '' || $mobile === $desktop) && $showDesktop && $showMobile) {
            return $desktop;
        }

        return json_encode([
            'desktop' => $desktop,
            'mobile' => $mobile !== '' ? $mobile : null,
            'showDesktop' => $showDesktop,
            'showMobile' => $showMobile,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private function decodeBannerImages(string $value): array
    {
        if (!str_starts_with(trim($value), '{')) {
            return [
                'imageUrl' => $value,
                'mobileImageUrl' => null,
                'showDesktop' => true,
                'showMobile' => true,
            ];
        }

        $parsed = json_decode($value, true);
        if (!is_array($parsed) || !is_string($parsed['desktop'] ?? null)) {
            return [
                'imageUrl' => $value,
                'mobileImageUrl' => null,
                'showDesktop' => true,
                'showMobile' => true,
            ];
        }

        return [
            'imageUrl' => $parsed['desktop'],
            'mobileImageUrl' => is_string($parsed['mobile'] ?? null) ? $parsed['mobile'] : null,
            'showDesktop' => ($parsed['showDesktop'] ?? true) !== false,
            'showMobile' => ($parsed['showMobile'] ?? true) !== false,
        ];
    }

    public function validMedia(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        if (preg_match('#^/(?:api/media/media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)|(?:brand|products)/[a-z0-9][a-z0-9._/-]*\.(?:jpg|jpeg|png|webp|gif))$#i', $value)) {
            return true;
        }

        $parts = parse_url($value);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && empty($parts['user'])
            && empty($parts['pass']);
    }

    public function validNavigation(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (str_starts_with($value, '#')) {
            return (bool) preg_match('/^#[A-Za-z0-9_-]+$/', $value);
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return true;
        }

        $parts = parse_url($value);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host']);
    }

    private function safeNavigation(?string $value, string $fallback = ''): string
    {
        $value = trim((string) $value);
        return $this->validNavigation($value) ? $value : $fallback;
    }

    private function safeHttp(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $parts = parse_url($value);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            ? $value : null;
    }

    private function neutralize(string $value): string
    {
        return preg_replace('/\b(?:doku|midtrans|digiflazz|melostore|kokinpay)\b/i', 'sistem LFAMILIA', $value) ?? $value;
    }
}
