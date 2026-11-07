<?php

namespace Tests\Unit;

use App\Services\DiscordNotificationRouting;
use PHPUnit\Framework\TestCase;

class DiscordNotificationRoutingTest extends TestCase
{
    public function test_existing_event_types_are_partitioned_into_four_purposes(): void
    {
        $this->assertSame(['order', 'tugas', 'sistem', 'arsip'], DiscordNotificationRouting::CHANNELS);

        $purposes = [
            'order' => [
                'order.created',
                'payment.paid',
                'payment.late_verified',
                'payment.refund_review',
                'payment.partial_refund_review',
                'wallet.topup_refund_review',
                'wallet.topup_refund_resolved',
                'fulfillment.success',
                'fulfillment.stock.success',
                'fulfillment.manual.success',
                'fulfillment.pending',
            ],
            'tugas' => [
                'fulfillment.manual.pending',
                'fulfillment.manual.failed',
                'fulfillment.failed',
                'fulfillment.blocked',
                'fulfillment.exhausted',
                'fulfillment.unknown',
                'fulfillment.pending.stale',
                'fulfillment.reconcile.stalled',
                'fulfillment.reconcile.too_old',
                'payment.manual_qris.pending',
                'digiflazz.mapping.duplicate_active',
            ],
            'sistem' => [
                'system.healthcheck.failed',
                'system.healthcheck.recovered',
                'integration.error',
                'digiflazz.sync.failed',
                'fulfillment.price_guard_breach',
            ],
            'arsip' => [
                'support.ticket.created',
                'support.ticket.replied',
                'security.admin_login_suspicious',
            ],
        ];

        foreach ($purposes as $purpose => $events) {
            foreach ($events as $event) {
                $this->assertSame($purpose, DiscordNotificationRouting::channelFor($event), $event);
            }
        }
        $this->assertSame('arsip', DiscordNotificationRouting::channelFor('something.brand_new'));
        $allEvents = array_merge(...array_values($purposes));
        $this->assertCount(count(array_unique($allEvents)), $allEvents);
        $this->assertSame(
            array_values(array_filter($allEvents, fn (string $event): bool => ! str_contains($event, 'refund'))),
            DiscordNotificationRouting::templateEvents(),
        );
    }

    public function test_group_webhook_falls_back_to_default_and_rejects_invalid_url(): void
    {
        $order = 'https://discord.com/api/webhooks/111/order';
        $tugas = 'https://discord.com/api/webhooks/222/tugas';
        $sistem = 'https://discord.com/api/webhooks/333/sistem';
        $arsip = 'https://discord.com/api/webhooks/444/arsip';
        $default = 'https://discord.com/api/webhooks/999/default';
        $config = [
            'webhook_url' => $default,
            'webhook_url_order' => $order,
            'webhook_url_tugas' => $tugas,
            'webhook_url_sistem' => $sistem,
            'webhook_url_arsip' => $arsip,
        ];

        foreach (['order' => $order, 'tugas' => $tugas, 'sistem' => $sistem, 'arsip' => $arsip] as $purpose => $url) {
            $this->assertSame($url, DiscordNotificationRouting::webhookUrl($config, $purpose));
            $this->assertSame($default, DiscordNotificationRouting::webhookUrl(
                ['webhook_url' => $default],
                $purpose,
            ));
        }
        $this->assertNull(DiscordNotificationRouting::webhookUrl($config, 'unknown'));
        $this->assertSame($default, DiscordNotificationRouting::webhookUrl([
            'webhook_url' => $default, 'webhook_url_order' => 'invalid',
        ], 'order'));
        $this->assertNull(DiscordNotificationRouting::webhookUrl([], 'order'));
        $this->assertNull(DiscordNotificationRouting::webhookUrl([
            'webhook_url' => 'not-a-url',
        ], 'arsip'));
    }
}
