<?php

namespace Tests\Unit;

use App\Services\DiscordNotificationRouting;
use PHPUnit\Framework\TestCase;

/**
 * Keputusan routing (15 Okt 2026 02:49 WIB): Discord dipecah per jenis
 * notif ke 10 channel. Telegram tetap 1 tujuan, dapat semua notif.
 */
class DiscordNotificationRoutingTest extends TestCase
{
    public function test_event_types_map_to_their_channels(): void
    {
        $this->assertSame('order', DiscordNotificationRouting::channelFor('order.created'));
        $this->assertSame('order', DiscordNotificationRouting::channelFor('payment.paid'));
        $this->assertSame('order', DiscordNotificationRouting::channelFor('payment.late_verified'));
        $this->assertSame('order', DiscordNotificationRouting::channelFor('payment.refund_review'));
        $this->assertSame('order', DiscordNotificationRouting::channelFor('payment.partial_refund_review'));
        $this->assertSame('order', DiscordNotificationRouting::channelFor('wallet.topup_refund_review'));
        $this->assertSame('order', DiscordNotificationRouting::channelFor('wallet.topup_refund_resolved'));
        $this->assertSame('qris', DiscordNotificationRouting::channelFor('payment.manual_qris.pending'));
        $this->assertSame('fulfillment', DiscordNotificationRouting::channelFor('fulfillment.success'));
        $this->assertSame('fulfillment', DiscordNotificationRouting::channelFor('fulfillment.failed'));
        $this->assertSame('fulfillment', DiscordNotificationRouting::channelFor('fulfillment.manual.pending'));
        $this->assertSame('fulfillment', DiscordNotificationRouting::channelFor('fulfillment.pending.stale'));
        $this->assertSame('tiket', DiscordNotificationRouting::channelFor('support.ticket.created'));
        $this->assertSame('tiket', DiscordNotificationRouting::channelFor('support.ticket.replied'));
        $this->assertSame('blocked', DiscordNotificationRouting::channelFor('fulfillment.blocked'));
        $this->assertSame('blocked', DiscordNotificationRouting::channelFor('fulfillment.exhausted'));
        $this->assertSame('blocked', DiscordNotificationRouting::channelFor('fulfillment.reconcile.stalled'));
        $this->assertSame('login', DiscordNotificationRouting::channelFor('security.admin_login_suspicious'));
        $this->assertSame('urgent', DiscordNotificationRouting::channelFor('fulfillment.price_guard_breach'));
        $this->assertSame('urgent', DiscordNotificationRouting::channelFor('digiflazz.mapping.duplicate_active'));
        $this->assertSame('vps', DiscordNotificationRouting::channelFor('system.healthcheck.failed'));
        $this->assertSame('vps', DiscordNotificationRouting::channelFor('system.healthcheck.recovered'));
        $this->assertSame('integrasi', DiscordNotificationRouting::channelFor('integration.error'));
        $this->assertSame('sync', DiscordNotificationRouting::channelFor('digiflazz.sync.failed'));
        // Tipe baru yang belum dipetakan jatuh ke channel umum ORDER.
        $this->assertSame('order', DiscordNotificationRouting::channelFor('something.brand_new'));
    }

    public function test_webhook_url_prefers_per_channel_then_falls_back_to_default(): void
    {
        $order = 'https://discord.com/api/webhooks/111/order';
        $default = 'https://discord.com/api/webhooks/999/default';
        $config = ['webhook_url' => $default, 'webhook_url_order' => $order];

        $this->assertSame($order, DiscordNotificationRouting::webhookUrl($config, 'order'));
        $this->assertSame($default, DiscordNotificationRouting::webhookUrl($config, 'qris'));
        $this->assertSame($default, DiscordNotificationRouting::webhookUrl(['webhook_url' => $default], 'vps'));
        // URL per-channel tidak valid -> pakai default (notif tidak hilang).
        $this->assertSame(
            $default,
            DiscordNotificationRouting::webhookUrl(['webhook_url' => $default, 'webhook_url_order' => 'not-a-url'], 'order')
        );
        $this->assertNull(DiscordNotificationRouting::webhookUrl([], 'order'));
        $this->assertNull(DiscordNotificationRouting::webhookUrl(['webhook_url' => 'not-a-url'], 'order'));
    }
}
