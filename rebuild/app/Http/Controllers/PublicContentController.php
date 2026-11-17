<?php

namespace App\Http\Controllers;

use App\Models\NewsArticle;
use App\Services\StorefrontContentService;
use App\Support\OptimizedMedia;
use App\Support\SeoMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Inertia\Response;

class PublicContentController
{
    public function news(StorefrontContentService $content): Response
    {
        return SeoMetadata::render('Content/News', ['articles' => $content->news(60)]);
    }

    public function article(string $slug): Response
    {
        $article = NewsArticle::where('slug', $slug)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->firstOrFail();

        return SeoMetadata::render('Content/Article', ['article' => [
            ...$article->only('slug', 'title', 'summary', 'body', 'source_label'),
            'published_at' => $article->published_at?->toIso8601String(),
            'cover_url' => OptimizedMedia::url($article, 'image', 'detail'),
        ]]);
    }

    public function faq(StorefrontContentService $content): Response
    {
        return SeoMetadata::render('Content/Faq', ['faqs' => $content->faqs()]);
    }

    public function contact(): Response
    {
        return SeoMetadata::render('Content/Contact');
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
        return SeoMetadata::render('Content/Tools');
    }

    private function legal(string $key): Response
    {
        abort_unless(in_array($key, ['terms', 'refund', 'privacy'], true), 404);
        $page = DB::table('content_pages')->where('key', $key)->where('is_active', true)->first();
        abort_unless($page, 404);

        return SeoMetadata::render('Content/Legal', ['page' => $page]);
    }

    /**
     * Keputusan pemilik: semua order SUCCESS dihitung, member maupun tamu, dikelompokkan per email
     * pembeli (email akun untuk member, guest_email untuk tamu). Yang tampil hanya alias samaran.
     */
    public function leaderboard(Request $request): Response
    {
        $period = $request->query('period') === 'all' ? 'all' : 'month';
        $emailKey = 'LOWER(COALESCE(users.email, orders.guest_email))';
        $entries = DB::table('orders as orders')
            ->leftJoin('users as users', fn ($join) => $join
                ->on('users.id', '=', 'orders.user_id')
                ->whereNull('users.deleted_at'))
            ->where('orders.status', 'SUCCESS')
            ->when($period === 'month', fn ($q) => $q->where('orders.created_at', '>=', now()->startOfMonth()))
            ->whereRaw("NULLIF({$emailKey}, '') IS NOT NULL")
            ->groupBy(DB::raw($emailKey))
            ->orderByDesc(DB::raw('SUM(orders.total_idr)'))
            ->orderByDesc(DB::raw('COUNT(orders.id)'))
            ->limit(20)
            ->get([
                DB::raw("{$emailKey} as buyer_email"),
                DB::raw('COUNT(orders.id) as order_count'),
                DB::raw('SUM(orders.total_idr) as total_spent'),
            ])->values()->map(fn ($row, int $index): array => [
                'rank' => $index + 1,
                'name' => $this->leaderboardAlias((string) $row->buyer_email),
                'order_count' => (int) $row->order_count,
                'total_spent' => (int) $row->total_spent,
            ]);

        return SeoMetadata::render('Content/Leaderboard', ['entries' => $entries, 'period' => $period]);
    }

    /** Alias samaran sama dengan dinding ulasan: 3 huruf pertama local part + 9 bintang. */
    private function leaderboardAlias(string $email): string
    {
        $localPart = strstr($email, '@', true);

        return mb_substr($localPart === false ? $email : $localPart, 0, 3).'*********';
    }

    public function tool(string $tool): Response
    {
        abort_unless(in_array($tool, ['win-rate', 'zodiac', 'magic-wheel'], true), 404);

        return SeoMetadata::render('Content/Tool', ['tool' => $tool]);
    }

    public function status(): Response
    {
        $databaseOk = true;
        $cacheOk = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $databaseOk = false;
        }
        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $cacheOk = false;
        }

        $paymentOk = $databaseOk && DB::table('payment_channels')
            ->where('is_active', true)->where('supports_order', true)->exists();
        $fulfillmentOk = $databaseOk && (
            DB::table('providers')->where('is_active', true)->exists()
            || DB::table('products')->where('is_active', true)->where('fulfillment_mode', 'MANUAL')->exists()
        );

        $services = [
            [
                'id' => 'catalog',
                'name' => 'Katalog & akun',
                'state' => $databaseOk && $cacheOk ? 'operational' : 'degraded',
                'detail' => $databaseOk && $cacheOk
                    ? 'Katalog, akun pelanggan, dan sesi aplikasi berjalan normal.'
                    : 'Sebagian layanan katalog atau sesi sedang ditinjau.',
            ],
            [
                'id' => 'payment',
                'name' => 'Pembayaran',
                'state' => $paymentOk ? 'operational' : 'degraded',
                'detail' => $paymentOk
                    ? 'Minimal satu channel pembayaran order sedang aktif.'
                    : 'Channel pembayaran otomatis belum siap atau sedang dinonaktifkan.',
            ],
            [
                'id' => 'fulfillment',
                'name' => 'Pemrosesan pesanan',
                'state' => $fulfillmentOk ? 'operational' : 'degraded',
                'detail' => $fulfillmentOk
                    ? 'Pemrosesan produk otomatis/manual tersedia sesuai konfigurasi produk.'
                    : 'Pemrosesan pesanan sedang ditinjau.',
            ],
            [
                'id' => 'support',
                'name' => 'Layanan pelanggan',
                'state' => $databaseOk ? 'operational' : 'degraded',
                'detail' => $databaseOk
                    ? 'Support ticket dan kanal bantuan tersedia.'
                    : 'Status layanan pelanggan sedang ditinjau.',
            ],
        ];

        $keys = ['store.name', 'store.legal_name', 'store.registration_id', 'store.address'];
        $settings = DB::table('system_settings')->whereIn('key', $keys)->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true));

        $legalName = trim((string) ($settings['store.legal_name'] ?? ''));
        if ($legalName === '') {
            $legalName = trim((string) ($settings['store.name'] ?? ''));
        }

        return SeoMetadata::render('Content/Status', [
            'services' => $services,
            'merchant' => [
                'legalName' => $legalName,
                'registrationId' => $settings['store.registration_id'] ?? '',
                'address' => $settings['store.address'] ?? '',
            ],
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    public function promo(): Response
    {
        $now = now();
        $vouchers = DB::table('vouchers')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByDesc('id')->limit(30)
            ->get(['code', 'discount_type', 'discount_value', 'minimum_total_idr', 'ends_at'])
            ->map(function (object $voucher): object {
                $voucher->ends_at = $voucher->ends_at
                    ? Carbon::parse($voucher->ends_at, 'UTC')->toIso8601String() : null;

                return $voucher;
            });

        return SeoMetadata::render('Content/Promo', ['vouchers' => $vouchers]);
    }
}
