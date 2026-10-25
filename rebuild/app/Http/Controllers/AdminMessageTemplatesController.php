<?php

namespace App\Http\Controllers;

use App\Jobs\SendTransactionalEmailJob;
use App\Services\AdminAuditService;
use App\Services\EditableMessageTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminMessageTemplatesController
{
    public function index(EditableMessageTemplateService $templates): Response
    {
        $events = DB::table('admin_notifications')->select('event_type')
            ->distinct()->orderBy('event_type')->pluck('event_type')->all();
        $saved = $templates->saved();

        return Inertia::render('Admin/MessageTemplates', [
            'emailEvents' => EditableMessageTemplateService::EMAIL_EVENTS,
            'notificationEvents' => array_values(array_unique(array_merge(
                $events, array_keys((array) ($saved['notifications'] ?? []))
            ))),
            'templates' => $saved,
        ]);
    }

    public function update(Request $request, AdminAuditService $audit, EditableMessageTemplateService $templates): RedirectResponse
    {
        $validated = $request->validate([
            'emails' => ['required', 'array'],
            'emails.*.subject' => ['nullable', 'string', 'max:255'],
            'emails.*.body' => ['nullable', 'string', 'max:10000'],
            'notifications' => ['required', 'array', 'max:100'],
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
