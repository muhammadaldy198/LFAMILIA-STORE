<?php

namespace App\Services;

/**
 * Tiga ember notifikasi admin, dipakai bersama oleh Telegram dan Discord.
 * Pemetaan event lama tetap lengkap; event tak dikenal menuju arsip.
 */
final class DiscordNotificationRouting
{
    public const CHANNELS = ['order', 'insiden', 'arsip'];

    /** @var array<string,string> */
    private const MAP = [
        'order.created' => 'order',
        'payment.paid' => 'order',
        'payment.late_verified' => 'order',
        'payment.manual_qris.pending' => 'order',
        'payment.refund_review' => 'order',
        'payment.partial_refund_review' => 'order',
        'wallet.topup_refund_review' => 'order',
        'wallet.topup_refund_resolved' => 'order',

        'fulfillment.success' => 'insiden',
        'fulfillment.stock.success' => 'insiden',
        'fulfillment.failed' => 'insiden',
        'fulfillment.manual.pending' => 'insiden',
        'fulfillment.manual.success' => 'insiden',
        'fulfillment.manual.failed' => 'insiden',
        'fulfillment.pending' => 'insiden',
        'fulfillment.unknown' => 'insiden',
        'fulfillment.pending.stale' => 'insiden',
        'fulfillment.blocked' => 'insiden',
        'fulfillment.exhausted' => 'insiden',
        'fulfillment.reconcile.stalled' => 'insiden',
        'fulfillment.price_guard_breach' => 'insiden',
        'digiflazz.mapping.duplicate_active' => 'insiden',
        'system.healthcheck.failed' => 'insiden',
        'system.healthcheck.recovered' => 'insiden',
        'integration.error' => 'insiden',
        'digiflazz.sync.failed' => 'insiden',

        'support.ticket.created' => 'arsip',
        'support.ticket.replied' => 'arsip',
        'security.admin_login_suspicious' => 'arsip',
    ];

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
