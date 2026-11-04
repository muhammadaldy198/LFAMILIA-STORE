<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TemplateOverrideService
{
    // Keys are taken from CustomerEmailTemplateRenderer's PR 1 registry.
    // This map only supplies editor labels; the original copy remains in that renderer.
    private const EMAIL_LABELS = [
        'verification' => 'Verifikasi email',
        'password_reset' => 'Link atur ulang kata sandi',
        'password_changed' => 'Kata sandi berhasil diperbarui',
        'password_reset_completed' => 'Kata sandi berhasil diatur ulang',
        'order_created' => 'Pesanan dibuat — menunggu pembayaran',
        'order_paid' => 'Pembayaran diterima',
        'order_completed' => 'Pesanan terkirim',
        'order_failed' => 'Pesanan gagal',
        'ticket_created' => 'Tiket bantuan diterima',
        'ticket_reply' => 'Balasan tiket',
    ];

    private const EMAIL_PLACEHOLDERS = [
        'verification' => ['nama', 'email'],
        'password_reset' => ['nama', 'email'],
        'password_changed' => ['nama', 'email'],
        'password_reset_completed' => ['nama', 'email'],
        'order_created' => ['nama', 'invoice', 'produk', 'total', 'batas_waktu', 'link_bayar', 'link_lacak'],
        'order_paid' => ['nama', 'invoice', 'produk', 'total', 'link_lacak'],
        'order_completed' => ['nama', 'invoice', 'produk', 'total', 'link_lacak'],
        'order_failed' => ['nama', 'invoice', 'produk', 'total', 'link_lacak'],
        'ticket_created' => ['nama', 'email'],
        'ticket_reply' => ['nama', 'email'],
    ];

    private const ADMIN_PLACEHOLDERS = [
        'judul', 'detail', 'nama_toko', 'event', 'invoice', 'produk', 'sku', 'seller',
        'provider', 'status', 'response_code', 'rc', 'reason', 'channel',
        'total', 'wib', 'link_panel',
    ];

    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * PR 1 supplies the customer keys. PR 2 supplies known admin events;
     * additional events already observed in the database are also editable.
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach (CustomerEmailTemplateRenderer::templateKeys() as $event) {
            $definitions['email.'.$event] = [
                'key' => 'email.'.$event,
                'label' => self::EMAIL_LABELS[$event] ?? $event,
                'group' => 'email',
                'placeholders' => array_values(array_unique([
                    'subjek_bawaan', 'pesan_bawaan', 'nama_toko', ...(self::EMAIL_PLACEHOLDERS[$event] ?? []),
                ])),
            ];
        }

        // Keep previously customized admin events visible after historical
        // notifications have been pruned or the routing table changes.
        $saved = app(EditableMessageTemplateService::class)->saved();
        $legacyEvents = is_array($saved['notifications'] ?? null)
            ? array_keys($saved['notifications'])
            : [];
        $overrideEvents = DB::table('message_template_overrides')
            ->where('template_key', 'like', 'admin.%')
            ->pluck('template_key')
            ->map(static fn ($key): string => substr((string) $key, strlen('admin.')))
            ->all();
        $events = array_unique(array_merge(
            DiscordNotificationRouting::templateEvents(),
            DB::table('admin_notifications')->distinct()->pluck('event_type')->all(),
            $legacyEvents,
            $overrideEvents
        ));
        sort($events);
        foreach ($events as $event) {
            if (! is_string($event) || ! preg_match('/^[a-zA-Z0-9._-]{1,120}$/D', $event)
                || str_contains(strtolower($event), 'refund')) {
                continue;
            }
            $definitions['admin.'.$event] = [
                'key' => 'admin.'.$event,
                'label' => str_replace(['.', '_'], ' ', $event),
                'group' => 'admin',
                'placeholders' => self::ADMIN_PLACEHOLDERS,
            ];
        }

        return $definitions;
    }

    public function listing(): array
    {
        $rows = DB::table('message_template_overrides')->get()->keyBy('template_key');
        $legacy = app(EditableMessageTemplateService::class)->saved();
        $legacyUpdatedAt = DB::table('system_settings')->where('key', 'store.message_templates')->value('updated_at');
        $entries = [];
        foreach ($this->definitions() as $key => $definition) {
            $row = $rows->get($key);
            [$type, $event] = explode('.', $key, 2);
            $group = $type === 'email' ? 'emails' : 'notifications';
            $old = $legacy[$group][$event] ?? null;
            $old = is_array($old) && (trim((string) ($old['subject'] ?? $old['title'] ?? '')) !== ''
                || trim((string) ($old['body'] ?? '')) !== '') ? $old : null;
            $subjectDefault = $type === 'admin' ? '{judul}' : '{subjek_bawaan}';
            $bodyDefault = $type === 'admin' ? '{detail}' : '{pesan_bawaan}';
            $subjectLegacy = $type === 'admin' ? (string) ($old['title'] ?? '') : (string) ($old['subject'] ?? '');
            $subjectLegacy = str_replace(
                ['{{default_subject}}', '{{default_title}}', '{{store_name}}'],
                ['{subjek_bawaan}', '{judul}', '{nama_toko}'],
                $subjectLegacy
            );
            $bodyLegacy = str_replace(
                ['{{default_body}}', '{{store_name}}'],
                [$bodyDefault, '{nama_toko}'],
                (string) ($old['body'] ?? '')
            );
            $modifiedAt = $row?->updated_at ?? ($old !== null ? $legacyUpdatedAt : null);
            $entries[] = [
                ...$definition,
                'subject' => $row?->subject ?? ($subjectLegacy !== '' ? $subjectLegacy : $subjectDefault),
                'body' => $row?->body ?? ($bodyLegacy !== '' ? $bodyLegacy : $bodyDefault),
                'is_enabled' => $row ? (bool) $row->is_enabled : true,
                'has_legacy' => $old !== null,
                'status' => $row === null && $old === null ? 'Bawaan' : (($row !== null && ! $row->is_enabled) ? 'Nonaktif' : 'Diubah'),
                'updated_at' => $modifiedAt
                    ? Carbon::parse((string) $modifiedAt, 'UTC')->timezone('Asia/Jakarta')->toIso8601String()
                    : null,
            ];
        }

        return $entries;
    }

    /**
     * Remove only the selected event from the previous editor's settings.
     * Called inside the caller's transaction; never discard other templates.
     * Returns the previous value so the change can be audited.
     */
    public function clearLegacy(string $key, int $adminId): ?array
    {
        [$kind, $event] = explode('.', $key, 2);
        $group = $kind === 'email' ? 'emails' : 'notifications';
        $setting = DB::table('system_settings')->where('key', 'store.message_templates')->lockForUpdate()->first();
        if ($setting === null) {
            return null;
        }

        $all = json_decode((string) $setting->value, true);
        if (! is_array($all) || ! isset($all[$group][$event]) || ! is_array($all[$group][$event])) {
            return null;
        }

        $previous = $all[$group][$event];
        unset($all[$group][$event]);
        DB::table('system_settings')->where('key', 'store.message_templates')->update([
            'value' => json_encode($all, JSON_THROW_ON_ERROR),
            'version' => (int) $setting->version + 1,
            'updated_by_admin_id' => $adminId,
            'updated_at' => now('UTC'),
        ]);

        return $previous;
    }

    public function enabled(string $key): bool
    {
        if (! str_starts_with($key, 'email.')) {
            return true;
        }
        $row = DB::table('message_template_overrides')->where('template_key', $key)->first(['is_enabled']);

        return $row === null || (bool) $row->is_enabled;
    }

    public function validateDraft(string $key, string $subject, string $body, bool $enabled = true): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages(['template_key' => 'Template tidak dikenal.']);
        }
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '' || mb_strlen($subject) > 255 || mb_strlen($body) > 10000) {
            throw ValidationException::withMessages(['body' => 'Subjek dan badan wajib diisi (maksimum 255 / 10.000 karakter).']);
        }
        foreach ([$subject, $body] as $part) {
            preg_match_all('/\{([a-z_][a-z0-9_]*)\}/i', $part, $matches);
            foreach ($matches[1] as $name) {
                if (! in_array($name, $definition['placeholders'], true)) {
                    throw ValidationException::withMessages(['body' => 'Placeholder {'.$name.'} tidak tersedia pada template ini.']);
                }
            }
        }
        // Support address is supplied exclusively by the global shop settings and footer.
        // A literal email address in customer copy would duplicate it or become stale.
        if ($definition['group'] === 'email' && preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu', $subject."\n".$body)) {
            throw ValidationException::withMessages([
                'body' => 'Alamat email tidak boleh ditulis langsung di template pelanggan. Gunakan {email} untuk alamat pelanggan; kontak support tetap di footer.',
            ]);
        }
        if (preg_match('/\brefund\b|pengembalian\s+dana|uang\s+kembali/iu', $subject."\n".$body)) {
            throw ValidationException::withMessages(['body' => 'Teks menjanjikan pengembalian dana tidak diperbolehkan.']);
        }
        if ($definition['group'] === 'admin') {
            $enabled = true;
        }

        return ['subject' => $subject, 'body' => $body, 'is_enabled' => $enabled];
    }

    /**
     * The frame, CTAs, facts, URLs, footer and status always come from PR 1/2.
     * A draft is provided exclusively by the authenticated preview endpoint.
     *
     * @return array{subject:string,body:string}
     */
    public function apply(string $key, string $defaultSubject, string $defaultBody, array $variables = [], ?array $draft = null): array
    {
        $row = $draft ?? (array) (DB::table('message_template_overrides')->where('template_key', $key)->first() ?? []);
        if ($row === []) {
            return ['subject' => $defaultSubject, 'body' => $defaultBody];
        }
        $available = ['subjek_bawaan' => $defaultSubject, 'pesan_bawaan' => $defaultBody, ...$variables];

        return [
            'subject' => mb_substr($this->replace((string) ($row['subject'] ?? $defaultSubject), $available), 0, 255),
            'body' => $this->replace((string) ($row['body'] ?? $defaultBody), $available),
        ];
    }

    private function replace(string $template, array $variables): string
    {
        $replace = [];
        foreach ($variables as $key => $value) {
            $replace['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $replace);
    }
}
