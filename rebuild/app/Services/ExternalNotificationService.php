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

        $renderer = app(AdminNotificationRenderer::class);
        $payload = $renderer->fromNotification($notification);
        $results = [
            $this->telegram($notificationId, $payload, $withLock, $renderer),
            $this->discord($notification, $payload, $withLock, $renderer),
            $this->resend($notificationId, $payload, $withLock, $renderer),
        ];

        if (in_array(false, $results, true)) {
            throw new RuntimeException('ADMIN_NOTIFICATION_DELIVERY_FAILED');
        }
    }

    private function telegram(int $id, array $payload, bool $withLock, AdminNotificationRenderer $renderer): ?bool
    {
        $resolved = $this->runtime->resolve('telegram');
        $config = $resolved['config'] ?? [];
        $environment = (string) ($resolved['environment'] ?? '');
        if (! is_array($config)
            || ($environment !== 'production' && ! (app()->environment('testing') && $environment === 'test'))
            || empty($config['chat_id'])) {
            return null;
        }

        $body = [
            'chat_id' => (string) $config['chat_id'],
            ...$renderer->telegram($payload),
        ];
        $bucket = DiscordNotificationRouting::channelFor($payload['event']);
        $token = trim((string) ($config['bot_token_'.$bucket] ?? ''));
        if ($token === '') {
            $token = trim((string) ($config['bot_token'] ?? ''));
        }
        if ($token === '') {
            return null;
        }

        $thread = trim((string) ($config['thread_'.$bucket] ?? ''));
        if ($thread !== '' && ctype_digit($thread) && (int) $thread > 0 && (int) $thread <= 2147483647) {
            $body['message_thread_id'] = (int) $thread;
        }

        return $this->attempt($id, 'telegram', (string) $config['chat_id'], $withLock, function () use ($token, $environment, $body) {
            return Http::acceptJson()->timeout(8)->post(
                $this->runtime->telegramBotBase($environment, $token).'/sendMessage',
                $body
            );
        });
    }

    private function discord(object $notification, array $payload, bool $withLock, AdminNotificationRenderer $renderer): ?bool
    {
        $config = $this->config('discord');
        if (! $config) {
            return null;
        }

        // Historical notification deliveries used 10 channel names. A queued
        // retry after migration must not resend an event already marked SENT.
        if (DB::table('notification_deliveries')->where('admin_notification_id', $notification->id)
            ->where('channel', 'like', 'discord:%')->where('status', 'SENT')->exists()) {
            return true;
        }

        $channel = DiscordNotificationRouting::channelFor($payload['event']);
        $url = DiscordNotificationRouting::webhookUrl($config, $channel);
        if ($url === null) {
            return null;
        }

        $embed = $renderer->discord($payload);
        $post = fn (string $target) => Http::acceptJson()->withOptions(['allow_redirects' => false])->timeout(8)->post($target, $embed);

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

    private function resend(int $id, array $payload, bool $withLock, AdminNotificationRenderer $renderer): ?bool
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

        $mail = $renderer->email($payload);

        return $this->attempt($id, 'email', implode(',', $recipients), $withLock, fn () => Http::withToken((string) $config['api_key'])
            ->acceptJson()->timeout(8)->post('https://api.resend.com/emails', [
                'from' => (string) $config['from_email'],
                'to' => $recipients,
                'subject' => $mail['subject'],
                'text' => $mail['text'],
                'html' => $mail['html'],
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
