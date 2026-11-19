<?php

namespace Tests\Feature;

use App\Services\CheckoutPricing;
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

    /**
     * Paket yang benar-benar bisa dibeli (pola fixture CheckoutTest): mode
     * PERCENT dengan sell_price_idr NULL — seperti mayoritas katalog toko —
     * modal Digiflazz Rp10.000 + margin 10% → harga efektif Rp11.000.
     */
    private function buyablePackage(): int
    {
        $suffix = bin2hex(random_bytes(6));
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $product = DB::table('products')->insertGetId([
            'category_id' => DB::table('categories')->where('slug', 'game')->value('id'),
            'name' => 'Game '.$suffix, 'slug' => 'syn-product-'.$suffix, 'is_active' => true,
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10,
        ]);
        $package = DB::table('product_packages')->insertGetId([
            'product_id' => $product, 'code' => 'SYN-'.$suffix, 'name' => 'Paket '.$suffix,
            'is_active' => true, 'pricing_mode' => 'PERCENT', 'margin_percent' => 10,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package,
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'SYN-SKU-'.$suffix,
            'cost_idr' => 10_000, 'max_price_idr' => 12_000, 'priority' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($package);

        return $package;
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
        $package = $this->buyablePackage();
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
        $package = $this->buyablePackage();
        $this->paidOrder('LFSYN260101-REAL00009Z', $package, 75_000);

        $feed = $this->get('/orders/track/feed')->assertOk()->json('transactions');
        $synthetic = array_values(array_filter(
            $feed,
            fn (array $item): bool => $item['maskedReferenceId'] !== $this->mask('LFSYN260101-REAL00009Z'),
        ));

        $this->assertNotEmpty($synthetic);
        // Paket fixture bermode PERCENT dengan sell_price_idr NULL (seperti
        // mayoritas katalog toko): generator wajib tetap mengisinya lewat
        // harga efektif CheckoutPricing, bukan kolom harga mentah.
        $this->assertNull(DB::table('product_packages')->where('id', $package)->value('sell_price_idr'));
        $quotes = app(CheckoutPricing::class)->forPackages(
            DB::table('product_packages')->where('is_active', true)->pluck('id')->all(),
        );
        $livePrices = array_map(fn (array $quote): int => (int) $quote['subtotal_idr'], $quotes);
        $this->assertContains(11_000, $livePrices);
        foreach ($synthetic as $item) {
            // Keputusan pemilik 19 Nov 2026: entri sintetis selalu "Terkirim"
            // (tidak ada lagi "Diproses" karangan) dan hanya dari paket
            // berharga wajar — tidak ada pesanan mahal karangan.
            $this->assertSame('success', $item['status']);
            $this->assertContains($item['total'], $livePrices);
            $this->assertLessThanOrEqual(FakeOrderFeed::MODEST_PRICE_CAP_IDR, $item['total']);
        }
    }

    public function test_feed_without_active_catalog_stays_pure_real(): void
    {
        $package = $this->buyablePackage();
        $this->paidOrder('LFSYN260101-REAL00007Y', $package, 75_000);
        DB::table('product_packages')->update(['is_active' => false]);

        $feed = $this->get('/orders/track/feed')->assertOk()->json('transactions');

        $this->assertSame([$this->mask('LFSYN260101-REAL00007Y')], array_column($feed, 'maskedReferenceId'));
    }

    public function test_fake_order_feed_is_deterministic_within_a_tick(): void
    {
        $this->buyablePackage();
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
        $this->buyablePackage();
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
        $package = $this->buyablePackage();
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
        $this->buyablePackage();

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
        $package = $this->buyablePackage();
        $this->paidOrder('LFSYN260101-BOARD0002B', $package, 123_000, 'onlybuyer@example.test');
        DB::table('product_packages')->update(['is_active' => false]);

        $entries = $this->board();

        $this->assertCount(1, $entries);
        $this->assertSame('onl*********', $entries[0]['name']);
        $this->assertSame(123_000, $entries[0]['total_spent']);
    }

    public function test_leaderboard_skips_fill_when_cap_is_below_the_cheapest_package(): void
    {
        $package = $this->buyablePackage();
        // Total asli Rp11.500 → pagar sintetis 0.92 × 11.500 = Rp10.580, di
        // bawah paket termurah (Rp11.000): tidak ada pembeli sintetis yang
        // masuk akal, jadi papan dibiarkan berisi pembeli asli saja.
        $this->paidOrder('LFSYN260101-BOARD0003C', $package, 11_500, 'smallbuyer@example.test');

        $entries = $this->board();

        $this->assertCount(1, $entries);
        $this->assertSame(11_500, $entries[0]['total_spent']);
    }

    public function test_fake_leaderboard_is_deterministic_and_respects_the_real_top_cap(): void
    {
        $this->buyablePackage();
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

    public function test_fake_leaderboard_totals_spread_below_the_cap_instead_of_clustering_on_it(): void
    {
        $this->buyablePackage();
        $now = CarbonImmutable::parse('2026-11-19 13:00:00', 'UTC');
        // Cerminan papan produksi 19 Nov 2026: total asli teratas Rp183.931
        // membuat hampir semua entri sintetis terpangkas ke pagar yang sama
        // (Rp169.216) — papan tampil seragam. Regresi: total wajib tersebar.
        $entries = FakeLeaderboard::entries(19, 'month', $now, 183_931);

        $this->assertCount(19, $entries);
        $totals = array_column($entries, 'total_spent');
        foreach ($entries as $entry) {
            $this->assertLessThan(183_931, $entry['total_spent']);
            $this->assertLessThanOrEqual(169_216, $entry['total_spent']);
            $this->assertGreaterThan(0, $entry['order_count']);
        }
        $this->assertGreaterThanOrEqual(5, count(array_unique($totals)));
        $this->assertLessThanOrEqual(3, max(array_count_values($totals)));
    }

    public function test_fake_leaderboard_on_an_empty_board_stays_within_modest_totals(): void
    {
        $this->buyablePackage();
        $now = CarbonImmutable::parse('2026-11-19 13:00:00', 'UTC');

        $entries = FakeLeaderboard::entries(20, 'month', $now, null);

        $this->assertCount(20, $entries);
        foreach ($entries as $entry) {
            $this->assertLessThanOrEqual(500_000, $entry['total_spent']);
            $this->assertGreaterThan(0, $entry['total_spent']);
            $this->assertGreaterThan(0, $entry['order_count']);
        }
    }
}
