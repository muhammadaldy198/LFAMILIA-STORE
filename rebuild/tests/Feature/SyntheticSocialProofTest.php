<?php

namespace Tests\Feature;

use App\Services\PublicOrderStatusService;
use App\Support\FakeLeaderboard;
use App\Support\FakeOrderFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pelengkap sintetis (keputusan pemilik): umpan Cek Pesanan dan leaderboard
 * diisi entri deterministik dari katalog live bila data asli belum mencapai
 * 20. Data asli tidak pernah berubah, tergeser, atau terkalahkan oleh
 * sintetis; tanpa katalog aktif, kedua permukaan kembali murni data asli.
 */
class SyntheticSocialProofTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    /** Paket aktif berharga jual > 0: bahan katalog live untuk generator sintetis. */
    private function pricedPackage(int $price = 50_000): int
    {
        $suffix = bin2hex(random_bytes(6));
        $category = DB::table('categories')->insertGetId(['name' => 'Game '.$suffix, 'slug' => 'syn-game-'.$suffix]);
        $product = DB::table('products')->insertGetId([
            'category_id' => $category, 'name' => 'Game '.$suffix, 'slug' => 'syn-product-'.$suffix, 'is_active' => true,
        ]);

        return DB::table('product_packages')->insertGetId([
            'product_id' => $product, 'code' => 'SYN-'.$suffix, 'name' => 'Paket '.$suffix,
            'is_active' => true, 'sell_price_idr' => $price,
        ]);
    }

    private function paidOrder(string $number, int $packageId, int $total, ?string $guestEmail = null, string $status = 'SUCCESS'): int
    {
        $productId = (int) DB::table('product_packages')->where('id', $packageId)->value('product_id');

        return DB::table('orders')->insertGetId([
            'order_number' => $number,
            'user_id' => null,
            'guest_email' => $guestEmail,
            'product_id' => $productId,
            'product_package_id' => $packageId,
            'status' => $status,
            'customer_input' => json_encode(['id' => '123']),
            'snapshot' => json_encode(['product' => 'Game']),
            'cost_idr' => max(1, $total - 1000),
            'margin_idr' => 1000,
            'total_idr' => $total,
            'paid_at' => now(),
            'idempotency_key' => $number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mask(string $number): string
    {
        return substr($number, 0, 5).'••••'.substr($number, -4);
    }

    public function test_feed_fills_to_twenty_and_keeps_every_real_paid_order(): void
    {
        $package = $this->pricedPackage();
        $numbers = ['LFSYN260101-REAL00001A', 'LFSYN260101-REAL00002B', 'LFSYN260101-REAL00003C'];
        foreach ($numbers as $number) {
            $this->paidOrder($number, $package, 75_000);
        }
        $ordersBefore = DB::table('orders')->count();

        $feed = $this->get('/orders/track/feed')->assertOk()->json('transactions');

        $this->assertCount(20, $feed);
        $maskedIds = array_column($feed, 'maskedReferenceId');
        foreach ($numbers as $number) {
            $this->assertContains($this->mask($number), $maskedIds);
        }
        foreach ($feed as $item) {
            $this->assertSame(
                ['referenceId', 'maskedReferenceId', 'productName', 'packageLabel', 'total', 'status', 'statusNote', 'createdAt'],
                array_keys($item),
            );
            $this->assertNull($item['referenceId']);
            $this->assertArrayNotHasKey('trackingToken', $item);
        }
        $times = array_column($feed, 'createdAt');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertSame($ordersBefore, DB::table('orders')->count());
    }

    public function test_feed_synthetic_entries_use_live_catalog_prices_and_public_statuses(): void
    {
        $package = $this->pricedPackage(50_000);
        $this->paidOrder('LFSYN260101-REAL00009Z', $package, 75_000);

        $feed = $this->get('/orders/track/feed')->assertOk()->json('transactions');
        $synthetic = array_values(array_filter(
            $feed,
            fn (array $item): bool => $item['maskedReferenceId'] !== $this->mask('LFSYN260101-REAL00009Z'),
        ));

        $this->assertNotEmpty($synthetic);
        $livePrices = DB::table('product_packages')
            ->where('is_active', true)->where('sell_price_idr', '>', 0)
            ->pluck('sell_price_idr')->map(fn (mixed $price): int => (int) $price)->all();
        foreach ($synthetic as $item) {
            $this->assertContains($item['status'], ['success', 'processing']);
            $this->assertContains($item['total'], $livePrices);
        }
    }

    public function test_feed_without_active_catalog_stays_pure_real(): void
    {
        $package = $this->pricedPackage();
        $this->paidOrder('LFSYN260101-REAL00007Y', $package, 75_000);
        DB::table('product_packages')->update(['is_active' => false]);

        $feed = $this->get('/orders/track/feed')->assertOk()->json('transactions');

        $this->assertSame([$this->mask('LFSYN260101-REAL00007Y')], array_column($feed, 'maskedReferenceId'));
    }

    public function test_fake_order_feed_is_deterministic_within_a_tick(): void
    {
        $this->pricedPackage();
        $now = CarbonImmutable::parse('2026-11-19 13:00:00', 'UTC');
        $statuses = app(PublicOrderStatusService::class);

        $first = FakeOrderFeed::entries(5, $now, [], $statuses);
        $second = FakeOrderFeed::entries(5, $now, [], $statuses);

        $this->assertCount(5, $first);
        $this->assertSame($first, $second);

        $excluded = FakeOrderFeed::entries(5, $now, [$first[0]['maskedReferenceId']], $statuses);
        $this->assertNotContains($first[0]['maskedReferenceId'], array_column($excluded, 'maskedReferenceId'));
    }

    public function test_fake_order_feed_future_clamp_is_tick_fixed_and_utc(): void
    {
        $this->pricedPackage();
        $statuses = app(PublicOrderStatusService::class);
        // Tick 5983753 (mulai 2026-11-19 22:05:00 UTC), indeks 3: umur 2 menit
        // dengan jitter 153 detik — waktu mentahnya 33 detik melewati awal
        // tick, jadi pagar anti-masa-depan pasti bekerja pada dua $now di bawah.
        $early = CarbonImmutable::parse('2026-11-20 05:05:10', 'Asia/Jakarta');
        $later = CarbonImmutable::parse('2026-11-20 05:05:20', 'Asia/Jakarta');

        $first = FakeOrderFeed::entries(4, $early, [], $statuses);
        $second = FakeOrderFeed::entries(4, $later, [], $statuses);

        $this->assertCount(4, $first);
        $this->assertSame('2026-11-19T22:02:49+00:00', $first[3]['createdAt']);
        $this->assertSame($first, $second);
    }

    /** @return array<int, array{rank: int, name: string, order_count: int, total_spent: int}> */
    private function board(string $period = 'all'): array
    {
        $page = $this->get('/leaderboard?period='.$period)->assertOk()->viewData('page');

        return json_decode(json_encode($page['props']['entries']), true);
    }

    public function test_leaderboard_fill_keeps_real_top_buyer_first_with_exact_total(): void
    {
        $package = $this->pricedPackage();
        $bigTotal = 900_000_000_000;
        $this->paidOrder('LFSYN260101-BOARD0001A', $package, $bigTotal, 'topbuyer@example.test');

        $entries = $this->board();

        $this->assertCount(20, $entries);
        $this->assertSame(range(1, 20), array_column($entries, 'rank'));
        $this->assertSame($bigTotal, $entries[0]['total_spent']);
        $this->assertSame('top*********', $entries[0]['name']);
        foreach (array_slice($entries, 1) as $entry) {
            $this->assertLessThan($bigTotal, $entry['total_spent']);
            $this->assertSame(['rank', 'name', 'order_count', 'total_spent'], array_keys($entry));
        }
        $totals = array_column($entries, 'total_spent');
        $sorted = $totals;
        rsort($sorted);
        $this->assertSame($sorted, $totals);
    }

    public function test_leaderboard_without_any_orders_shows_twenty_synthetic_entries(): void
    {
        $this->pricedPackage();

        $entries = $this->board();

        $this->assertCount(20, $entries);
        $this->assertSame(range(1, 20), array_column($entries, 'rank'));
        $totals = array_column($entries, 'total_spent');
        $sorted = $totals;
        rsort($sorted);
        $this->assertSame($sorted, $totals);
        foreach ($entries as $entry) {
            $this->assertStringEndsWith('*********', $entry['name']);
            $this->assertStringNotContainsString('@', $entry['name']);
            $this->assertGreaterThan(0, $entry['order_count']);
            $this->assertGreaterThan(0, $entry['total_spent']);
        }
    }

    public function test_leaderboard_without_active_catalog_stays_pure_real(): void
    {
        $package = $this->pricedPackage();
        $this->paidOrder('LFSYN260101-BOARD0002B', $package, 123_000, 'onlybuyer@example.test');
        DB::table('product_packages')->update(['is_active' => false]);

        $entries = $this->board();

        $this->assertCount(1, $entries);
        $this->assertSame('onl*********', $entries[0]['name']);
        $this->assertSame(123_000, $entries[0]['total_spent']);
    }

    public function test_fake_leaderboard_is_deterministic_and_respects_the_real_top_cap(): void
    {
        $this->pricedPackage(40_000);
        $now = CarbonImmutable::parse('2026-11-19 13:00:00', 'UTC');

        $first = FakeLeaderboard::entries(5, 'month', $now, 100_000);
        $second = FakeLeaderboard::entries(5, 'month', $now, 100_000);

        $this->assertCount(5, $first);
        $this->assertSame($first, $second);
        foreach ($first as $entry) {
            $this->assertLessThanOrEqual(92_000, $entry['total_spent']);
            $this->assertGreaterThan(0, $entry['total_spent']);
        }
    }
}
