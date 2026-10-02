<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSupportRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(array $permissions = ['support.manage'], string $role = 'ADMIN'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Support Admin',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => Hash::make('support-restoration-password'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function user(string $name = 'Support Customer'): User
    {
        return User::create([
            'name' => $name,
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('customer-support-password'),
            'membership_tier_code' => 'BASIC',
        ]);
    }

    public function test_support_workspace_has_filters_summary_pagination_and_selected_conversation(): void
    {
        $this->login();
        $user = $this->user();

        $target = SupportTicket::create([
            'user_id' => $user->id,
            'order_id' => null,
            'subject' => 'Refund saldo belum diterima',
            'message' => 'Mohon periksa refund saya.',
            'kind' => 'COMPLAINT',
        ]);
        DB::table('support_ticket_messages')->insert([
            'support_ticket_id' => $target->id,
            'sender_type' => 'CUSTOMER',
            'user_id' => $user->id,
            'admin_user_id' => null,
            'message' => 'Saya kirim detail tambahan.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SupportTicket::create([
            'user_id' => $this->user('Other Customer')->id,
            'order_id' => null,
            'subject' => 'Pertanyaan umum',
            'message' => 'Halo.',
            'kind' => 'GENERAL',
        ]);

        $this->get('/admin/support?q=Refund&status=OPEN&kind=COMPLAINT&source=ACCOUNT&per_page=10&ticket='.$target->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Support')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $target->id)
                ->where('tickets.data.0.kind', 'COMPLAINT')
                ->where('tickets.data.0.source', 'ACCOUNT')
                ->where('tickets.data.0.message_count', 2)
                ->where('selectedTicket.id', $target->id)
                ->where('selectedTicket.messages.0.message', 'Saya kirim detail tambahan.')
                ->where('summary.total', 2)
                ->where('summary.open', 2)
                ->has('kinds')
                ->has('quickReplies'));
    }

    public function test_admin_reply_updates_category_handler_status_conversation_and_audit(): void
    {
        Queue::fake();
        $admin = $this->login();
        $user = $this->user();
        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Pembayaran tertahan',
            'message' => 'Pembayaran belum berubah.',
            'kind' => 'GENERAL',
        ]);

        $this->put('/admin/support/'.$ticket->id, [
            'status' => 'IN_PROGRESS',
            'kind' => 'PAYMENT',
            'reply' => 'Pembayaran sedang kami periksa.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => 'IN_PROGRESS',
            'kind' => 'PAYMENT',
            'handled_by_admin_id' => $admin->id,
        ]);
        $this->assertNotNull(DB::table('support_tickets')->where('id', $ticket->id)->value('handled_at'));
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'ADMIN',
            'admin_user_id' => $admin->id,
            'message' => 'Pembayaran sedang kami periksa.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'support.updated',
            'target_type' => 'support_ticket',
            'target_id' => (string) $ticket->id,
        ]);
    }

    public function test_admin_can_change_status_without_sending_duplicate_message(): void
    {
        $this->login();
        $ticket = SupportTicket::create([
            'user_id' => $this->user()->id,
            'subject' => 'Sudah selesai',
            'message' => 'Tolong tutup tiket.',
            'kind' => 'GENERAL',
        ]);

        $this->put('/admin/support/'.$ticket->id, [
            'status' => 'RESOLVED',
            'kind' => 'GENERAL',
            'reply' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => 'RESOLVED',
        ]);
        $this->assertSame(0, DB::table('support_ticket_messages')
            ->where('support_ticket_id', $ticket->id)->count());
    }

    public function test_quick_replies_trim_blank_templates_and_are_audited(): void
    {
        $this->login();

        $this->put('/admin/support/quick-replies', [
            'replies' => [
                '  Mohon sertakan nomor invoice.  ',
                '',
                'Terima kasih, laporan Anda sedang kami periksa.',
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $stored = json_decode(
            (string) DB::table('system_settings')->where('key', 'support.quick_replies')->value('value'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame([
            'Mohon sertakan nomor invoice.',
            'Terima kasih, laporan Anda sedang kami periksa.',
        ], $stored);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'support.quick_replies.updated',
            'target_id' => 'support.quick_replies',
        ]);
    }

    public function test_customer_reply_reopens_ticket_and_notifies_admin(): void
    {
        Queue::fake();
        $user = $this->user();
        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Update komplain',
            'message' => 'Pesan awal.',
            'kind' => 'COMPLAINT',
        ]);
        DB::table('support_tickets')->where('id', $ticket->id)->update(['status' => 'RESOLVED']);

        $this->actingAs($user);
        $this->post('/account/tickets/'.$ticket->id.'/messages', [
            'message' => 'Masalahnya muncul lagi.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'CUSTOMER',
            'message' => 'Masalahnya muncul lagi.',
        ]);
        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'support.ticket.replied',
            'target_type' => 'support_ticket',
            'target_id' => (string) $ticket->id,
        ]);
    }

    public function test_support_routes_remain_permission_gated(): void
    {
        $this->login(['dashboard.view']);

        $this->get('/admin/support')->assertForbidden();
        $this->put('/admin/support/quick-replies', ['replies' => []])->assertForbidden();
        $this->put('/admin/support/999999', [
            'status' => 'OPEN',
            'kind' => 'GENERAL',
            'reply' => null,
        ])->assertForbidden();
    }
}
