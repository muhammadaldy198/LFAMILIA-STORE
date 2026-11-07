<?php

namespace App\Services;

/**
 * Empat tujuan notifikasi admin bersama untuk Telegram dan Discord.
 * Event tak dikenal selalu dikirim ke arsip.
 */
final class DiscordNotificationRouting
{
    public const CHANNELS = ['order', 'tugas', 'sistem', 'arsip'];

    /** @var array<string,string> */
    private const MAP = [
        'order.created' => 'order',
        'payment.paid' => 'order',
        'payment.late_verified' => 'order',
        'payment.refund_review' => 'order',
        'payment.partial_refund_review' => 'order',
        'wallet.topup_refund_review' => 'order',
        'wallet.topup_refund_resolved' => 'order',
        'fulfillment.success' => 'order',
        'fulfillment.stock.success' => 'order',
        'fulfillment.manual.success' => 'order',
        'fulfillment.pending' => 'order',

        'fulfillment.manual.pending' => 'tugas',
        'fulfillment.manual.failed' => 'tugas',
        'fulfillment.failed' => 'tugas',
        'fulfillment.blocked' => 'tugas',
        'fulfillment.exhausted' => 'tugas',
        'fulfillment.unknown' => 'tugas',
        'fulfillment.pending.stale' => 'tugas',
        'fulfillment.reconcile.stalled' => 'tugas',
        'fulfillment.reconcile.too_old' => 'tugas',
        'payment.manual_qris.pending' => 'tugas',
        'digiflazz.mapping.duplicate_active' => 'tugas',

        'system.healthcheck.failed' => 'sistem',
        'system.healthcheck.recovered' => 'sistem',
        'integration.error' => 'sistem',
        'digiflazz.sync.failed' => 'sistem',
        'fulfillment.price_guard_breach' => 'sistem',

        'support.ticket.created' => 'arsip',
        'support.ticket.replied' => 'arsip',
        'security.admin_login_suspicious' => 'arsip',
    ];

    /** The routing table is PR 2's event registry; historical refund flows are excluded. */
    public static function templateEvents(): array
    {
        return array_values(array_filter(
            array_keys(self::MAP),
            static fn (string $event): bool => ! str_contains(strtolower($event), 'refund')
        ));
    }

    public static function channelFor(string $eventType): string
    {
        return self::MAP[$eventType] ?? 'arsip';
    }

    /**
     * A configured bucket overrides the default. Invalid/unset bucket URLs
     * use the default; transport failures retry the default in the sender.
     */
    public static function webhookUrl(array $config, string $channel): ?string
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            return null;
        }

        $perChannel = trim((string) ($config['webhook_url_'.$channel] ?? ''));
        if ($perChannel !== '' && DiscordWebhookUrl::valid($perChannel)) {
            return $perChannel;
        }

        return self::defaultWebhookUrl($config);
    }

    public static function defaultWebhookUrl(array $config): ?string
    {
        $default = trim((string) ($config['webhook_url'] ?? ''));

        return $default !== '' && DiscordWebhookUrl::valid($default) ? $default : null;
    }
}
