<?php

namespace Tests\Feature;

use App\Services\GuestOrderAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestSupportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(1 + crc32($this->name()) % 200)]);
    }

    public function test_guest_support_limits_requests_from_the_same_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/support/verify', ['order_number' => 'UNKNOWN', 'access_code' => str_repeat('x', 64)])
                ->assertSessionHasErrors('order_number');
        }
        $this->post('/support/verify', ['order_number' => 'UNKNOWN', 'access_code' => str_repeat('x', 64)])
            ->assertStatus(429);
    }

    private function order(string $number): int
    {
        $category = DB::table('categories')->insertGetId(['name' => $number, 'slug' => strtolower($number)]);
        $product = DB::table('products')->insertGetId(['category_id' => $category, 'name' => $number, 'slug' => strtolower($number)]);
        $package = DB::table('product_packages')->insertGetId(['product_id' => $product, 'code' => $number, 'name' => '10 Unit']);

        return DB::table('orders')->insertGetId([
            'order_number' => $number, 'user_id' => null, 'guest_email' => 'guest@example.test',
            'product_id' => $product, 'product_package_id' => $package,
            'customer_input' => '{}', 'snapshot' => '{}', 'cost_idr' => 9000,
            'margin_idr' => 1000, 'total_idr' => 10000, 'idempotency_key' => $number,
        ]);
    }

    public function test_guest_creates_ticket_reads_admin_reply_and_replies_without_account(): void
    {
        Queue::fake();
        $id = $this->order('GUEST-SUPPORT-1');
        $code = app(GuestOrderAccess::class)->issue($id);
        $this->post('/support', [
            'order_number' => 'GUEST-SUPPORT-1', 'access_code' => $code,
            'subject' => 'Pesanan belum masuk', 'message' => 'Tolong periksa pesanan saya.',
        ])->assertRedirect('/support');
        $ticket = DB::table('support_tickets')->where('order_id', $id)->first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->user_id);
        $this->assertSame('ORDER', $ticket->kind);
        DB::table('support_ticket_messages')->insert([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'ADMIN',
            'message' => 'Sedang kami periksa.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/support')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Guest/Support')->has('tickets', 1)
            ->where('tickets.0.messages.0.message', 'Sedang kami periksa.'));
        $this->post('/support/'.$ticket->id.'/messages', ['message' => 'Terima kasih.'])
            ->assertRedirect('/support');
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id, 'sender_type' => 'CUSTOMER', 'message' => 'Terima kasih.',
        ]);
        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'support.ticket.replied',
            'target_type' => 'support_ticket',
            'target_id' => (string) $ticket->id,
        ]);
        DB::table('support_tickets')->where('id', $ticket->id)->update(['status' => 'CLOSED']);
        $this->post('/support/'.$ticket->id.'/messages', ['message' => 'Balasan terlambat'])->assertStatus(422);
    }

    public function test_wrong_code_and_other_guest_session_cannot_access_ticket(): void
    {
        Queue::fake();
        $id = $this->order('GUEST-SUPPORT-2');
        $other = $this->order('GUEST-SUPPORT-3');
        $ticket = DB::table('support_tickets')->insertGetId([
            'user_id' => null, 'order_id' => $id, 'subject' => 'Rahasia',
            'message' => 'Percakapan pribadi', 'status' => 'OPEN',
        ]);
        app(GuestOrderAccess::class)->issue($id);
        $this->post('/support', [
            'order_number' => 'GUEST-SUPPORT-2', 'access_code' => str_repeat('x', 64),
            'subject' => 'Salah', 'message' => 'Tidak boleh dibuat.',
        ])->assertSessionHasErrors('order_number');
        $this->assertSame(1, DB::table('support_tickets')->where('order_id', $id)->count());
        $this->withSession(['guest_order_id' => $other])->get('/support')
            ->assertOk()->assertInertia(fn ($page) => $page->has('tickets', 0));
        $this->post('/support/'.$ticket.'/messages', ['message' => 'Akses silang'])->assertNotFound();
    }

    public function test_guest_reopens_ticket_history_with_valid_access_code(): void
    {
        $id = $this->order('GUEST-SUPPORT-4');
        $code = app(GuestOrderAccess::class)->issue($id);
        $this->post('/support/verify', ['order_number' => 'GUEST-SUPPORT-4', 'access_code' => $code])
            ->assertRedirect('/support')->assertSessionHas('guest_order_id', $id);
        $this->get('/contact')->assertOk()->assertInertia(fn ($page) => $page->component('Content/Contact'));
    }
}
