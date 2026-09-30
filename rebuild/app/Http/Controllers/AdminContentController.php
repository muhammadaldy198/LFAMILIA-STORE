<?php

namespace App\Http\Controllers;

use App\Models\FaqEntry;
use App\Models\HomeBanner;
use App\Models\NewsArticle;
use App\Models\ProductReview;
use App\Models\SitePopup;
use App\Models\StoreAsset;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminContentController
{
    public function index(): Response
    {
        $settingsKeys = [
            'store.support_widget_enabled', 'store.footer_description',
            'store.home_news_title', 'store.home_news_intro',
            'store.support_whatsapp', 'store.instagram_url', 'store.email',
            'store.discord_url', 'store.support_url', 'store.business_hours',
        ];
        $settings = DB::table('system_settings')->whereIn('key', $settingsKeys)->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true));

        return Inertia::render('Admin/Content', [
            'assets' => StoreAsset::orderBy('id')->get()->map(fn (StoreAsset $asset): array => [
                ...$asset->only('id', 'key', 'target_url', 'is_active'),
                'image_url' => $asset->getFirstMediaUrl('image'),
            ]),
            'banners' => HomeBanner::orderBy('sort_order')->orderBy('id')->get()->map(fn (HomeBanner $banner): array => [
                ...$banner->only(
                    'id', 'title', 'subtitle', 'cta_label', 'cta_href',
                    'show_desktop', 'show_mobile', 'sort_order', 'is_active'
                ),
                'desktop_url' => $banner->getFirstMediaUrl('desktop'),
                'mobile_url' => $banner->getFirstMediaUrl('mobile'),
            ]),
            'popups' => SitePopup::orderBy('sort_order')->orderBy('id')->get(),
            'news' => NewsArticle::orderBy('sort_order')->orderByDesc('id')->get()->map(fn (NewsArticle $article): array => [
                ...$article->only('id', 'slug', 'title', 'summary', 'body', 'source_label', 'sort_order', 'is_active'),
                'published_at' => $article->published_at?->format('Y-m-d\TH:i'),
                'image_url' => $article->getFirstMediaUrl('image'),
            ]),
            'faqs' => FaqEntry::orderBy('sort_order')->orderBy('id')->get(),
            'reviews' => ProductReview::query()
                ->join('products', 'products.id', '=', 'product_reviews.product_id')
                ->orderByDesc('product_reviews.id')
                ->limit(200)
                ->get([
                    'product_reviews.id', 'product_reviews.display_name', 'product_reviews.rating',
                    'product_reviews.body', 'product_reviews.is_active', 'product_reviews.published_at',
                    'products.name as product_name', 'products.slug as product_slug',
                ]),
            'pages' => DB::table('content_pages')->orderBy('key')->get(),
            'settings' => collect($settingsKeys)->mapWithKeys(fn (string $key): array => [$key => $settings[$key] ?? '']),
        ]);
    }

    public function storeBanner(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->bannerData($request);
        $banner = HomeBanner::create($data);
        $audit->record($request, 'content.banner.created', 'home_banner', $banner->id, null, $banner->toArray());

        return back();
    }

    public function updateBanner(Request $request, HomeBanner $banner, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->bannerData($request);
        $before = $banner->toArray();
        $banner->update($data);
        $audit->record($request, 'content.banner.updated', 'home_banner', $banner->id, $before, $banner->toArray());

        return back();
    }

    public function destroyBanner(Request $request, HomeBanner $banner, AdminAuditService $audit): RedirectResponse
    {
        $before = $banner->toArray();
        $id = $banner->id;
        $banner->clearMediaCollection('desktop');
        $banner->clearMediaCollection('mobile');
        $banner->delete();
        $audit->record($request, 'content.banner.deleted', 'home_banner', $id, $before, null);

        return back();
    }

    public function storePopup(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->popupData($request);
        $popup = SitePopup::orderBy('id')->first();

        if ($popup) {
            $before = $popup->toArray();
            $popup->update($data);
            $audit->record($request, 'content.popup.updated', 'site_popup', $popup->id, $before, $popup->toArray());

            return back();
        }

        $popup = SitePopup::create($data);
        $audit->record($request, 'content.popup.created', 'site_popup', $popup->id, null, $popup->toArray());

        return back();
    }

    public function updatePopup(Request $request, SitePopup $popup, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->popupData($request);
        $before = $popup->toArray();
        $popup->update($data);
        $audit->record($request, 'content.popup.updated', 'site_popup', $popup->id, $before, $popup->toArray());

        return back();
    }

    public function destroyPopup(Request $request, SitePopup $popup, AdminAuditService $audit): RedirectResponse
    {
        $before = $popup->toArray();
        $id = $popup->id;
        $popup->delete();
        $audit->record($request, 'content.popup.deleted', 'site_popup', $id, $before, null);

        return back();
    }

    public function storeNews(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->newsData($request);
        $slug = Str::slug($data['slug'] ?: $data['title']);
        if ($slug === '' || NewsArticle::where('slug', $slug)->exists()) {
            return back()->withErrors(['slug' => 'Slug berita tidak valid atau sudah dipakai.']);
        }
        $article = NewsArticle::create([...$data, 'slug' => $slug]);
        $audit->record($request, 'content.news.created', 'news_article', $article->id, null, $article->toArray());

        return back();
    }

    public function updateNews(Request $request, NewsArticle $news, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->newsData($request, $news->id);
        $data['slug'] = Str::slug($data['slug'] ?: $data['title']);
        $before = $news->toArray();
        $news->update($data);
        $audit->record($request, 'content.news.updated', 'news_article', $news->id, $before, $news->toArray());

        return back();
    }

    public function destroyNews(Request $request, NewsArticle $news, AdminAuditService $audit): RedirectResponse
    {
        $before = $news->toArray();
        $id = $news->id;
        $news->clearMediaCollection('image');
        $news->delete();
        $audit->record($request, 'content.news.deleted', 'news_article', $id, $before, null);

        return back();
    }

    public function storeFaq(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->faqData($request);
        $faq = FaqEntry::create($data);
        $audit->record($request, 'content.faq.created', 'faq_entry', $faq->id, null, $faq->toArray());

        return back();
    }

    public function updateFaq(Request $request, FaqEntry $faq, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->faqData($request);
        $before = $faq->toArray();
        $faq->update($data);
        $audit->record($request, 'content.faq.updated', 'faq_entry', $faq->id, $before, $faq->toArray());

        return back();
    }

    public function destroyFaq(Request $request, FaqEntry $faq, AdminAuditService $audit): RedirectResponse
    {
        $before = $faq->toArray();
        $id = $faq->id;
        $faq->delete();
        $audit->record($request, 'content.faq.deleted', 'faq_entry', $id, $before, null);

        return back();
    }

    public function updateReview(Request $request, ProductReview $review, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $before = $review->toArray();
        $review->update($data);
        $audit->record($request, 'content.review.updated', 'product_review', $review->id, $before, $review->toArray());

        return back();
    }

    public function updatePage(Request $request, string $key, AdminAuditService $audit): RedirectResponse
    {
        abort_unless(in_array($key, ['terms', 'refund', 'privacy'], true), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:3000'],
            'body' => ['nullable', 'string', 'max:30000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $before = DB::table('content_pages')->where('key', $key)->first();
        abort_unless($before, 404);
        DB::table('content_pages')->where('key', $key)->update([
            ...$data,
            'updated_by_admin_id' => $request->user('admin')->id,
            'updated_at' => now(),
        ]);
        $audit->record($request, 'content.page.updated', 'content_page', $key, (array) $before, $data);

        return back();
    }

    public function updateSettings(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'support_widget_enabled' => ['required', 'boolean'],
            'footer_description' => ['nullable', 'string', 'max:1000'],
            'home_news_title' => ['nullable', 'string', 'max:255'],
            'home_news_intro' => ['nullable', 'string', 'max:1000'],
            'support_whatsapp' => ['nullable', 'string', 'max:100'],
            'instagram_url' => ['nullable', 'url:http,https', 'max:500'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'discord_url' => ['nullable', 'url:http,https', 'max:500'],
            'support_url' => ['nullable', 'url:http,https', 'max:500'],
            'business_hours' => ['nullable', 'string', 'max:500'],
        ]);
        $map = [
            'store.support_widget_enabled' => (bool) $data['support_widget_enabled'],
            'store.footer_description' => $data['footer_description'] ?? '',
            'store.home_news_title' => $data['home_news_title'] ?? '',
            'store.home_news_intro' => $data['home_news_intro'] ?? '',
            'store.support_whatsapp' => $data['support_whatsapp'] ?? '',
            'store.instagram_url' => $data['instagram_url'] ?? '',
            'store.email' => $data['email'] ?? '',
            'store.discord_url' => $data['discord_url'] ?? '',
            'store.support_url' => $data['support_url'] ?? '',
            'store.business_hours' => $data['business_hours'] ?? '',
        ];
        foreach ($map as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => $key], [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
        $audit->record($request, 'content.settings.updated', 'system_setting', 'storefront', null, $map);

        return back();
    }

    private function bannerData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_href' => ['nullable', 'string', 'max:500', 'regex:/^(\/(?!\/)|https?:\/\/)/i'],
            'show_desktop' => ['required', 'boolean'],
            'show_mobile' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! $data['show_desktop'] && ! $data['show_mobile']) {
            abort(422, 'Pilih minimal satu tampilan banner.');
        }

        return $data;
    }

    private function popupData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'dismiss_days' => ['required', 'integer', 'min:0', 'max:365'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function newsData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('news_articles', 'slug')->ignore($ignoreId)],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string', 'max:30000'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);
    }

    private function faqData(Request $request): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
