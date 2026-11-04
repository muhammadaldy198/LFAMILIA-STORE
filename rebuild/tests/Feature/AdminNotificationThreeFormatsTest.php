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

    private const TASK_WEBHOOK = 'https://discord.com/api/webhooks/222/task';

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
        $this->telegram(['thread_tugas' => '5678']);
        $this->discord(['webhook_url_tugas' => self::TASK_WEBHOOK]);
        $this->resend();

        $id = $this->event();
        $createdAt = DB::table('admin_notifications')->where('id', $id)->value('created_at');
        // Database stores UTC even when view displays WIB.
        $this->assertNotNull($createdAt);

        app(ExternalNotificationService::class)->deliver($id);

        $telegram = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))->first()[0];
        $discord = Http::recorded(fn ($request) => $request->url() === self::TASK_WEBHOOK)->first()[0];
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

        foreach (['telegram', 'discord:tugas', 'email'] as $channel) {
            $this->assertDatabaseHas('notification_deliveries', [
                'admin_notification_id' => $id,
                'channel' => $channel,
                'status' => 'SENT',
            ]);
        }
    }

    public function test_four_telegram_purposes_use_only_their_configured_thread_ids(): void
    {
        $this->telegram([
            'thread_order' => '101',
            'thread_tugas' => '202',
            'thread_sistem' => '303',
            // Arsip intentionally has no thread.
        ]);
        foreach (['order.created', 'fulfillment.blocked', 'system.healthcheck.failed', 'support.ticket.created'] as $event) {
            app(ExternalNotificationService::class)->deliver($this->event($event));
        }
        $requests = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]);

        $this->assertCount(4, $requests);
        $this->assertSame(101, $requests[0]['message_thread_id']);
        $this->assertSame(202, $requests[1]['message_thread_id']);
        $this->assertSame(303, $requests[2]['message_thread_id']);
        $this->assertArrayNotHasKey('message_thread_id', $requests[3]->data());
    }

    public function test_four_telegram_bots_use_distinct_tokens_and_shared_group(): void
    {
        $this->telegram([
            'bot_token' => '',
            'bot_token_order' => 'order-bot',
            'bot_token_tugas' => 'tugas-bot',
            'bot_token_sistem' => 'sistem-bot',
            'bot_token_arsip' => 'arsip-bot',
            'thread_order' => '101',
            'thread_tugas' => '202',
            'thread_sistem' => '303',
            'thread_arsip' => '404',
        ]);
        foreach (['order.created', 'fulfillment.failed', 'system.healthcheck.failed', 'support.ticket.created'] as $event) {
            app(ExternalNotificationService::class)->deliver($this->event($event));
        }
        $requests = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]);
        $this->assertCount(4, $requests);
        foreach (['order', 'tugas', 'sistem', 'arsip'] as $index => $purpose) {
            $this->assertStringContainsString('/bot'.$purpose.'-bot/test/sendMessage', $requests[$index]->url());
            $this->assertSame('test-chat', $requests[$index]['chat_id']);
            $this->assertSame(($index + 1) * 101, $requests[$index]['message_thread_id']);
        }
    }

    public function test_telegram_uses_default_token_for_missing_purpose_and_silently_skips_without_any_token(): void
    {
        $this->telegram([
            'bot_token' => 'fallback-bot',
            'bot_token_order' => 'order-bot',
            'bot_token_tugas' => '',
        ]);
        $service = app(ExternalNotificationService::class);
        $service->deliver($this->event('order.created'));
        $service->deliver($this->event('fulfillment.failed'));
        $requests = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]);

        $this->assertCount(2, $requests);
        $this->assertStringContainsString('/botorder-bot/test/sendMessage', $requests[0]->url());
        $this->assertStringContainsString('/botfallback-bot/test/sendMessage', $requests[1]->url());

        $this->telegram(['bot_token' => '', 'bot_token_order' => '']);
        $skippedId = $this->event('order.created');
        $service->deliver($skippedId);
        $this->assertSame(2, Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))->count());
        $this->assertDatabaseMissing('notification_deliveries', [
            'admin_notification_id' => $skippedId,
            'channel' => 'telegram',
        ]);
    }

    public function test_discord_fallback_unconfigured_destinations_and_dedupe(): void
    {
        $this->discord([
            'webhook_url' => self::FALLBACK_WEBHOOK,
            'webhook_url_order' => self::ORDER_WEBHOOK,
        ]);
        $this->telegram();

        $orderId = $this->event('order.created', 'INFO');
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
            'admin_notification_id' => $skipped, 'channel' => 'discord:tugas',
        ]);
    }

    public function test_old_discord_sent_row_prevents_double_send_after_bucket_migration(): void
    {
        $this->discord(['webhook_url_tugas' => self::TASK_WEBHOOK]);
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
        $this->assertSame(0, Http::recorded(fn ($request) => $request->url() === self::TASK_WEBHOOK)->count());
    }

    public function test_discord_embed_respects_combined_character_budget(): void
    {
        $renderer = app(AdminNotificationRenderer::class);
        $payload = $renderer->fromNotification((object) [
            'event_type' => 'integration.error',
            'severity' => 'ERROR',
            'title' => 'Webhook warning',
            'message' => implode("\n", array_map(
                fn (int $i): string => 'Fakta '.$i.': '.str_repeat('X', 1000),
                range(1, 10)
            )),
            'target_type' => null,
            'target_id' => null,
            'data' => null,
            'created_at' => '2026-11-04 07:02:00',
        ]);
        $embed = $renderer->discord($payload)['embeds'][0];
        $length = mb_strlen($embed['title']) + mb_strlen($embed['footer']['text']);
        foreach ($embed['fields'] as $field) {
            $length += mb_strlen($field['name']) + mb_strlen($field['value']);
        }

        $this->assertLessThanOrEqual(6000, $length);
        $this->assertNotEmpty($embed['fields']);
    }

    public function test_fulfillment_attempt_metadata_links_to_specific_admin_order(): void
    {
        $renderer = app(AdminNotificationRenderer::class);
        $payload = $renderer->fromNotification((object) [
            'event_type' => 'fulfillment.failed',
            'severity' => 'ERROR',
            'title' => 'Pengiriman Gagal',
            'message' => 'Penyedia mengembalikan kesalahan.',
            'target_type' => 'fulfillment_attempt',
            'target_id' => '88',
            'data' => json_encode(['order_id' => 107], JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-04 07:02:00',
        ]);

        $this->assertSame(route('admin.orders.show', ['id' => 107]), $payload['url']);
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
