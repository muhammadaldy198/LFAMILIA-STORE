<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\AdminManualOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pesanan yang dibatalkan tidak boleh menyisakan proses pemenuhan menggantung:
 * kolom "Status Proses" wajib "Dibatalkan", tidak perlu perhatian, dan tidak
 * muncul di antrean pemenuhan. (Menggantikan berkas probe yang dulu mengunci
 * perilaku salah.)
 */
class CancelledOrderFulfillmentStatusTest extends TestCase
{
    use DatabaseTransactions;

    private function login(): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Cancel guard', 'email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => bcrypt('cancel-guard-only'), 'role' => 'SUPER_ADMIN',
            'permissions' => [], 'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function order(AdminUser $admin, string $destination = 'TARGET-CANCEL'): int
    {
        return app(AdminManualOrderService::class)->create([
            'customer_name' => 'Pelanggan Cancel', 'phone' => '081234567890',
            'email' => 'cancel@example.test', 'product_name' => 'Produk',
            'package_name' => 'Paket', 'destination' => $destination,
            'total_idr' => 15000, 'note' => 'catatan', 'payment_received' => true,
            'idempotency_key' => (string) Str::uuid(),
        ], (int) $admin->id);
    }

    private function row(int $orderId): array
    {
        $row = [];
        $this->get('/admin/orders')->assertOk()->assertInertia(function (Assert $page) use ($orderId, &$row): void {
            foreach ($page->toArray()['props']['orders']['data'] ?? [] as $candidate) {
                if ((int) $candidate['id'] === $orderId) {
                    $row = $candidate;
                }
            }
        });
        $this->assertNotEmpty($row, 'Pesanan tidak muncul di daftar pesanan admin.');

        return $row;
    }

    public function test_cancelling_a_blocked_order_closes_its_fulfillment_attempt(): void
    {
        $admin = $this->login();
        Queue::fake();
        $orderId = $this->order($admin);
        $number = (string) DB::table('orders')->where('id', $orderId)->value('order_number');
        DB::table('fulfillment_attempts')->where('order_id', $orderId)->update([
            'status' => 'BLOCKED', 'last_error' => 'Semua SKU yang diizinkan telah gagal. Hubungi superadmin.',
        ]);
        DB::table('orders')->where('id', $orderId)->update(['status' => 'BLOCKED']);
        $this->assertTrue($this->row($orderId)['needs_attention']);

        $this->withSession(['admin.password_confirmed_at' => time()])
            ->post('/admin/orders/'.$orderId.'/cancel', ['confirmed' => true, 'reason_code' => 'stok_habis'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $this->assertSame('CANCELLED', $attempt->status);
        $this->assertSame('Pesanan dibatalkan: Stok habis', $attempt->last_error);
        $this->assertNotNull($attempt->completed_at);

        $event = DB::table('order_events')->where('order_id', $orderId)
            ->where('event_type', 'FULFILLMENT_CLOSED_BY_CANCELLATION')->first();
        $this->assertNotNull($event, 'Peristiwa penutupan proses tidak dicatat.');
        $this->assertSame('CANCELLED', $event->to_status);
        $this->assertSame([(int) $attempt->id], json_decode((string) $event->metadata, true)['attempt_ids']);

        $row = $this->row($orderId);
        $this->assertSame('CANCELLED', $row['status']);
        $this->assertSame('CANCELLED', $row['fulfillment_status']);
        $this->assertSame('Dibatalkan', $row['fulfillment_label']);
        $this->assertFalse($row['needs_attention']);

        $this->get('/admin/orders?status=attention')->assertOk()->assertInertia(function (Assert $page) use ($orderId): void {
            foreach ($page->toArray()['props']['orders']['data'] ?? [] as $candidate) {
                $this->assertNotSame($orderId, (int) $candidate['id'], 'Pesanan batal masih muncul di filter perlu perhatian.');
            }
        });

        $this->get('/admin/fulfillment?scope=action&q='.urlencode($number))->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('attempts.data', 0)
        );
    }

    public function test_order_with_a_successful_attempt_cannot_be_cancelled(): void
    {
        $admin = $this->login();
        Queue::fake();
        $orderId = $this->order($admin);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update(['status' => 'SUCCESS']);
        DB::table('orders')->where('id', $orderId)->update(['status' => 'BLOCKED']);

        $this->withSession(['admin.password_confirmed_at' => time()])
            ->post('/admin/orders/'.$orderId.'/cancel', ['confirmed' => true, 'reason_code' => 'stok_habis'])
            ->assertSessionHasErrors('order');

        $this->assertSame('SUCCESS', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $this->assertNull(DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('completed_at'));
        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
    }
}
