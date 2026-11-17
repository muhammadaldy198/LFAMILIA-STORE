<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Leaderboard publik: semua order SUCCESS (member dan tamu) dihitung per email pembeli dan
 * hanya ditampilkan sebagai alias samaran. Email acak dan nominal sangat besar dipakai supaya
 * sisa data test lain tidak bisa menggeser entri test ini keluar dari 20 besar.
 */
class LeaderboardTest extends TestCase
{
    use DatabaseTransactions;

    /** Lebih besar dari nominal fixture mana pun di suite, supaya entri test selalu masuk 20 besar. */
    private const BIG_TOTAL = 900_000_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    private function uniqueEmail(string $prefix): string
    {
        return $prefix.bin2hex(random_bytes(6)).'@leaderboard-'.bin2hex(random_bytes(4)).'.test';
    }

    private function member(string $email, bool $optIn = false): User
    {
        $phone = '08'.random_int(1000000000, 9999999999);
        $user = User::create([
            'name' => 'Nama Akun Rahasia',
            'email' => $email,
            'phone' => $phone,
            'phone_normalized' => CreateNewUser::normalizePhone($phone),
            'password' => Hash::make('leaderboard-password-123'),
        ]);
        $user->forceFill(['leaderboard_opt_in' => $optIn])->save();

        return $user;
    }

    private function order(?User $user, ?string $guestEmail, int $total, string $status = 'SUCCESS', ?Carbon $createdAt = null): int
    {
        $suffix = bin2hex(random_bytes(6));
        $category = DB::table('categories')->insertGetId(['name' => 'Game '.$suffix, 'slug' => 'lb-game-'.$suffix]);
        $product = DB::table('products')->insertGetId([
            'category_id' => $category, 'name' => 'Game '.$suffix, 'slug' => 'lb-product-'.$suffix,
        ]);
        $package = DB::table('product_packages')->insertGetId([
            'product_id' => $product, 'code' => 'LB-'.$suffix, 'name' => '10 Unit',
        ]);
        $createdAt ??= now();

        return DB::table('orders')->insertGetId([
            'order_number' => 'LB-'.$suffix,
            'user_id' => $user?->id,
            'guest_email' => $guestEmail,
            'product_id' => $product,
            'product_package_id' => $package,
            'status' => $status,
            'customer_input' => json_encode(['id' => '123']),
            'snapshot' => json_encode(['product' => 'Game']),
            'cost_idr' => max(1, $total - 1000),
            'margin_idr' => 1000,
            'total_idr' => $total,
            'idempotency_key' => 'LB-'.$suffix,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @return array<int, array{rank: int, name: string, order_count: int, total_spent: int}> */
    private function entries(?string $period = null): array
    {
        $url = $period === null ? '/leaderboard' : '/leaderboard?period='.$period;
        $page = $this->get($url)->assertOk()->viewData('page');

        return json_decode(json_encode($page['props']['entries']), true);
    }

    private function entryWithTotal(array $entries, int $total): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['total_spent'] === $total) {
                return $entry;
            }
        }

        return null;
    }

    private function totalSeed(): int
    {
        return self::BIG_TOTAL + random_int(1, 1_000_000) * 1000;
    }

    public function test_guest_success_order_is_ranked_with_alias_from_guest_email(): void
    {
        $email = $this->uniqueEmail('gst');
        $total = $this->totalSeed();
        $this->order(null, $email, $total);

        $entry = $this->entryWithTotal($this->entries('all'), $total);

        $this->assertNotNull($entry);
        $this->assertSame('gst*********', $entry['name']);
        $this->assertSame(1, $entry['order_count']);
        $this->assertSame(['rank', 'name', 'order_count', 'total_spent'], array_keys($entry));
    }

    public function test_short_local_part_is_used_as_is_with_nine_stars(): void
    {
        $total = $this->totalSeed();
        $this->order(null, 'ab@leaderboard-'.bin2hex(random_bytes(6)).'.test', $total);

        $entry = $this->entryWithTotal($this->entries('all'), $total);

        $this->assertNotNull($entry);
        $this->assertSame('ab*********', $entry['name']);
    }

    public function test_member_order_is_ranked_by_account_email_even_without_opt_in(): void
    {
        $member = $this->member($this->uniqueEmail('mbr'), optIn: false);
        $total = $this->totalSeed();
        $this->order($member, null, $total);

        $entry = $this->entryWithTotal($this->entries('all'), $total);

        $this->assertNotNull($entry);
        $this->assertSame('mbr*********', $entry['name']);
        $this->assertStringNotContainsString('Rahasia', $entry['name']);
        $this->assertStringNotContainsString('Nama', $entry['name']);
    }

    public function test_guest_and_member_orders_with_same_email_merge_case_insensitively(): void
    {
        $email = $this->uniqueEmail('mix');
        $member = $this->member($email);
        $memberTotal = $this->totalSeed();
        $guestTotal = 7000;
        $this->order($member, null, $memberTotal);
        $this->order(null, strtoupper($email), $guestTotal);

        $entries = $this->entries('all');
        $merged = $this->entryWithTotal($entries, $memberTotal + $guestTotal);

        $this->assertNotNull($merged);
        $this->assertSame(2, $merged['order_count']);
        $this->assertSame('mix*********', $merged['name']);
        $this->assertNull($this->entryWithTotal($entries, $memberTotal));
    }

    public function test_non_success_and_emailless_orders_are_not_counted(): void
    {
        $email = $this->uniqueEmail('sts');
        $successTotal = $this->totalSeed();
        $this->order(null, $email, $successTotal);
        $this->order(null, $email, 5000, 'FAILED');
        $this->order(null, $email, 6000, 'PENDING_PAYMENT');
        $noEmailTotal = $this->totalSeed() + 1;
        $emptyEmailTotal = $this->totalSeed() + 2;
        $this->order(null, null, $noEmailTotal);
        $this->order(null, '', $emptyEmailTotal);

        $entries = $this->entries('all');
        $entry = $this->entryWithTotal($entries, $successTotal);

        $this->assertNotNull($entry);
        $this->assertSame(1, $entry['order_count']);
        $this->assertSame('sts*********', $entry['name']);
        $this->assertNull($this->entryWithTotal($entries, $noEmailTotal));
        $this->assertNull($this->entryWithTotal($entries, $emptyEmailTotal));
        foreach ($entries as $listed) {
            $this->assertNotSame('*********', $listed['name']);
        }
    }

    public function test_month_period_excludes_last_month_while_all_period_includes_it(): void
    {
        $email = $this->uniqueEmail('per');
        $thisMonthTotal = $this->totalSeed();
        $lastMonthTotal = 9000;
        $this->order(null, $email, $thisMonthTotal);
        $this->order(null, $email, $lastMonthTotal, 'SUCCESS', now()->startOfMonth()->subDays(2));

        $month = $this->entryWithTotal($this->entries('month'), $thisMonthTotal);
        $defaultPeriod = $this->entryWithTotal($this->entries(), $thisMonthTotal);
        $all = $this->entryWithTotal($this->entries('all'), $thisMonthTotal + $lastMonthTotal);

        $this->assertNotNull($month);
        $this->assertSame(1, $month['order_count']);
        $this->assertNotNull($defaultPeriod);
        $this->assertNotNull($all);
        $this->assertSame(2, $all['order_count']);
        $this->get('/leaderboard?period=all')->assertInertia(fn (Assert $page) => $page
            ->component('Content/Leaderboard')->where('period', 'all'));
        $this->get('/leaderboard')->assertInertia(fn (Assert $page) => $page->where('period', 'month'));
    }

    public function test_ranking_follows_total_spent_and_never_exposes_full_email(): void
    {
        $base = self::BIG_TOTAL * 10 + random_int(1, 1_000_000) * 1000;
        $emails = [
            'small' => $this->uniqueEmail('sml'),
            'large' => $this->uniqueEmail('lrg'),
            'medium' => $this->uniqueEmail('med'),
        ];
        $this->order(null, $emails['small'], $base + 1000);
        $this->order(null, $emails['large'], $base + 3000);
        $member = $this->member($emails['medium']);
        $this->order($member, null, $base + 2000);

        $entries = $this->entries('all');
        $large = $this->entryWithTotal($entries, $base + 3000);
        $medium = $this->entryWithTotal($entries, $base + 2000);
        $small = $this->entryWithTotal($entries, $base + 1000);

        $this->assertNotNull($large);
        $this->assertNotNull($medium);
        $this->assertNotNull($small);
        $this->assertLessThan($medium['rank'], $large['rank']);
        $this->assertLessThan($small['rank'], $medium['rank']);
        $this->assertSame(range(1, count($entries)), array_column($entries, 'rank'));
        $this->assertLessThanOrEqual(20, count($entries));

        $totals = array_column($entries, 'total_spent');
        $sorted = $totals;
        rsort($sorted);
        $this->assertSame($sorted, $totals);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('@', $entry['name']);
            $this->assertNotContains($entry['name'], $emails);
        }
        $body = $this->get('/leaderboard?period=all')->getContent();
        foreach ($emails as $email) {
            $this->assertStringNotContainsString($email, $body);
        }
    }

    public function test_profile_no_longer_sends_or_accepts_leaderboard_opt_in(): void
    {
        $member = $this->member($this->uniqueEmail('prf'), optIn: false);

        $this->actingAs($member, 'web')->get('/account/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Profile')
                ->has('customer.email')
                ->missing('customer.leaderboard_opt_in')
                ->missing('auth.user.leaderboard_opt_in'));

        $this->put('/account/profile', [
            'name' => 'Nama Baru',
            'email' => $member->email,
            'phone' => $member->phone,
            'leaderboard_opt_in' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $member->fresh();
        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertFalse($fresh->leaderboard_opt_in);
    }
}
