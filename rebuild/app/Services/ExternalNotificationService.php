<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ExternalNotificationService
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    /**
     * @param  bool  $withLock  false = lewati Cache::lock (Redis) dan
     *                          panggil attemptLocked langsung. Dipakai
     *                          jalur alert healthcheck yang tidak boleh
     *                          bergantung Redis: bila Redis mati, lock
     *                          melempar dan alert hilang. Dedupe tetap
     *                          jalan via DB (cek SENT di attemptLocked
     *                          + dedupe 30 menit di pemanggil).
     */
    public function deliver(int $notificationId, bool $withLock = true): void
    {
        $notification = DB::table('admin_notifications')->where('id', $notificationId)->first();
        if (! $notification) {
            return;
        }

        $text = '['.$notification->severity.'] '.$notification->title."\n".$notification->message;
        $results = [
            $this->telegram($notificationId, $text, $withLock),
            $this->discord($notification, $text, $withLock),
            $this->resend($notificationId, $notification->title, $text, $withLock),
        ];

        if (in_array(false, $results, true)) {
            throw new RuntimeException('ADMIN_NOTIFICATION_DELIVERY_FAILED');
        }
    }

    private function telegram(int $id, string $text, bool $withLock): ?bool
    {
        $resolved = $this->runtime->resolve('telegram');
        $config = $resolved['config'] ?? [];
        $environment = (string) ($resolved['environment'] ?? '');
        if (! is_array($config)
            || ! in_array($environment, ['test', 'production'], true)
            || empty($config['bot_token'])
            || empty($config['chat_id'])) {
            return null;
        }

        return $this->attempt($id, 'telegram', (string) $config['chat_id'], $withLock, function () use ($config, $environment, $text) {
            return Http::acceptJson()->timeout(8)->post(
                $this->runtime->telegramBotBase($environment, (string) $config['bot_token']).'/sendMessage',
                ['chat_id' => (string) $config['chat_id'], 'text' => $text]
            );
        });
    }

    private function discord(object $notification, string $text, bool $withLock): ?bool
    {
        $config = $this->config('discord');
        if (! $config) {
            return null;
        }

        // Discord dipecah per jenis notif; tiap channel dilacak terpisah
        // di notification_deliveries agar retry tidak tertukar.
        $channel = DiscordNotificationRouting::channelFor((string) $notification->event_type);
        $url = DiscordNotificationRouting::webhookUrl($config, $channel);
        if ($url === null) {
            return null;
        }

        $post = fn (string $target) => Http::acceptJson()->withOptions(['allow_redirects' => false])->timeout(8)->post($target, [
            'content' => mb_substr($text, 0, 1900),
            'allowed_mentions' => ['parse' => []],
        ]);

        $sent = $this->attempt((int) $notification->id, 'discord:'.$channel, $url, $withLock, fn () => $post($url));

        if ($sent) {
            return true;
        }

        // Fallback nyata ke webhook default: webhookUrl() hanya
        // menjatuhkan ke default bila URL per-channel formatnya
        // tidak valid. URL per-channel yang formatnya valid tapi
        // tidak terjangkau (dihapus di Discord → 404, timeout)
        // tetap dipilih webhookUrl(), dan tanpa fallback di sini
        // notif channel itu hilang padahal webhook default sehat —
        // padahal tes koneksi menjanjikan "jatuh ke webhook
        // default". Coba sekali ke default (bila ada dan berbeda)
        // sebelum menyerah; baris notification_deliveries untuk
        // channel ini mencatat percobaan terakhir (SENT ke default
        // bila fallback berhasil).
        $default = DiscordNotificationRouting::defaultWebhookUrl($config);
        if ($default === null || $default === $url) {
            return false;
        }

        return $this->attempt((int) $notification->id, 'discord:'.$channel, $default, $withLock, fn () => $post($default));
    }

    private function resend(int $id, string $subject, string $text, bool $withLock): ?bool
    {
        $config = $this->config('resend');
        $recipients = $config['admin_recipients'] ?? [];
        if (is_string($recipients)) {
            $recipients = array_values(array_filter(array_map('trim', explode(',', $recipients))));
        }
        if (! $config || empty($config['api_key']) || empty($config['from_email'])
            || ! is_array($recipients) || $recipients === []) {
            return null;
        }

        return $this->attempt($id, 'email', implode(',', $recipients), $withLock, fn () => Http::withToken((string) $config['api_key'])
            ->acceptJson()->timeout(8)->post('https://api.resend.com/emails', [
                'from' => (string) $config['from_email'],
                'to' => $recipients,
                'subject' => '[LFAMILIA] '.$subject,
                'text' => $text,
            ]));
    }

    private function attempt(int $id, string $channel, string $destination, bool $withLock, callable $callback): bool
    {
        if (! $withLock) {
            return $this->attemptLocked($id, $channel, $destination, $callback);
        }

        // Serialize external sends across queued workers. The HTTP timeouts are 8 seconds,
        // so the 30-second lease outlives each network operation.
        return Cache::lock('lfamilia:notify:'.$id.':'.$channel, 30)
            ->block(10, fn (): bool => $this->attemptLocked($id, $channel, $destination, $callback));
    }

    private function attemptLocked(int $id, string $channel, string $destination, callable $callback): bool
    {
        if (DB::table('notification_deliveries')
            ->where('admin_notification_id', $id)
            ->where('channel', $channel)
            ->where('status', 'SENT')->exists()) {
            return true;
        }

        try {
            $response = $callback();
            $success = $response->successful();
            DB::table('notification_deliveries')->updateOrInsert(
                ['admin_notification_id' => $id, 'channel' => $channel],
                [
                    'status' => $success ? 'SENT' : 'FAILED',
                    'destination_hash' => hash('sha256', $destination),
                    'response_code' => (string) $response->status(),
                    'error_code' => $success ? null : 'HTTP_'.$response->status(),
                    'created_at' => now(),
                ]
            );

            return $success;
        } catch (Throwable) {
            DB::table('notification_deliveries')->updateOrInsert(
                ['admin_notification_id' => $id, 'channel' => $channel],
                [
                    'status' => 'FAILED',
                    'destination_hash' => hash('sha256', $destination),
                    'response_code' => null,
                    'error_code' => 'TRANSPORT_ERROR',
                    'created_at' => now(),
                ]
            );

            return false;
        }
    }

    private function config(string $code): ?array
    {
        $resolved = $this->runtime->resolve($code);

        return isset($resolved['config']) && is_array($resolved['config']) ? $resolved['config'] : null;
    }
}
