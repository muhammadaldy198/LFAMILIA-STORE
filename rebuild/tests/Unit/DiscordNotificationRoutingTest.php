<?php

namespace Tests\Unit;

use App\Services\DiscordNotificationRouting;
use PHPUnit\Framework\TestCase;

class DiscordNotificationRoutingTest extends TestCase
{
    public function test_existing_event_types_are_partitioned_into_three_buckets(): void
    {
        $this->assertSame(['order', 'insiden', 'arsip'], DiscordNotificationRouting::CHANNELS);
        foreach ([
            'order.created', 'payment.paid', 'payment.late_verified',
            'payment.manual_qris.pending', 'payment.refund_review',
        ] as $type) {
            $this->assertSame('order', DiscordNotificationRouting::channelFor($type));
        }
        foreach ([
            'fulfillment.success', 'fulfillment.failed',
            'fulfillment.blocked', 'fulfillment.reconcile.stalled',
            'digiflazz.sync.failed', 'integration.error',
            'system.healthcheck.failed', 'system.healthcheck.recovered',
            'digiflazz.mapping.duplicate_active',
        ] as $type) {
            $this->assertSame('insiden', DiscordNotificationRouting::channelFor($type));
        }
        foreach ([
            'support.ticket.created', 'support.ticket.replied',
            'security.admin_login_suspicious', 'something.brand_new',
        ] as $type) {
            $this->assertSame('arsip', DiscordNotificationRouting::channelFor($type));
        }
    }

    public function test_group_webhook_falls_back_to_default_and_rejects_invalid_url(): void
    {
        $order = 'https://discord.com/api/webhooks/111/order';
        $default = 'https://discord.com/api/webhooks/999/default';
        $config = ['webhook_url' => $default, 'webhook_url_order' => $order];

        $this->assertSame($order, DiscordNotificationRouting::webhookUrl($config, 'order'));
        $this->assertSame($default, DiscordNotificationRouting::webhookUrl($config, 'insiden'));
        $this->assertSame($default, DiscordNotificationRouting::webhookUrl($config, 'arsip'));
        $this->assertNull(DiscordNotificationRouting::webhookUrl($config, 'qris'));
        $this->assertSame($default, DiscordNotificationRouting::webhookUrl([
            'webhook_url' => $default, 'webhook_url_order' => 'invalid',
        ], 'order'));
        $this->assertNull(DiscordNotificationRouting::webhookUrl([], 'order'));
        $this->assertNull(DiscordNotificationRouting::webhookUrl([
            'webhook_url' => 'not-a-url',
        ], 'arsip'));
    }
}
