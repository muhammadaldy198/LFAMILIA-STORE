<?php

namespace App\Http\Controllers;

use App\Jobs\SendTransactionalEmailJob;
use App\Services\AdminAuditService;
use App\Services\EditableMessageTemplateService;
use App\Services\TemplateOverrideService;
use App\Services\CustomerEmailTemplateRenderer;
use App\Services\AdminNotificationRenderer;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminMessageTemplatesController
{
    public function index(TemplateOverrideService $templates): Response
    {
        return Inertia::render('Admin/MessageTemplates', ['entries' => $templates->listing()]);
    }

    public function saveOverride(Request $request, TemplateOverrideService $templates, AdminAuditService $audit): RedirectResponse
    {
        $input = $request->validate([
            'template_key' => ['required', 'string', 'max:140'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'is_enabled' => ['required', 'boolean'],
        ]);
        $key = $input['template_key'];
        $draft = $templates->validateDraft($key, $input['subject'], $input['body'], (bool) $input['is_enabled']);
        $before = (array) (DB::table('message_template_overrides')->where('template_key', $key)->first() ?? []);
        DB::table('message_template_overrides')->updateOrInsert(
            ['template_key' => $key],
            [...$draft, 'created_at' => $before['created_at'] ?? now('UTC'), 'updated_at' => now('UTC')]
        );
        $audit->record($request, 'templates.override.saved', 'message_template', $key, $before, $draft);

        return back()->with('status', 'Template disimpan.');
    }

    public function resetOverride(Request $request, TemplateOverrideService $templates, AdminAuditService $audit, string $templateKey): RedirectResponse
    {
        abort_unless(array_key_exists($templateKey, $templates->definitions()), 404);
        $before = (array) (DB::table('message_template_overrides')->where('template_key', $templateKey)->first() ?? []);
        DB::table('message_template_overrides')->where('template_key', $templateKey)->delete();
        $audit->record($request, 'templates.override.reset', 'message_template', $templateKey, $before);

        return back()->with('status', 'Template kembali ke bawaan.');
    }

    public function preview(Request $request, TemplateOverrideService $templates): JsonResponse
    {
        $input = $request->validate([
            'template_key' => ['required', 'string', 'max:140'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'is_enabled' => ['required', 'boolean'],
        ]);
        $key = $input['template_key'];
        $draft = $templates->validateDraft($key, $input['subject'], $input['body'], (bool) $input['is_enabled']);
        if (str_starts_with($key, 'email.')) {
            $event = substr($key, 6);
            $invoice = 'LF261104-CONTOH';
            $url = URL::temporarySignedRoute('payment.show', now()->addMinutes(20), ['invoice' => $invoice]);
            $context = [
                'name' => 'Pelanggan Contoh', 'invoice' => $invoice, 'product' => 'Mobile Legends',
                'package' => '5 Diamonds', 'destination' => '123456789 (1234)', 'method' => 'QRIS',
                'subtotal' => 1647, 'fee' => 12, 'total' => 1659,
                'created_at' => now('UTC')->toDateTimeString(),
                'expires_at' => now('UTC')->addMinutes(30)->toDateTimeString(), 'payment_url' => $url,
            ];
            $source = match ($event) {
                'verification' => 'Verifikasi: https://lfamilia.com/verify/example',
                'password_reset' => 'Atur ulang: https://lfamilia.com/reset-password/example',
                'ticket_created' => 'Tiket #42 sudah diterima.',
                'ticket_reply' => 'Tim LFAMILIA membalas tiket #42: Pesan contoh.',
                default => 'Nomor Invoice: '.$invoice,
            };
            $rendered = app(CustomerEmailTemplateRenderer::class)->render(
                $event, 'Informasi pesanan '.$invoice, $source, 'pelanggan@example.test', $context, null, $draft
            );

            return response()->json(['subject' => $rendered['subject'], 'html' => $rendered['html']]);
        }

        $event = substr($key, 6);
        $notification = (object) [
            'event_type' => $event, 'severity' => 'WARNING', 'title' => 'Notifikasi contoh — '.$event,
            'message' => "Invoice: LF261104-CONTOH\nProduk: Mobile Legends\nStatus: Diproses",
            'target_type' => 'order', 'target_id' => '1', 'data' => [
                'invoice' => 'LF261104-CONTOH', 'product' => 'Mobile Legends',
                'action_required' => 'Periksa panel admin.',
            ],
            'created_at' => now('UTC'),
        ];
        $renderer = app(AdminNotificationRenderer::class);
        $rendered = $renderer->email($renderer->fromNotification($notification, $draft));

        return response()->json(['subject' => $rendered['subject'], 'html' => $rendered['html']]);
    }

    public function update(Request $request, AdminAuditService $audit, EditableMessageTemplateService $templates): RedirectResponse
    {
        $validated = $request->validate([
            'emails' => ['required', 'array'],
            'emails.*.subject' => ['nullable', 'string', 'max:255'],
            'emails.*.body' => ['nullable', 'string', 'max:10000'],
            'notifications' => ['present', 'array', 'max:100'],
            'notifications.*.title' => ['nullable', 'string', 'max:255'],
            'notifications.*.body' => ['nullable', 'string', 'max:10000'],
        ]);
        $clean = ['emails' => [], 'notifications' => []];
        foreach (EditableMessageTemplateService::EMAIL_EVENTS as $event => $label) {
            $entry = (array) (($validated['emails'] ?? [])[$event] ?? []);
            $subject = trim((string) ($entry['subject'] ?? ''));
            $body = trim((string) ($entry['body'] ?? ''));
            if ($subject !== '' && ! str_contains($subject, '{{default_subject}}')) {
                throw ValidationException::withMessages(['emails' => 'Subjek harus menyertakan {{default_subject}} agar informasi pesanan tetap ada.']);
            }
            if ($body !== '' && ! str_contains($body, '{{default_body}}')) {
                throw ValidationException::withMessages(['emails' => 'Isi email harus menyertakan {{default_body}} agar informasi penting tidak terhapus.']);
            }
            if ($subject !== '' || $body !== '') {
                $clean['emails'][$event] = ['subject' => $subject, 'body' => $body];
            }
        }
        foreach ((array) ($validated['notifications'] ?? []) as $event => $entry) {
            if (! is_string($event) || ! preg_match('/^[a-zA-Z0-9._-]{1,120}$/', $event) || ! is_array($entry)) {
                throw ValidationException::withMessages(['notifications' => 'Kode notifikasi tidak valid.']);
            }
            $title = trim((string) ($entry['title'] ?? ''));
            $body = trim((string) ($entry['body'] ?? ''));
            if (($title !== '' && ! str_contains($title, '{{default_title}}'))
                || ($body !== '' && ! str_contains($body, '{{default_body}}'))) {
                throw ValidationException::withMessages(['notifications' => 'Template notifikasi harus menyertakan teks bawaan.']);
            }
            if ($title !== '' || $body !== '') {
                $clean['notifications'][$event] = ['title' => $title, 'body' => $body];
            }
        }

        $before = $templates->saved();
        DB::transaction(function () use ($request, $clean): void {
            $current = DB::table('system_settings')->where('key', 'store.message_templates')
                ->lockForUpdate()->first();
            $payload = [
                'value' => json_encode($clean, JSON_THROW_ON_ERROR),
                'version' => (int) ($current?->version ?? 0) + 1,
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
            ];
            if ($current) {
                DB::table('system_settings')->where('key', 'store.message_templates')->update($payload);
            } else {
                DB::table('system_settings')->insert(['key' => 'store.message_templates', ...$payload, 'created_at' => now()]);
            }
        });
        $audit->record($request, 'settings.message_templates.updated', 'system_setting', 'store.message_templates', $before, $clean);

        return back()->with('status', 'Template pesan disimpan.');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless(filter_var($admin?->email, FILTER_VALIDATE_EMAIL), 422);
        SendTransactionalEmailJob::dispatch(
            $admin->email,
            'Tes template email LFAMILIA STORE',
            'Ini email uji dari panel admin. Tidak berisi data pesanan atau tautan sensitif.',
            'generic'
        )->afterCommit();

        return back()->with('status', 'Email uji dimasukkan ke antrean pengiriman alamat admin.');
    }
}
