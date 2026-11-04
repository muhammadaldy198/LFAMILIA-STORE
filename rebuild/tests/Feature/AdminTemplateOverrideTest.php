<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Services\AdminNotificationRenderer;
use App\Services\CustomerEmailTemplateRenderer;
use App\Services\TemplateOverrideService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminTemplateOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(string $role = 'SUPER_ADMIN', array $permissions = []): void
    {
        $user = $this->createAdmin([
            'name' => 'Template Tester',
            'email' => 'templates.'.bin2hex(random_bytes(5)).'@example.test',
            'password' => Hash::make('test-password'),
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($user, 'admin');
    }

    private function override(string $key, string $subject, string $body, bool $enabled = true): void
    {
        DB::table('message_template_overrides')->insert([
            'template_key' => $key, 'subject' => $subject,
            'body' => $body, 'is_enabled' => $enabled,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function test_customer_registry_drives_list_and_defaults_are_kept_in_code(): void
    {
        $list = app(TemplateOverrideService::class)->listing();
        $emails = array_values(array_filter($list, fn ($row) => $row['group'] === 'email'));
        $this->assertCount(10, $emails);
        $this->assertSame('Bawaan', $emails[0]['status']);
        $this->assertContains('link_bayar', collect($emails)->firstWhere('key', 'email.order_created')['placeholders']);
        $this->assertContains('admin.fulfillment.blocked', array_column($list, 'key'));
        $this->assertNotContains('admin.payment.refund_review', array_column($list, 'key'));
    }

    public function test_override_is_used_by_customer_renderer_and_reset_restores_default(): void
    {
        $renderer = app(CustomerEmailTemplateRenderer::class);
        $before = $renderer->render('order_paid', 'Pembayaran diterima', 'Nomor Invoice: LF261104-TEST', 'customer@example.test', [
            'invoice' => 'LF261104-TEST', 'name' => 'Pelanggan', 'total' => 1659,
        ]);
        $this->override('email.order_paid', 'Info {invoice}', 'Halo {nama}, transaksi {invoice} berhasil.');
        $changed = $renderer->render('order_paid', 'Pembayaran diterima', 'Nomor Invoice: LF261104-TEST', 'customer@example.test', [
            'invoice' => 'LF261104-TEST', 'name' => 'Pelanggan', 'total' => 1659,
        ]);
        $this->assertSame('Info LF261104-TEST', $changed['subject']);
        $this->assertStringContainsString('Halo Pelanggan, transaksi LF261104-TEST berhasil.', $changed['html']);
        $this->assertStringNotContainsString('Halo Pelanggan, transaksi LF261104-TEST berhasil.', $before['html']);

        DB::table('message_template_overrides')->where('template_key', 'email.order_paid')->delete();
        $reset = $renderer->render('order_paid', 'Pembayaran diterima', 'Nomor Invoice: LF261104-TEST', 'customer@example.test', [
            'invoice' => 'LF261104-TEST', 'name' => 'Pelanggan', 'total' => 1659,
        ]);
        $this->assertSame($before['html'], $reset['html']);
    }

    public function test_unauthorized_role_cannot_open_edit_or_preview(): void
    {
        $this->admin('ADMIN', ['settings.manage']);
        $payload = [
            'template_key' => 'email.order_paid', 'subject' => '{subjek_bawaan}',
            'body' => '{pesan_bawaan}', 'is_enabled' => true,
        ];
        $this->get('/admin/message-templates')->assertForbidden();
        $this->put('/admin/message-templates/overrides', $payload)->assertForbidden();
        $this->postJson('/admin/message-templates/preview', $payload)->assertForbidden();
        $this->assertDatabaseMissing('message_template_overrides', ['template_key' => 'email.order_paid']);
    }

    public function test_unknown_placeholder_is_rejected_without_audit_or_storage(): void
    {
        $this->admin();
        $this->put('/admin/message-templates/overrides', [
            'template_key' => 'email.order_paid', 'subject' => 'Halo {data_rahasia}',
            'body' => '{pesan_bawaan}', 'is_enabled' => true,
        ])->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('message_template_overrides', ['template_key' => 'email.order_paid']);
    }

    public function test_disable_email_skips_resend_before_credentials_are_loaded(): void
    {
        $this->override('email.verification', '{subjek_bawaan}', '{pesan_bawaan}', false);
        Http::fake();
        (new SendTransactionalEmailJob(
            'customer@example.test', 'Verifikasi email', 'Verifikasi melalui https://lfamilia.com/verify/example', 'verification'
        ))->handle();
        Http::assertNothingSent();
        $this->assertFalse(app(TemplateOverrideService::class)->enabled('email.verification'));
    }

    public function test_admin_override_changes_all_three_channel_text_without_removing_facts(): void
    {
        $this->override('admin.fulfillment.blocked', 'Periksa {invoice}', "{detail}\nTindak lanjut via panel.");
        $notification = (object) [
            'event_type' => 'fulfillment.blocked', 'severity' => 'ERROR',
            'title' => 'Pengiriman terhenti', 'message' => 'Invoice: LF261104-TEST',
            'target_type' => 'order', 'target_id' => '1',
            'data' => ['invoice' => 'LF261104-TEST'], 'created_at' => now('UTC'),
        ];
        $renderer = app(AdminNotificationRenderer::class);
        $copy = $renderer->fromNotification($notification);
        $this->assertSame('Periksa LF261104-TEST', $copy['title']);
        $this->assertStringContainsString('Periksa LF261104-TEST', $renderer->telegram($copy)['text']);
        $this->assertStringContainsString('Periksa LF261104-TEST', $renderer->discord($copy)['embeds'][0]['title']);
        $this->assertStringContainsString('LF261104-TEST', $renderer->email($copy)['text']);
    }

    public function test_save_and_reset_produce_admin_audit_records(): void
    {
        $this->admin();
        $this->put('/admin/message-templates/overrides', [
            'template_key' => 'email.order_paid', 'subject' => '{subjek_bawaan}',
            'body' => 'Terima kasih {nama}. {pesan_bawaan}', 'is_enabled' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('message_template_overrides', ['template_key' => 'email.order_paid']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'templates.override.saved', 'target_id' => 'email.order_paid']);
        $this->delete('/admin/message-templates/overrides/email.order_paid')->assertRedirect();
        $this->assertDatabaseMissing('message_template_overrides', ['template_key' => 'email.order_paid']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'templates.override.reset', 'target_id' => 'email.order_paid']);
    }

    public function test_preview_renders_real_html_without_sending_mail(): void
    {
        $this->admin();
        Http::fake();
        $this->postJson('/admin/message-templates/preview', [
            'template_key' => 'email.order_created', 'subject' => 'Pesanan {invoice} dibuat',
            'body' => 'Bayar sebelum {batas_waktu}.', 'is_enabled' => true,
        ])->assertOk()->assertJsonPath('subject', 'Pesanan LF261104-CONTOH dibuat')
            ->assertJsonStructure(['html', 'subject']);
        Http::assertNothingSent();
    }
}
