<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Satu kontrak data untuk tiga saluran notifikasi operasional.
 * Status internal boleh muncul hanya di saluran khusus admin.
 */
class AdminNotificationRenderer
{
    private const LEVELS = [
        'INFO' => ['label' => 'INFO', 'color' => '#2F7DE1', 'icon' => '🔵'],
        'SUCCESS' => ['label' => 'BERHASIL', 'color' => '#1A7F37', 'icon' => '🟢'],
        'WARNING' => ['label' => 'PERINGATAN', 'color' => '#B54708', 'icon' => '🟠'],
        'ERROR' => ['label' => 'KRITIS', 'color' => '#D92D20', 'icon' => '🔴'],
    ];

    /**
     * @return array{level:string,color:string,icon:string,title:string,event:string,fields:array<int,array{name:string,value:string}>,action:?string,url:string,wib:string}
     */
    public function fromNotification(object $notification): array
    {
        $level = $this->level((string) ($notification->severity ?? 'INFO'));
        $design = self::LEVELS[$level];
        $event = (string) ($notification->event_type ?? '');
        $data = $this->json($notification->data ?? null);
        $title = trim((string) ($notification->title ?? 'Notifikasi admin'));
        $message = trim((string) ($notification->message ?? ''));
        $fields = [];

        $targetType = (string) ($notification->target_type ?? '');
        $targetId = trim((string) ($notification->target_id ?? ''));
        if ($targetType !== '' && $targetId !== '') {
            $fields[] = ['name' => 'Objek', 'value' => $targetType.' #'.$targetId];
        }

        // Preserve every human-authored fact from the existing notification.
        // A line with "Label: value" becomes a fact; other lines become Rincian.
        $description = [];
        foreach (preg_split('/\r\n|\r|\n/', $message) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([^:]{1,64}):\s*(.+)$/u', $line, $match) === 1) {
                $fields[] = ['name' => trim($match[1]), 'value' => trim($match[2])];
            } else {
                $description[] = $line;
            }
        }
        if ($description !== []) {
            $fields[] = ['name' => 'Rincian', 'value' => implode("\n", $description)];
        }

        // The historical notification message was the only external source.
        // Add only known safe scalar data; do not broadcast arbitrary metadata,
        // integration credentials, webhook signatures or tokens.
        $safe = [
            'invoice' => 'Invoice', 'order_number' => 'Invoice',
            'product' => 'Produk', 'sku' => 'SKU', 'seller' => 'Seller',
            'provider' => 'Penyedia', 'status' => 'Status',
            'response_code' => 'Kode respons', 'rc' => 'Kode respons',
            'reason' => 'Alasan', 'channel' => 'Channel', 'total' => 'Total',
        ];
        foreach ($safe as $key => $label) {
            $value = $data[$key] ?? null;
            $scalar = is_string($value) || is_numeric($value);
            if (! $scalar || trim((string) $value) === '') {
                continue;
            }
            if (! collect($fields)->contains(fn (array $field): bool => strcasecmp($field['name'], $label) === 0)) {
                $fields[] = ['name' => $label, 'value' => trim((string) $value)];
            }
        }

        // Event ID remains visible even when an old message has no structured facts.
        $fields[] = ['name' => 'Peristiwa', 'value' => $event];

        $action = $data['action_required'] ?? $data['action'] ?? null;
        if (! is_string($action) || trim($action) === '') {
            $action = in_array($level, ['WARNING', 'ERROR'], true)
                ? 'Periksa kejadian dan lakukan penanganan melalui panel admin.'
                : null;
        }

        $createdAt = $notification->created_at ?? now('UTC');
        $wib = Carbon::parse($createdAt, 'UTC')
            ->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H.i').' WIB';

        return [
            'level' => $design['label'],
            'color' => $design['color'],
            'icon' => $design['icon'],
            'title' => $title !== '' ? $title : 'Notifikasi admin',
            'event' => $event,
            'fields' => $fields,
            'action' => $action,
            'url' => $this->adminUrl($event, $targetType, $targetId, $data),
            'wib' => $wib,
        ];
    }

    /** @param array<string,mixed> $message */
    public function telegram(array $message): array
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [
            $message['icon'].' <b>['.$escape($message['level']).'] '.$escape(mb_substr($message['title'], 0, 220)).'</b>',
            '',
        ];
        // Reserve enough space for action + admin URL. Truncating the final
        // HTML string can cut tags/entities and make Telegram reject it.
        $footer = ['Waktu: '.$escape($message['wib']), 'Panel: <a href="'.$escape($message['url']).'">Buka di Panel Admin</a>'];
        $action = $message['action'] === null ? [] : [
            '',
            '<b>Yang harus dilakukan:</b> '.$escape(mb_substr($message['action'], 0, 350)),
        ];
        foreach ($message['fields'] as $fact) {
            $line = '<b>'.$escape(mb_substr($fact['name'], 0, 90)).':</b> '.$escape(mb_substr($fact['value'], 0, 1000));
            if (mb_strlen(implode("\n", [...$lines, $line, ...$action, ...$footer])) > 3900) {
                $lines[] = 'Rincian lainnya: lihat panel admin.';
                break;
            }
            $lines[] = $line;
        }
        $lines = [...$lines, ...$action, ...$footer];

        return [
            'text' => implode("\n", $lines),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
    }

    /** @param  array<string, mixed>  $message */
    public function discord(array $message): array
    {
        $title = mb_substr($message['icon'].' ['.$message['level'].'] '.$message['title'], 0, 256);
        $footer = $message['wib'];
        if ($message['action'] !== null) {
            $footer .= ' • Yang harus dilakukan: '.$message['action'];
        }
        $footer = mb_substr($footer, 0, 1600);

        // Discord limits all text within one embed to 6,000 characters
        // COMBINED (not 6,000 per field). Reserve room for its title, footer,
        // field names and a buffer for provider-side counting differences.
        $remaining = 6000 - mb_strlen($title) - mb_strlen($footer) - 80;
        $fields = [];
        foreach ($message['fields'] as $fact) {
            if (count($fields) >= 25 || $remaining <= 8) {
                break;
            }
            $name = mb_substr($fact['name'], 0, min(256, $remaining - 2));
            $remaining -= mb_strlen($name);
            $value = mb_substr($fact['value'], 0, min(1024, $remaining - 2));
            $remaining -= mb_strlen($value);
            if ($name === '' || $value === '') {
                break;
            }
            $fields[] = [
                'name' => $name,
                'value' => $value,
                'inline' => mb_strlen($value) < 110,
            ];
        }

        return [
            'embeds' => [[
                'title' => $title,
                'url' => $message['url'],
                'color' => hexdec(ltrim($message['color'], '#')),
                'fields' => $fields,
                'footer' => ['text' => $footer],
            ]],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /** @param array<string,mixed> $message */
    public function email(array $message): array
    {
        $subject = '[LFAMILIA] ['.$message['level'].'] '.$message['title'];

        $plain = [$subject, $message['wib'], ''];
        foreach ($message['fields'] as $fact) {
            $plain[] = $fact['name'].': '.$fact['value'];
        }
        if ($message['action'] !== null) {
            $plain[] = '';
            $plain[] = 'Yang harus dilakukan: '.$message['action'];
        }
        $plain[] = '';
        $plain[] = 'Buka di Panel Admin: '.$message['url'];
        $plain[] = 'Notifikasi otomatis LFAMILIA STORE — pesan yang sama dikirim ke Telegram, Discord, dan panel admin.';

        return [
            'subject' => mb_substr($subject, 0, 255),
            'text' => implode("\n", $plain),
            'html' => view('emails.admin-notification', $message)->render(),
        ];
    }

    private function level(string $severity): string
    {
        return match (strtoupper(trim($severity))) {
            'SUCCESS', 'OK', 'BERHASIL' => 'SUCCESS',
            'WARN', 'WARNING', 'PERINGATAN' => 'WARNING',
            'CRITICAL', 'FATAL', 'DANGER', 'ERROR', 'KRITIS' => 'ERROR',
            default => 'INFO',
        };
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $data */
    private function adminUrl(string $event, string $targetType, string $targetId, array $data): string
    {
        if ($targetType === 'order' || str_starts_with($event, 'order.')
            || str_starts_with($event, 'payment.') || str_starts_with($event, 'fulfillment.')) {
            if ($targetType === 'order' && ctype_digit($targetId) && (int) $targetId > 0) {
                return route('admin.orders.show', ['id' => (int) $targetId]);
            }

            $orderId = (string) ($data['order_id'] ?? '');
            if (ctype_digit($orderId) && (int) $orderId > 0) {
                return route('admin.orders.show', ['id' => (int) $orderId]);
            }

            $invoice = (string) ($data['invoice'] ?? $data['order_number'] ?? '');
            return $invoice !== '' ? route('admin.orders', ['q' => $invoice]) : route('admin.orders');
        }

        if (str_starts_with($event, 'support.ticket.')) {
            return ctype_digit($targetId) && (int) $targetId > 0
                ? route('admin.support.index', ['ticket' => (int) $targetId])
                : route('admin.support.index');
        }

        if (str_starts_with($event, 'digiflazz.')) {
            return route('admin.digiflazz');
        }

        if (str_starts_with($event, 'integration.')) {
            return route('admin.integrations');
        }

        return route('admin.panel');
    }
}
