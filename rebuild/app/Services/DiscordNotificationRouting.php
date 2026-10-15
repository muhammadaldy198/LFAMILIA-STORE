<?php

namespace App\Services;

/**
 * Routing notifikasi admin ke channel Discord per jenis notif
 * (keputusan 15 Okt 2026 02:49 WIB). Telegram tetap 1 tujuan dan
 * menerima semua notif; email admin + panel juga tetap semua.
 *
 * Channel: order, qris, fulfillment, tiket, blocked, login, urgent,
 * vps, integrasi, sync. Tipe event yang belum dipetakan jatuh ke
 * channel umum ORDER agar notif tidak hilang diam-diam.
 */
final class DiscordNotificationRouting
{
    public const CHANNELS = [
        'order',
        'qris',
        'fulfillment',
        'tiket',
        'blocked',
        'login',
        'urgent',
        'vps',
        'integrasi',
        'sync',
    ];

    /** @var array<string, string> event_type => channel */
    private const MAP = [
        // ORDER: order baru + bayar terlambat + laporan refund (gateway reports).
        'order.created' => 'order',
        'payment.paid' => 'order',
        'payment.late_verified' => 'order',
        'payment.refund_review' => 'order',
        'payment.partial_refund_review' => 'order',
        'wallet.topup_refund_review' => 'order',
        'wallet.topup_refund_resolved' => 'order',
        // QRIS.
        'payment.manual_qris.pending' => 'qris',
        // FULFILMENT: update fulfillment rutin.
        'fulfillment.success' => 'fulfillment',
        'fulfillment.stock.success' => 'fulfillment',
        'fulfillment.failed' => 'fulfillment',
        'fulfillment.manual.pending' => 'fulfillment',
        'fulfillment.manual.success' => 'fulfillment',
        'fulfillment.manual.failed' => 'fulfillment',
        'fulfillment.pending' => 'fulfillment',
        'fulfillment.unknown' => 'fulfillment',
        'fulfillment.pending.stale' => 'fulfillment',
        // TIKET.
        'support.ticket.created' => 'tiket',
        'support.ticket.replied' => 'tiket',
        // BLOCKED: tertahan / mentok, perlu penanganan manual.
        'fulfillment.blocked' => 'blocked',
        'fulfillment.exhausted' => 'blocked',
        'fulfillment.reconcile.stalled' => 'blocked',
        // LOGIN MENCURIGAKAN.
        'security.admin_login_suspicious' => 'login',
        // URGENT+MAPPING: harga penyedia jebol + mapping aktif ganda.
        'fulfillment.price_guard_breach' => 'urgent',
        'digiflazz.mapping.duplicate_active' => 'urgent',
        // VPS: website mati / healthcheck.
        'system.healthcheck.failed' => 'vps',
        'system.healthcheck.recovered' => 'vps',
        // INTEGRASI: integrasi bermasalah.
        'integration.error' => 'integrasi',
        // SYNC: sync Digiflazz gagal.
        'digiflazz.sync.failed' => 'sync',
    ];

    public static function channelFor(string $eventType): string
    {
        return self::MAP[$eventType] ?? 'order';
    }

    /**
     * Webhook untuk channel: URL per-channel bila terisi dan valid,
     * bila tidak pakai webhook default. URL tidak valid di mana-mana
     * berarti channel Discord tidak terkirim (Telegram + email jalan).
     *
     * CATATAN: fallback ke default di sini HANYA untuk URL per-channel
     * yang formatnya tidak valid. URL per-channel yang formatnya
     * valid tapi tidak terjangkau (webhook dihapus di Discord → 404,
     * timeout) tetap DIPILIH oleh method ini — fallback untuk kasus
     * itu ada di pengiriman (ExternalNotificationService::discord),
     * yang mencoba webhook default sekali bila kirim ke per-channel
     * gagal. Tanpa fallback di pengiriman, janji "jatuh ke webhook
     * default" di tes koneksi akan salah untuk URL mati.
     */
    public static function webhookUrl(array $config, string $channel): ?string
    {
        $perChannel = trim((string) ($config['webhook_url_'.$channel] ?? ''));
        if ($perChannel !== '' && DiscordWebhookUrl::valid($perChannel)) {
            return $perChannel;
        }

        return self::defaultWebhookUrl($config);
    }

    /** Webhook default (fallback) bila terisi dan formatnya valid. */
    public static function defaultWebhookUrl(array $config): ?string
    {
        $default = trim((string) ($config['webhook_url'] ?? ''));

        return $default !== '' && DiscordWebhookUrl::valid($default) ? $default : null;
    }
}
