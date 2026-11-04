<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Services\AdminNotificationRenderer;
use App\Services\CustomerEmailTemplateRenderer;
use App\Services\EditableMessageTemplateService;
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

    public function test_saved_admin_events_remain_listed_after_notifications_are_pruned(): void
    {
        $this->override('admin.custom.saved_event', '{judul}', '{detail}');
        DB::table('system_settings')->updateOrInsert(['key' => 'store.message_templates'], [
            'value' => json_encode(['notifications' => [
                'custom.legacy_event' => [
                    'title' => '{{default_title}}',
                    'body' => '{{default_body}}',
                ],
            ]], JSON_THROW_ON_ERROR),
            'version' => 1, 'updated_at' => now('UTC'), 'created_at' => now('UTC'),
        ]);

        // Neither event appears in the static registry or notification history.
        $keys = array_column(app(TemplateOverrideService::class)->listing(), 'key');
        $this->assertContains('admin.custom.saved_event', $keys);
        $this->assertContains('admin.custom.legacy_event', $keys);
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

    public function test_customer_email_body_preserves_newlines_and_escapes_html(): void
    {
        $this->override('email.order_paid', 'Pembaruan pesanan', "Paragraf pertama.\n<script>alert(1)</script>");
        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'order_paid', 'Pembayaran diterima', 'Nomor Invoice: LF261104-TEST',
            'customer@example.test', ['invoice' => 'LF261104-TEST', 'total' => 1659]
        );

        $this->assertStringContainsString('Paragraf pertama.<br>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered['html']);
        $this->assertStringNotContainsString('<script>', $rendered['html']);
    }

    public function test_unauthorized_role_cannot_open_edit_or_preview(): void
    {
        $this->admin('ADMIN', ['settings.manage']);
        $payload = [
            'template_key' => 'email.order_paid', 'subject' => '{subjek_bawaan}',
            'body' => '{pesan_bawaan}', 'is_enabled' => true,
        ];
        $this->get('/admin/message-templates')->assertForbidden();
        $this->put('/admin/message-templates', ['emails' => [], 'notifications' => []])->assertForbidden();
        $this->post('/admin/message-templates/test-email')->assertForbidden();
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

    public function test_customer_template_rejects_hardcoded_support_email_and_admin_refund_copy(): void
    {
        $this->admin();
        $this->put('/admin/message-templates/overrides', [
            'template_key' => 'email.order_paid',
            'subject' => 'Info pembayaran', 'body' => 'Hubungi support@lfamilia.com.',
            'is_enabled' => true,
        ])->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('message_template_overrides', ['template_key' => 'email.order_paid']);

        $this->put('/admin/message-templates/overrides', [
            'template_key' => 'email.order_paid',
            'subject' => 'Info untuk {email}', 'body' => 'Customer Service kami siap membantu.',
            'is_enabled' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('message_template_overrides', ['template_key' => 'email.order_paid']);

        $this->put('/admin/message-templates/overrides', [
            'template_key' => 'admin.fulfillment.blocked',
            'subject' => '{judul}', 'body' => 'Proses refund sekarang.',
            'is_enabled' => true,
        ])->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('message_template_overrides', ['template_key' => 'admin.fulfillment.blocked']);
    }

    public function test_legacy_update_cannot_stack_copy_on_existing_new_override(): void
    {
        $this->admin();
        $this->override('email.order_paid', '{subjek_bawaan}', 'Teks baru.');
        $this->put('/admin/message-templates', [
            'emails' => ['order_paid' => [
                'subject' => 'Lama {{default_subject}}',
                'body' => 'Lama {{default_body}}',
            ]],
            'notifications' => [],
        ])->assertSessionHasErrors('emails');

        $this->assertDatabaseHas('message_template_overrides', [
            'template_key' => 'email.order_paid', 'body' => 'Teks baru.',
        ]);
        $this->assertArrayNotHasKey('order_paid',
            app(EditableMessageTemplateService::class)->saved()['emails'] ?? []);
    }

    public function test_template_reset_is_forbidden_without_granular_permission(): void
    {
        $this->override('email.order_paid', 'Subjek', 'Isi');
        $this->admin('ADMIN', ['settings.manage']);
        $this->delete('/admin/message-templates/overrides/email.order_paid')->assertForbidden();
        $this->assertDatabaseHas('message_template_overrides', ['template_key' => 'email.order_paid']);
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

    public function test_legacy_admin_store_name_token_is_editable_and_resolves_dynamically(): void
    {
        $this->admin();
        DB::table('system_settings')->updateOrInsert(['key' => 'store.name'], [
            'value' => json_encode('NAMA TOKO DINAMIS', JSON_THROW_ON_ERROR),
            'version' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('system_settings')->updateOrInsert(['key' => 'store.message_templates'], [
            'value' => json_encode(['notifications' => [
                'fulfillment.blocked' => [
                    'title' => '{{default_title}}',
                    'body' => 'Info dari {{store_name}}: {{default_body}}',
                ],
            ]], JSON_THROW_ON_ERROR),
            'version' => 1, 'updated_at' => now('UTC'), 'created_at' => now('UTC'),
        ]);

        $row = collect(app(TemplateOverrideService::class)->listing())
            ->firstWhere('key', 'admin.fulfillment.blocked');
        $this->assertTrue($row['has_legacy']);
        $this->assertSame('Info dari {nama_toko}: {detail}', $row['body']);
        $this->put('/admin/message-templates/overrides', [
            'template_key' => 'admin.fulfillment.blocked',
            'subject' => '{judul}', 'body' => $row['body'], 'is_enabled' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('fulfillment.blocked',
            app(EditableMessageTemplateService::class)->saved()['notifications']);

        $notification = (object) [
            'event_type' => 'fulfillment.blocked', 'severity' => 'WARNING',
            'title' => 'Butuh pemeriksaan', 'message' => 'Detail insiden',
            'data' => [], 'created_at' => now('UTC'),
        ];
        $copy = app(AdminNotificationRenderer::class)->fromNotification($notification);
        $facts = implode(' ', array_column($copy['fields'], 'value'));
        $this->assertStringContainsString('NAMA TOKO DINAMIS', $facts);
        $this->assertStringContainsString('Detail insiden', $facts);
    }

    public function test_admin_custom_copy_keeps_immutable_original_facts_across_channels(): void
    {
        $this->override('admin.fulfillment.blocked', '{judul}', 'Mohon periksa pembayaran ini.');
        $renderer = app(AdminNotificationRenderer::class);
        $notification = (object) [
            'event_type' => 'fulfillment.blocked', 'severity' => 'ERROR',
            'title' => 'Pembayaran perlu pemeriksaan',
            'message' => "Gateway: Midtrans\nAlasan: Timeout saat verifikasi\nPeriksa gateway sebelum meminta pembeli membayar ulang.",
            'target_type' => 'order', 'target_id' => '1',
            'data' => [], 'created_at' => now('UTC'),
        ];
        $message = $renderer->fromNotification($notification);
        $factText = implode(' | ', array_column($message['fields'], 'value'));
        $this->assertStringContainsString('Midtrans', $factText);
        $this->assertStringContainsString('Timeout saat verifikasi', $factText);
        $this->assertStringContainsString('Periksa gateway sebelum meminta pembeli membayar ulang.', $factText);
        $this->assertStringContainsString('Mohon periksa pembayaran ini.', $factText);
        $this->assertStringContainsString('Midtrans', $renderer->telegram($message)['text']);
        $this->assertStringContainsString('Midtrans', json_encode($renderer->discord($message), JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('Midtrans', $renderer->email($message)['text']);
    }

    public function test_reset_clears_legacy_template_but_preserves_other_events(): void
    {
        $this->admin();
        DB::table('system_settings')->updateOrInsert(['key' => 'store.message_templates'], [
            'value' => json_encode([
                'emails' => [
                    'order_paid' => [
                        'subject' => '[LAMA] {{default_subject}}',
                        'body' => 'LAMA {{default_body}}',
                    ],
                    'ticket_reply' => [
                        'subject' => '{{default_subject}}',
                        'body' => 'PESAN LAIN {{default_body}}',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'version' => 1, 'updated_at' => now('UTC'), 'created_at' => now('UTC'),
        ]);
        $service = app(TemplateOverrideService::class);
        $before = collect($service->listing())->firstWhere('key', 'email.order_paid');
        $this->assertSame('Diubah', $before['status']);
        $this->assertTrue($before['has_legacy']);
        $this->assertSame('[LAMA] {subjek_bawaan}', $before['subject']);

        $this->override('email.order_paid', 'Judul yang sudah diubah', 'Konten yang sudah diubah');
        $this->delete('/admin/message-templates/overrides/email.order_paid')
            ->assertRedirect()->assertSessionHasNoErrors();
        $after = app(EditableMessageTemplateService::class)->saved();
        $this->assertArrayNotHasKey('order_paid', $after['emails']);
        $this->assertArrayHasKey('ticket_reply', $after['emails']);
        $this->assertDatabaseMissing('message_template_overrides', ['template_key' => 'email.order_paid']);
        $this->assertSame('Bawaan', collect($service->listing())->firstWhere('key', 'email.order_paid')['status']);
        $this->assertSame('Pembayaran diterima', $service->apply(
            'email.order_paid', 'Pembayaran diterima', 'Isi bawaan'
        )['subject']);
    }

    public function test_saving_new_override_replaces_legacy_without_affecting_other_templates(): void
    {
        $this->admin();
        DB::table('system_settings')->updateOrInsert(['key' => 'store.message_templates'], [
            'value' => json_encode([
                'emails' => ['order_paid' => [
                    'subject' => 'LAMA {{default_subject}}',
                    'body' => 'LAMA {{default_body}}',
                ]],
                'notifications' => ['fulfillment.blocked' => [
                    'title' => 'REKAN {{default_title}}',
                    'body' => '{{default_body}}',
                ]],
            ], JSON_THROW_ON_ERROR),
            'version' => 1, 'updated_at' => now('UTC'), 'created_at' => now('UTC'),
        ]);
        $this->put('/admin/message-templates/overrides', [
            'template_key' => 'email.order_paid',
            'subject' => 'Baru {invoice}',
            'body' => 'Pembayaran {invoice} sudah diterima.',
            'is_enabled' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $saved = app(EditableMessageTemplateService::class)->saved();
        $this->assertArrayNotHasKey('order_paid', $saved['emails']);
        $this->assertArrayHasKey('fulfillment.blocked', $saved['notifications']);
        $this->assertDatabaseHas('message_template_overrides', ['template_key' => 'email.order_paid']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'templates.override.saved', 'target_id' => 'email.order_paid']);
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
