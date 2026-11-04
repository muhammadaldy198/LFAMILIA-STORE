<?php

namespace Tests\Feature;

use App\Models\IntegrationCredential;
use App\Services\AdminNotificationRenderer;
use App\Services\AdminNotificationService;
use App\Services\ExternalNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminNotificationThreeFormatsTest extends TestCase
{
    use DatabaseTransactions;

    private const ORDER_WEBHOOK = 'https://discord.com/api/webhooks/111/order';

    private const INCIDENT_WEBHOOK = 'https://discord.com/api/webhooks/222/incident';

    private const ARCHIVE_WEBHOOK = 'https://discord.com/api/webhooks/333/archive';

    private const FALLBACK_WEBHOOK = 'https://discord.com/api/webhooks/999/fallback';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
    }

    private function telegram(array $threads = []): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'telegram'], [
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => [
                    'test' => [
                        'bot_token' => '111:test-token',
                        'chat_id' => 'test-chat',
                        ...$threads,
                    ],
                ],
            ],
        ]);
    }

    private function discord(array $urls = []): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'discord'], [
            'is_active' => true,
            'config_ciphertext' => $urls,
        ]);
    }

    private function resend(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'resend'], [
            'is_active' => true,
            'config_ciphertext' => [
                'api_key' => 'resend-ci-key',
                'from_email' => 'system@lfamilia.test',
                'admin_recipients' => ['owner@lfamilia.test'],
            ],
        ]);
    }

    private function event(string $type = 'fulfillment.blocked', string $severity = 'ERROR'): int
    {
        return app(AdminNotificationService::class)->record(
            $type,
            'Pengiriman Gagal — LF261103-SYLIRR1OBB',
            "Invoice: LF261103-SYLIRR1OBB\nProduk: Mobile Legends 70 DM\nSeller: Infinity ID\nDigiflazz: Gagal — RC 02\nStatus: BLOCKED, menunggu tindakan",
            $severity, 'order', 107,
            [
                'sku' => 'ML70_A',
                'action_required' => 'Kirim ulang manual atau ganti seller dari panel.',
            ]
        );
    }

    public function test_one_admin_event_renders_three_distinct_formats_with_same_facts(): void
    {
        $this->telegram(['thread_insiden' => '5678']);
        $this->discord(['webhook_url_insiden' => self::INCIDENT_WEBHOOK]);
        $this->resend();

        $id = $this->event();
        $createdAt = DB::table('admin_notifications')->where('id', $id)->value('created_at');
        // Database stores UTC even when view displays WIB.
        $this->assertNotNull($createdAt);

        app(ExternalNotificationService::class)->deliver($id);

        $telegram = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))->first()[0];
        $discord = Http::recorded(fn ($request) => $request->url() === self::INCIDENT_WEBHOOK)->first()[0];
        $resend = Http::recorded(fn ($request) => $request->url() === 'https://api.resend.com/emails')->first()[0];

        $this->assertSame('HTML', $telegram['parse_mode']);
        $this->assertSame(5678, $telegram['message_thread_id']);
        $this->assertStringContainsString('<b>[KRITIS]', $telegram['text']);
        $this->assertStringContainsString('ML70_A', $telegram['text']);
        $this->assertStringContainsString('LF261103-SYLIRR1OBB', $telegram['text']);

        $this->assertArrayNotHasKey('content', $discord->data());
        $this->assertStringContainsString('[KRITIS]', $discord['embeds'][0]['title']);
        $this->assertSame(hexdec('D92D20'), $discord['embeds'][0]['color']);
        $this->assertContains('SKU', array_column($discord['embeds'][0]['fields'], 'name'));
        $this->assertStringContainsString('Yang harus dilakukan:', $discord['embeds'][0]['footer']['text']);
        $this->assertStringContainsString('WIB', $discord['embeds'][0]['footer']['text']);

        $this->assertSame('owner@lfamilia.test', $resend['to'][0]);
        $this->assertStringContainsString('[LFAMILIA] [KRITIS]', $resend['subject']);
        $this->assertStringContainsString('background:#D92D20', $resend['html']);
        $this->assertStringContainsString('LF261103-SYLIRR1OBB', $resend['html']);
        $this->assertStringContainsString('Infinity ID', $resend['html']);
        $this->assertStringContainsString('ML70_A', $resend['html']);
        $this->assertStringContainsString('Buka di Panel Admin', $resend['html']);
        $this->assertStringContainsString('Yang harus dilakukan:', $resend['text']);
        $this->assertStringContainsString('WIB', $resend['html']);
        $this->assertStringContainsString('WIB', $resend['text']);
        $this->assertSame(1, substr_count($resend['html'], 'Buka di Panel Admin'));

        foreach (['telegram', 'discord:insiden', 'email'] as $channel) {
            $this->assertDatabaseHas('notification_deliveries', [
                'admin_notification_id' => $id,
                'channel' => $channel,
                'status' => 'SENT',
            ]);
        }
    }

    public function test_three_telegram_buckets_use_only_their_configured_thread_ids(): void
    {
        $this->telegram([
            'thread_order' => '101',
            'thread_insiden' => '202',
            // arsip intentionally omitted.
        ]);
        foreach (['payment.manual_qris.pending', 'fulfillment.blocked', 'support.ticket.created'] as $event) {
            $id = $this->event($event);
            app(ExternalNotificationService::class)->deliver($id);
        }
        $requests = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]);

        $this->assertCount(3, $requests);
        $this->assertSame(101, $requests[0]['message_thread_id']);
        $this->assertSame(202, $requests[1]['message_thread_id']);
        $this->assertArrayNotHasKey('message_thread_id', $requests[2]->data());
    }

    public function test_discord_fallback_unconfigured_destinations_and_dedupe(): void
    {
        $this->discord([
            'webhook_url' => self::FALLBACK_WEBHOOK,
            'webhook_url_order' => self::ORDER_WEBHOOK,
        ]);
        $this->telegram();

        $orderId = $this->event('payment.manual_qris.pending', 'INFO');
        $archiveId = $this->event('support.ticket.replied', 'SUCCESS');
        $service = app(ExternalNotificationService::class);
        $service->deliver($orderId);
        $service->deliver($orderId);
        $service->deliver($archiveId);
        $service->deliver($archiveId);

        $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === self::ORDER_WEBHOOK)->count());
        $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === self::FALLBACK_WEBHOOK)->count());
        $this->assertSame(2, Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))->count());
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $archiveId,
            'channel' => 'discord:arsip',
            'status' => 'SENT',
        ]);

        // Active Discord with neither bucket nor default is a silent skip.
        $this->discord([]);
        $skipped = $this->event('fulfillment.blocked');
        $service->deliver($skipped);
        $this->assertDatabaseMissing('notification_deliveries', [
            'admin_notification_id' => $skipped, 'channel' => 'discord:insiden',
        ]);
    }

    public function test_old_discord_sent_row_prevents_double_send_after_bucket_migration(): void
    {
        $this->discord(['webhook_url_insiden' => self::INCIDENT_WEBHOOK]);
        $id = $this->event();
        DB::table('notification_deliveries')->insert([
            'admin_notification_id' => $id,
            'channel' => 'discord:blocked',
            'destination_hash' => hash('sha256', 'old destination'),
            'status' => 'SENT',
            'response_code' => '204',
            'created_at' => now('UTC'),
        ]);

        app(ExternalNotificationService::class)->deliver($id);
        $this->assertSame(0, Http::recorded(fn ($request) => $request->url() === self::INCIDENT_WEBHOOK)->count());
    }

    public function test_renderer_escapes_untrusted_markup_and_converts_utc_to_wib(): void
    {
        $notification = (object) [
            'event_type' => 'system.healthcheck.failed',
            'severity' => 'WARNING',
            'title' => '<script>jangan</script>',
            'message' => "Layanan: /health/ready\nHasil: gagal",
            'target_type' => 'system',
            'target_id' => 'ready',
            'data' => '{}',
            'created_at' => '2026-11-04 07:02:00',
        ];
        $renderer = app(AdminNotificationRenderer::class);
        $payload = $renderer->fromNotification($notification);
        $tg = $renderer->telegram($payload);
        $discord = $renderer->discord($payload);
        $email = $renderer->email($payload);

        $this->assertSame('PERINGATAN', $payload['level']);
        $this->assertSame('4 Nov 2026, 14.02 WIB', $payload['wib']);
        $this->assertStringContainsString('&lt;script&gt;', $tg['text']);
        $this->assertStringNotContainsString('<script>', $email['html']);
        $this->assertSame(hexdec('B54708'), $discord['embeds'][0]['color']);
        $this->assertStringContainsString('Yang harus dilakukan:', $email['html']);
        $this->assertStringContainsString(route('admin.panel'), $email['html']);
    }
}
