<?php

namespace Tests\Feature;

use App\Services\GuestOrderAccess;
use App\Services\PublicOrderStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function guestOrder(string $number): int
    {
        $category = DB::table('categories')->insertGetId([
            'name' => 'Guest Game '.$number, 'slug' => 'guest-game-'.$number,
        ]);
        $product = DB::table('products')->insertGetId([
            'category_id' => $category, 'name' => 'Guest Game', 'slug' => 'guest-product-'.$number,
        ]);
        $package = DB::table('product_packages')->insertGetId([
            'product_id' => $product, 'code' => $number, 'name' => '10 Unit',
        ]);

        return DB::table('orders')->insertGetId([
            'order_number' => $number,
            'guest_email' => 'guest@example.test',
            'product_id' => $product,
            'product_package_id' => $package,
            'customer_input' => json_encode(['id' => '123']),
            'snapshot' => json_encode(['product' => 'Guest Game']),
            'cost_idr' => 9000,
            'margin_idr' => 1000,
            'total_idr' => 10000,
            'idempotency_key' => $number,
        ]);
    }

    public function test_guest_requires_correct_order_number_and_secret_code(): void
    {
        $first = $this->guestOrder('GUEST-100');
        $second = $this->guestOrder('GUEST-200');
        $code = app(GuestOrderAccess::class)->issue($first);

        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(hash('sha256', $code), DB::table('guest_order_tokens')
            ->where('order_id', $first)->value('token_hash'));
        $this->get('/orders/guest/GUEST-100')->assertNotFound();

        $this->post('/orders/check', [
            'order_number' => 'GUEST-200', 'access_code' => $code,
        ])->assertSessionHasErrors('order_number');
        $this->post('/orders/check', [
            'order_number' => 'GUEST-100', 'access_code' => $code,
        ])->assertRedirect(route('guest.orders.show', 'GUEST-100'));

        $this->get('/orders/guest/GUEST-100')->assertOk();
        $this->get('/orders/guest/GUEST-200')->assertNotFound();

        $this->assertFalse(app(GuestOrderAccess::class)->matches($second, $code));
    }

    public function test_blocked_order_is_failed_on_public_customer_surfaces(): void
    {
        $orderId = $this->guestOrder('LFTRACK-STATUS-100');
        DB::table('orders')->where('id', $orderId)->update([
            'status' => 'BLOCKED',
            'paid_at' => now(),
        ]);
        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'event_type' => 'fulfillment.updated',
            'correlation_id' => 'guest-status-'.bin2hex(random_bytes(8)),
            'from_status' => 'PROCESSING',
            'to_status' => 'BLOCKED',
            'created_at' => now(),
        ]);

        $tracking = $this->post('/orders/track/search', ['query' => 'LFTRACK-STATUS-100'])
            ->assertOk()
            ->assertJsonPath('order.fulfillmentStatus', 'failed')
            ->assertJsonPath('order.paymentStatus', 'paid')
            ->assertJsonPath('order.wasPaid', true);

        $guestPage = $this->withSession(['guest_order_id' => $orderId])
            ->get('/orders/guest/LFTRACK-STATUS-100')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.status', 'FAILED')
                ->where('events.0.from_status', 'PROCESSING')
                ->where('events.0.to_status', 'FAILED')
                ->where('events.0.label', 'Gagal')
                ->missing('events.0.event_type'));
        $guestEvents = $this->get('/orders/guest/LFTRACK-STATUS-100/events')
            ->assertOk()
            ->assertJsonPath('order.status', 'FAILED')
            ->assertJsonPath('events.0.to_status', 'FAILED')
            ->assertJsonPath('events.0.label', 'Gagal')
            ->assertJsonMissingPath('events.0.event_type');
        $paymentPage = $this->get('/payment?invoice=LFTRACK-STATUS-100')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('order.status', 'FAILED'));

        foreach ([$tracking, $guestPage, $guestEvents, $paymentPage] as $response) {
            $content = $response->getContent();
            $this->assertStringNotContainsString('blocked', strtolower($content));
        }
    }

    public function test_public_status_has_only_four_labels_and_unpaid_expiry_note(): void
    {
        $statuses = app(PublicOrderStatusService::class);
        $this->assertSame('Terkirim', $statuses->label('SUCCESS'));
        $this->assertSame('Diproses', $statuses->label('PAID'));
        $this->assertSame('Menunggu Pembayaran', $statuses->label('PENDING_PAYMENT'));
        foreach (['FAILED', 'BLOCKED', 'EXPIRED', 'CANCELLED'] as $code) {
            $this->assertSame('FAILED', $statuses->value($code));
            $this->assertSame('failed', $statuses->slug($code));
            $this->assertSame('Gagal', $statuses->label($code));
        }
        $this->assertSame('Kedaluwarsa', $statuses->note('EXPIRED', false));
        $this->assertSame('Kedaluwarsa', $statuses->note('CANCELLED', false));
        $this->assertNull($statuses->note('FAILED', true));
        $this->assertNull($statuses->note('EXPIRED', true));
    }

    public function test_public_timeline_names_payment_success_and_collapses_repeat_heartbeats(): void
    {
        $statuses = app(PublicOrderStatusService::class);
        $this->assertSame('Pembayaran Berhasil', $statuses->eventLabel('PAYMENT_VERIFIED', 'PAID'));
        $this->assertSame('Menunggu hasil dari penyedia', $statuses->eventLabel('FULFILLMENT_PENDING', 'PROCESSING'));
        $this->assertSame('Diproses', $statuses->eventLabel('FULFILLMENT_QUEUED', 'PROCESSING'));

        $orderId = $this->guestOrder('LFTRACK-TIMELINE-100');
        DB::table('orders')->where('id', $orderId)->update([
            'status' => 'SUCCESS', 'paid_at' => '2026-11-03 06:47:00',
        ]);
        $rows = [];
        foreach ([
            ['ORDER_CREATED', null, 'PENDING_PAYMENT', '2026-11-03 06:45:46'],
            ['PAYMENT_VERIFIED', 'PENDING_PAYMENT', 'PAID', '2026-11-03 06:47:00'],
            ['FULFILLMENT_QUEUED', 'PAID', 'PROCESSING', '2026-11-03 06:47:01'],
            ['FULFILLMENT_PENDING', 'PROCESSING', 'PROCESSING', '2026-11-03 06:47:02'],
            ['FULFILLMENT_UNKNOWN', 'PROCESSING', 'PROCESSING', '2026-11-03 06:47:20'],
            ['FULFILLMENT_UNKNOWN', 'PROCESSING', 'PROCESSING', '2026-11-03 06:47:40'],
            ['FULFILLMENT_SUCCEEDED', 'PROCESSING', 'SUCCESS', '2026-11-03 06:48:02'],
        ] as $i => [$type, $from, $to, $at]) {
            $rows[] = [
                'order_id' => $orderId, 'event_type' => $type,
                'correlation_id' => 'timeline-'.$i, 'from_status' => $from,
                'to_status' => $to, 'created_at' => $at,
            ];
        }
        DB::table('order_events')->insert($rows);

        $expected = [
            'Menunggu Pembayaran', 'Pembayaran Berhasil', 'Diproses',
            'Menunggu hasil dari penyedia', 'Diproses', 'Terkirim',
        ];
        $tracking = $this->postJson('/orders/track/search', ['query' => 'LFTRACK-TIMELINE-100'])
            ->assertOk();
        $this->assertSame($expected, array_column($tracking->json('order.events'), 'label'));

        $guest = $this->withSession(['guest_order_id' => $orderId])
            ->get('/orders/guest/LFTRACK-TIMELINE-100/events')->assertOk();
        $this->assertSame($expected, array_column($guest->json('events'), 'label'));
    }

    public function test_public_tracking_and_guest_events_emit_utc_iso_timestamps_for_wib_rendering(): void
    {
        $orderId = $this->guestOrder('LFTRACK-UTC-100');
        DB::table('orders')->where('id', $orderId)->update([
            'status' => 'EXPIRED', 'paid_at' => null,
            'created_at' => '2026-11-03 06:45:46',
            'updated_at' => '2026-11-03 07:19:17',
        ]);
        DB::table('order_events')->insert([
            'order_id' => $orderId, 'event_type' => 'PAYMENT_EXPIRED',
            'correlation_id' => 'test-iso-timestamp',
            'from_status' => 'PENDING_PAYMENT', 'to_status' => 'EXPIRED',
            'created_at' => '2026-11-03 06:48:02',
        ]);
        $isoCreated = '2026-11-03T06:45:46+00:00';
        $isoEvent = '2026-11-03T06:48:02+00:00';

        $this->postJson('/orders/track/search', ['query' => 'LFTRACK-UTC-100'])
            ->assertOk()
            ->assertJsonPath('order.fulfillmentStatus', 'failed')
            ->assertJsonPath('order.statusNote', 'Kedaluwarsa')
            ->assertJsonPath('order.createdAt', $isoCreated)
            ->assertJsonPath('order.events.0.createdAt', $isoEvent);

        $feed = $this->get('/orders/track/feed')->assertOk()->json('transactions');
        $this->assertNotContains('LFTRA••••-100', array_column($feed, 'maskedReferenceId'));
        $this->withSession(['guest_order_id' => $orderId])->get('/orders/guest/LFTRACK-UTC-100/events')
            ->assertOk()
            ->assertJsonPath('order.created_at', $isoCreated)
            ->assertJsonPath('order.status_note', 'Kedaluwarsa')
            ->assertJsonPath('events.0.created_at', $isoEvent);
        $this->get('/orders/guest/LFTRACK-UTC-100')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.status', 'FAILED')
                ->where('order.status_note', 'Kedaluwarsa')
                ->where('order.created_at', $isoCreated));
    }

    public function test_public_recent_transactions_include_only_paid_orders_on_feed_and_index(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $unpaidNumber = 'LF-FEED-UNPAID-'.$suffix.'U';
        $paidNumber = 'LF-FEED-PAID-'.$suffix.'P';
        $unpaidId = $this->guestOrder($unpaidNumber);
        $paidId = $this->guestOrder($paidNumber);
        DB::table('orders')->where('id', $unpaidId)->update(['status' => 'EXPIRED', 'paid_at' => null]);
        DB::table('orders')->where('id', $paidId)->update(['status' => 'FAILED', 'paid_at' => now()]);
        $mask = fn (string $number): string => substr($number, 0, 5).'••••'.substr($number, -4);

        $feed = $this->get('/orders/track/feed')->assertOk()->json('transactions');
        $this->assertContains($mask($paidNumber), array_column($feed, 'maskedReferenceId'));
        $this->assertNotContains($mask($unpaidNumber), array_column($feed, 'maskedReferenceId'));

        $index = $this->get('/orders/check')->assertOk();
        $index->assertInertia(fn ($page) => $page
            ->where('transactions', fn ($rows): bool => collect($rows)->contains('maskedReferenceId', $mask($paidNumber))
                && collect($rows)->doesntContain('maskedReferenceId', $mask($unpaidNumber))));
    }

    public function test_reissuing_code_invalidates_old_code(): void
    {
        $id = $this->guestOrder('GUEST-300');
        $access = app(GuestOrderAccess::class);
        $old = $access->issue($id);
        $new = $access->issue($id);

        $this->assertFalse($access->matches($id, $old));
        $this->assertTrue($access->matches($id, $new));
    }
}
