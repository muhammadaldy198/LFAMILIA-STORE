<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\ReviewBank;
use App\Support\ReviewWall;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewWallTest extends TestCase
{
    private const BASE = [
        'mobile-legends' => 2431,
        'free-fire' => 1186,
        'pubg-mobile' => 842,
        'magic-chess-go-go' => 517,
        'valorant' => 463,
        'call-of-duty-mobile' => 358,
        'blood-strike' => 174,
        'delta-force-garena' => 132,
    ];

    private function anchor(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-18 00:00:00', 'Asia/Jakarta');
    }

    private function wall(string $slug, CarbonImmutable $now): ?array
    {
        return ReviewWall::forProduct(new Product(['slug' => $slug]), $now);
    }

    public function test_wall_is_null_for_product_without_bank(): void
    {
        $this->assertNull($this->wall('roblox', $this->anchor()->addDays(3)));
    }

    public function test_wall_totals_equal_locked_base_at_anchor_and_average_rounds_to_4_9(): void
    {
        foreach (self::BASE as $slug => $base) {
            $wall = $this->wall($slug, $this->anchor());
            $this->assertNotNull($wall, $slug);
            $this->assertSame($base, $wall['total'], $slug);
            $this->assertSame($base, array_sum($wall['distribution']), $slug);
            $average = $wall['points'] / $wall['total'];
            $this->assertSame(4.9, round($average, 1), $slug.' average '.$average);
        }
    }

    public function test_wall_is_deterministic_for_the_same_moment(): void
    {
        $now = $this->anchor()->addDays(13)->setTime(19, 42);
        $first = $this->wall('mobile-legends', $now);
        $second = $this->wall('mobile-legends', $now);
        $this->assertSame($first, $second);
    }

    public function test_wall_total_never_decreases_and_grows_over_time(): void
    {
        $slug = 'mobile-legends';
        $atAnchor = $this->wall($slug, $this->anchor())['total'];
        $afterDay = $this->wall($slug, $this->anchor()->addDay())['total'];
        $afterMonth = $this->wall($slug, $this->anchor()->addDays(30))['total'];
        $this->assertSame(self::BASE[$slug], $atAnchor);
        $this->assertGreaterThanOrEqual($atAnchor, $afterDay);
        $this->assertGreaterThan($afterDay, $afterMonth);
    }

    public function test_newest_entry_is_fresh_during_busy_hours_and_never_in_the_future(): void
    {
        // [momen, batas umur entri terbaru] — jam sepi 00-06 peluangnya dibagi 6,
        // jadi entri terbaru wajar berumur beberapa jam; jam ramai harus segar.
        $moments = [
            [$this->anchor()->addDays(2)->setTime(19, 37), 90],
            [$this->anchor()->addDays(5)->setTime(12, 15), 90],
            [$this->anchor()->addDays(9)->setTime(21, 3), 90],
            [$this->anchor()->addDays(4)->setTime(3, 12), 240],
        ];
        foreach ($moments as [$now, $maxAge]) {
            $wall = $this->wall('mobile-legends', $now);
            $this->assertNotEmpty($wall['entries']);
            $newest = CarbonImmutable::parse($wall['entries'][0]['published_at']);
            $this->assertLessThanOrEqual($now->timestamp, $newest->timestamp, 'entry in the future at '.$now);
            $ageMinutes = ($now->timestamp - $newest->timestamp) / 60;
            $this->assertLessThanOrEqual($maxAge, $ageMinutes, 'newest entry too old at '.$now.': '.$ageMinutes.' min');
        }
    }

    public function test_entries_shape_masking_and_rating_range(): void
    {
        $wall = $this->wall('free-fire', $this->anchor()->addDays(6)->setTime(20, 5));
        $this->assertLessThanOrEqual(6, count($wall['entries']));
        foreach ($wall['entries'] as $entry) {
            $this->assertMatchesRegularExpression('/^[A-Za-z]{3}\*{9}$/', $entry['display_name']);
            $this->assertGreaterThanOrEqual(1, $entry['rating']);
            $this->assertLessThanOrEqual(5, $entry['rating']);
            $this->assertFalse($entry['verified_purchase']);
            $this->assertNotSame('', $entry['body']);
        }
    }

    public function test_entries_never_repeat_a_body_within_one_render(): void
    {
        $moments = [
            $this->anchor()->addDays(1)->setTime(20, 5),
            $this->anchor()->addDays(3)->setTime(12, 40),
            $this->anchor()->addDays(7)->setTime(21, 15),
            $this->anchor()->addDays(2)->setTime(2, 30),
        ];
        foreach (array_keys(self::BASE) as $slug) {
            foreach ($moments as $now) {
                $wall = $this->wall($slug, $now);
                $bodies = array_column($wall['entries'], 'body');
                $this->assertSame(
                    count($bodies),
                    count(array_unique($bodies)),
                    "duplicate review body for {$slug} at {$now}: ".implode(' | ', $bodies),
                );
                $pairs = array_map(
                    fn (array $entry): string => $entry['display_name'].'#'.$entry['body'],
                    $wall['entries'],
                );
                $this->assertSame(count($pairs), count(array_unique($pairs)), "duplicate entry for {$slug} at {$now}");
            }
        }
    }

    public function test_bodies_vary_across_renders(): void
    {
        $seen = [];
        for ($day = 1; $day <= 4; $day++) {
            foreach ([9, 14, 20] as $hour) {
                $wall = $this->wall('mobile-legends', $this->anchor()->addDays($day)->setTime($hour, 25));
                foreach ($wall['entries'] as $entry) {
                    $seen[$entry['body']] = true;
                }
            }
        }
        // Bank ML berisi 16 teks; hash yang sehat menyebar ke sebagian besar bank
        // dalam 12 render (hash lemah menumpuk di 1-2 teks yang sama berulang).
        $this->assertGreaterThanOrEqual(8, count($seen), 'bodies barely vary: '.implode(' | ', array_keys($seen)));
    }

    public function test_bank_contains_no_forbidden_words(): void
    {
        $forbidden = ['pending', 'lama', 'lambat', 'gagal', 'error', 'ribet', 'susah', 'kecewa',
            'mahal', 'nunggu', 'antri', 'bermasalah', 'komplain', 'refund', 'jelek', 'zonk',
            'kendala', 'scam', 'tipu'];
        $texts = ReviewBank::NEUTRAL_BODIES;
        foreach (ReviewBank::BANKS as $bank) {
            $texts = array_merge($texts, $bank['bodies'], $bank['titles']);
        }
        foreach ($texts as $text) {
            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, mb_strtolower($text), 'Forbidden "'.$word.'" in: '.$text);
            }
        }
    }

    public function test_each_game_bank_uses_its_own_vocabulary(): void
    {
        $keywords = [
            'mobile-legends' => 'diamond',
            'free-fire' => 'diamond',
            'pubg-mobile' => 'UC',
            'valorant' => 'Points',
            'call-of-duty-mobile' => 'CP',
            'magic-chess-go-go' => 'diamond',
            'blood-strike' => 'Golds',
            'delta-force-garena' => 'Coins',
        ];
        foreach ($keywords as $slug => $keyword) {
            $joined = implode(' ', ReviewBank::BANKS[$slug]['bodies']);
            $this->assertStringContainsString($keyword, $joined, $slug);
        }
    }

    public function test_product_page_merges_wall_with_real_reviews(): void
    {
        // Bekukan waktu di bingkai aplikasi (now() lokal), bukan CarbonImmutable
        // zona lain: published_at tersimpan naif dan dibandingkan naif juga.
        $now = Carbon::now()->setDate(2026, 11, 21)->setTime(20, 0, 0);
        Carbon::setTestNow($now);

        $product = $this->mlProduct();
        $orderId = $this->guestOrder($product, 'LF261121-ASLI'.strtoupper(bin2hex(random_bytes(3))));
        ProductReview::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'display_name' => 'Pembeli Asli',
            'rating' => 5,
            'title' => 'Beneran',
            'body' => 'Ulasan asli dari pembeli beneran.',
            'is_active' => true,
            'published_at' => $now,
        ]);

        $wall = $this->wall('mobile-legends', $now->toImmutable());
        $expectedTotal = $wall['total'] + 1;
        $expectedDistribution = $wall['distribution'];
        $expectedDistribution[5]++;

        $this->get('/catalog/'.$product->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Show')
                ->has('reviews', 5)
                ->where('reviews.0.display_name', 'Pembeli Asli')
                ->where('reviewStats.total', $expectedTotal)
                ->where('reviewStats.distribution.5', $expectedDistribution[5])
                ->where('reviewStats.distribution.4', $expectedDistribution[4])
            );

        Carbon::setTestNow();
    }

    private function mlProduct(): Product
    {
        // Suite ini tidak me-rollback data antar test, jadi produk dibuat
        // idempoten: pakai yang sudah ada bila slug-nya tertinggal dari run lalu.
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::firstOrCreate(
            ['slug' => 'mobile-legends'],
            [
                'category_id' => $category->id,
                'name' => 'Mobile Legends',
                'publisher' => 'Moonton',
                'description' => 'Top up diamond ML.',
                'manual_instructions' => 'INTERNAL ONLY',
                'margin_percent' => 10,
                'fulfillment_mode' => 'AUTO_PROVIDER',
                'is_active' => true,
            ]
        );
        if (! $product->is_active) {
            $product->forceFill(['is_active' => true])->save();
        }
        $product->fields()->firstOrCreate(
            ['field_key' => 'user_id'],
            ['label' => 'User ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]
        );
        $package = $product->packages()->where('is_active', true)->first();
        if ($package === null) {
            $package = $product->packages()->create([
                'code' => 'MLWALL'.strtoupper(bin2hex(random_bytes(2))),
                'name' => '100 Diamonds',
                'group_name' => 'Diamonds',
                'nominal_value' => 100,
                'sort_order' => 0,
                'is_active' => true,
            ]);
            DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
            $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
            DB::table('provider_mappings')->insert([
                'product_package_id' => $package->id,
                'provider_id' => $providerId,
                'external_sku' => 'MLWALL_A',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->seedCheckoutDigiflazzCatalog($package->id);

        return $product;
    }

    private function guestOrder(Product $product, string $number): int
    {
        $packageId = $product->packages()->firstOrFail()->id;

        return DB::table('orders')->insertGetId([
            'order_number' => $number,
            'guest_email' => 'guest@example.test',
            'guest_phone' => '+62 812-3456-7890',
            'guest_phone_normalized' => '6281234567890',
            'product_id' => $product->id,
            'product_package_id' => $packageId,
            'status' => 'SUCCESS',
            'customer_input' => json_encode(['user_id' => '123456']),
            'snapshot' => json_encode(['product' => ['name' => $product->name]]),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'total_idr' => 11000,
            'idempotency_key' => 'idem-'.$number,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
