<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EditableMessageTemplateService
{
    public const EMAIL_EVENTS = [
        'generic' => 'Email umum dan pesan akun lainnya',
        'order_created' => 'Pesanan dibuat',
        'order_paid' => 'Pembayaran diterima',
        'order_completed' => 'Pesanan selesai',
        'order_failed' => 'Pesanan gagal',
        'verification' => 'Verifikasi email',
        'password_reset' => 'Reset kata sandi',
    ];

    public function saved(): array
    {
        $raw = DB::table('system_settings')->where('key', 'store.message_templates')->value('value');
        $decoded = $raw === null ? null : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A customized message must include the original subject/body. This keeps
     * payment amounts, access codes, signed links, and troubleshooting details
     * intact while giving the operator full control of surrounding copy.
     */
    public function renderEmail(string $event, string $subject, string $body): array
    {
        $all = $this->saved();
        $entry = $all['emails'][$event] ?? null;
        $entry = is_array($entry) ? $entry : [];
        $subjectTemplate = (string) ($entry['subject'] ?? '');
        $bodyTemplate = (string) ($entry['body'] ?? '');
        $identity = app(StorefrontContentService::class)->settings();
        $replacements = ['{{store_name}}' => (string) ($identity['store.name'] ?? 'LFAMILIA STORE')];

        if ($subjectTemplate !== '' && str_contains($subjectTemplate, '{{default_subject}}')) {
            $subject = strtr($subjectTemplate, [
                ...$replacements, '{{default_subject}}' => $subject,
            ]);
        }
        if ($bodyTemplate !== '' && str_contains($bodyTemplate, '{{default_body}}')) {
            $body = strtr($bodyTemplate, [
                ...$replacements, '{{default_body}}' => $body,
            ]);
        }

        return ['subject' => mb_substr($subject, 0, 255), 'body' => $body];
    }

    public function renderNotification(string $event, string $title, string $message): array
    {
        if (! preg_match('/^[a-zA-Z0-9._-]{1,120}$/', $event)) {
            return ['title' => $title, 'message' => $message];
        }
        $entry = $this->saved()['notifications'][$event] ?? null;
        if (! is_array($entry)) {
            return ['title' => $title, 'message' => $message];
        }
        $heading = (string) ($entry['title'] ?? '');
        $body = (string) ($entry['body'] ?? '');

        return [
            'title' => $heading !== '' && str_contains($heading, '{{default_title}}')
                ? mb_substr(str_replace('{{default_title}}', $title, $heading), 0, 255) : $title,
            'message' => $body !== '' && str_contains($body, '{{default_body}}')
                ? str_replace('{{default_body}}', $message, $body) : $message,
        ];
    }
}
