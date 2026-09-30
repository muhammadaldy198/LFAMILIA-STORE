<?php

namespace App\Http\Controllers;

use App\Models\NewsArticle;
use App\Services\StorefrontContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PublicContentController
{
    public function news(StorefrontContentService $content): Response
    {
        return Inertia::render('Content/News', ['articles' => $content->news(60)]);
    }

    public function article(string $slug): Response
    {
        $article = NewsArticle::where('slug', $slug)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->firstOrFail();

        return Inertia::render('Content/Article', ['article' => [
            ...$article->only('slug', 'title', 'summary', 'body', 'source_label'),
            'published_at' => $article->published_at?->toIso8601String(),
            'cover_url' => $article->getFirstMediaUrl('image'),
        ]]);
    }

    public function faq(StorefrontContentService $content): Response
    {
        return Inertia::render('Content/Faq', ['faqs' => $content->faqs()]);
    }

    public function contact(): Response
    {
        return Inertia::render('Content/Contact');
    }

    public function terms(): Response
    {
        return $this->legal('terms');
    }

    public function refund(): Response
    {
        return $this->legal('refund');
    }

    public function privacy(): Response
    {
        return $this->legal('privacy');
    }

    public function tools(): Response
    {
        return Inertia::render('Content/Tool', ['tool' => 'win-rate']);
    }

    public function legal(string $key): Response
    {
        abort_unless(in_array($key, ['terms', 'refund', 'privacy'], true), 404);
        $page = DB::table('content_pages')->where('key', $key)->where('is_active', true)->first();
        abort_unless($page, 404);

        return Inertia::render('Content/Legal', ['page' => $page]);
    }

    public function leaderboard(Request $request): Response
    {
        $period = $request->query('period') === 'all' ? 'all' : 'month';
        $query = DB::table('orders')
            ->whereNotNull('user_id')->where('status', 'SUCCESS')
            ->when($period === 'month', fn ($q) => $q->where('created_at', '>=', now()->startOfMonth()))
            ->groupBy('user_id')
            ->orderByDesc(DB::raw('SUM(total_idr)'))
            ->limit(50)
            ->get([
                'user_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total_idr) as total_spent'),
            ])->values()->map(fn ($row, int $index): array => [
                'rank' => $index + 1,
                'name' => 'Pelanggan #'.str_pad((string) $row->user_id, 4, '0', STR_PAD_LEFT),
                'order_count' => (int) $row->order_count,
                'total_spent' => (int) $row->total_spent,
            ]);

        return Inertia::render('Content/Leaderboard', ['entries' => $query, 'period' => $period]);
    }

    public function tool(string $tool): Response
    {
        abort_unless(in_array($tool, ['win-rate', 'zodiac', 'magic-wheel'], true), 404);

        return Inertia::render('Content/Tool', ['tool' => $tool]);
    }

    public function promo(): Response
    {
        $now = now();
        $vouchers = DB::table('vouchers')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByDesc('id')->limit(30)
            ->get(['code', 'discount_type', 'discount_value', 'minimum_total_idr', 'ends_at']);

        return Inertia::render('Content/Promo', ['vouchers' => $vouchers]);
    }
}
