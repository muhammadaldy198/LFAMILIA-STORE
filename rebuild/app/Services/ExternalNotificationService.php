<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ExternalNotificationService
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    public function deliver(int $notificationId): void
    {
        $notification = DB::table('admin_notifications')->where('id', $notificationId)->first();
        if (! $notification) {
            return;
        }

        $text = '['.$notification->severity.'] '.$notification->title."\n".$notification->message;
        $results = [
            $this->telegram($notificationId, $text),
            $this->discord($notificationId, $text),
            $this->resend($notificationId, $notification->title, $text),
        ];

        if (in_array(false, $results, true)) {
            throw new RuntimeException('ADMIN_NOTIFICATION_DELIVERY_FAILED');
        }
    }

    private function telegram(int $id, string $text): ?bool
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

        return $this->attempt($id, 'telegram', (string) $config['chat_id'], function () use ($config, $environment, $text) {
            return Http::acceptJson()->timeout(8)->post(
                $this->runtime->telegramBotBase($environment, (string) $config['bot_token']).'/sendMessage',
                ['chat_id' => (string) $config['chat_id'], 'text' => $text]
            );
        });
    }

    private function discord(int $id, string $text): ?bool
    {
        $config = $this->config('discord');
        $url = (string) ($config['webhook_url'] ?? '');
        if (! $config || ! $this->validDiscordWebhook($url)) {
            return null;
        }

        return $this->attempt($id, 'discord', $url, fn () => Http::acceptJson()->timeout(8)->post($url, [
            'content' => mb_substr($text, 0, 1900),
            'allowed_mentions' => ['parse' => []],
        ]));
    }

    private function resend(int $id, string $subject, string $text): ?bool
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

        return $this->attempt($id, 'email', implode(',', $recipients), fn () => Http::withToken((string) $config['api_key'])
            ->acceptJson()->timeout(8)->post('https://api.resend.com/emails', [
                'from' => (string) $config['from_email'],
                'to' => $recipients,
                'subject' => '[LFAMILIA] '.$subject,
                'text' => $text,
            ]));
    }

    private function attempt(int $id, string $channel, string $destination, callable $callback): bool
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

    private function validDiscordWebhook(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($host, ['discord.com', 'www.discord.com', 'discordapp.com', 'www.discordapp.com'], true)) {
            return false;
        }

        return (bool) preg_match('#^/api(?:/v\\d+)?/webhooks/[^/]+/[^/]+/?$#', (string) ($parts['path'] ?? ''));
    }

    private function config(string $code): ?array
    {
        $resolved = $this->runtime->resolve($code);

        return isset($resolved['config']) && is_array($resolved['config']) ? $resolved['config'] : null;
    }
}
