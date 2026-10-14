<?php

namespace Tests\Feature;

use App\Models\IntegrationCredential;
use App\Services\AdminNotificationService;
use App\Services\ExternalNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Routing Discord per jenis notif (keputusan 15 Okt 2026 02:49 WIB):
 * Telegram tetap 1 tujuan dan menerima SEMUA notif; Discord hanya
 * ke channel yang webhook-nya terkonfigurasi (fallback ke webhook
 * default bila ada); pengiriman dicatat terpisah per channel Discord.
 */
class ExternalNotificationRoutingTest extends TestCase
{
    use DatabaseTransactions;

    private const ORDER_WEBHOOK = 'https://discord.com/api/webhooks/111/order';

    private const FULFILLMENT_WEBHOOK = 'https://discord.com/api/webhooks/222/fulfillment';

    private const LEGACY_WEBHOOK = 'https://discord.com/api/webhooks/999/legacy';

    protected function setUp(): void
    {
        parent::setUp();
        // Hermetik: tanpa fake, job notif ikut jalan sync dan kirim ke
        // webhook betulan (ganda + bergantung jaringan).
        Queue::fake();
        Http::fake();
        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => [
                    'test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat'],
                ],
            ],
        ]);
    }

    private function seedDiscord(array $config): void
    {
        IntegrationCredential::create([
            'code' => 'discord',
            'is_active' => true,
            'config_ciphertext' => $config,
        ]);
    }

    private function record(string $eventType): int
    {
        return app(AdminNotificationService::class)->record(
            $eventType, 'Judul '.$eventType, 'Isi '.$eventType, 'INFO', 'order', 'TEST-1',
        );
    }

    private function telegramSends(): int
    {
        return Http::recorded(fn ($request, $response) => str_contains($request->url(), 'sendMessage'))->count();
    }

    public function test_telegram_receives_every_notification_while_discord_skips_unconfigured_channel(): void
    {
        $this->seedDiscord(['webhook_url_order' => self::ORDER_WEBHOOK]);

        $fulfillmentId = $this->record('fulfillment.success');
        $orderId = $this->record('order.created');
        app(ExternalNotificationService::class)->deliver($fulfillmentId);
        app(ExternalNotificationService::class)->deliver($orderId);

        // Telegram: kedua notif sampai (channel fulfillment Discord
        // tidak terkonfigurasi, tapi Telegram tidak ikut ke-skip).
        $this->assertSame(2, $this->telegramSends());
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $fulfillmentId,
            'channel' => 'telegram',
            'status' => 'SENT',
        ]);
        // Discord: hanya notif ORDER yang dikirim, ke webhook ORDER.
        // CATATAN: Http::assertSentCount() hanya menerima $count —
        // callback filter argumen ke-2 DIABAIKAN diam-diam (PHP tidak
        // error untuk argumen berlebih), sehingga ia menghitung TOTAL
        // semua request (2 telegram + 1 discord = 3). Pakai
        // Http::recorded($filter)->count() agar yang dihitung benar-benar
        // post ke webhook ini.
        $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === self::ORDER_WEBHOOK)->count());
        $this->assertDatabaseMissing('notification_deliveries', [
            'admin_notification_id' => $fulfillmentId,
            'channel' => 'discord:fulfillment',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $orderId,
            'channel' => 'discord:order',
            'status' => 'SENT',
        ]);
    }

    public function test_discord_dedupe_is_tracked_per_channel(): void
    {
        $this->seedDiscord([
            'webhook_url_order' => self::ORDER_WEBHOOK,
            'webhook_url_fulfillment' => self::FULFILLMENT_WEBHOOK,
        ]);

        $orderId = $this->record('order.created');
        $fulfillmentId = $this->record('fulfillment.success');

        // Kirim dua kali (seperti job antrean yang retry): tidak dobel.
        $service = app(ExternalNotificationService::class);
        $service->deliver($orderId);
        $service->deliver($orderId);
        $service->deliver($fulfillmentId);
        $service->deliver($fulfillmentId);

        $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === self::ORDER_WEBHOOK)->count());
        $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === self::FULFILLMENT_WEBHOOK)->count());
        // Channel kedua TIDAK ke-skip gara-gara channel pertama SENT:
        // tiap channel punya baris dedupe sendiri.
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $orderId,
            'channel' => 'discord:order',
            'status' => 'SENT',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $fulfillmentId,
            'channel' => 'discord:fulfillment',
            'status' => 'SENT',
        ]);
        $this->assertSame(2, $this->telegramSends());
    }

    public function test_discord_falls_back_to_legacy_webhook_at_deliver_level(): void
    {
        $this->seedDiscord(['webhook_url' => self::LEGACY_WEBHOOK]);

        $orderId = $this->record('order.created');
        $fulfillmentId = $this->record('fulfillment.success');
        $service = app(ExternalNotificationService::class);
        $service->deliver($orderId);
        $service->deliver($fulfillmentId);

        // Tanpa URL per-channel, semua notif jatuh ke webhook lama.
        // (Alasan tidak pakai Http::assertSentCount: lihat catatan di test pertama.)
        $this->assertSame(2, Http::recorded(fn ($request) => $request->url() === self::LEGACY_WEBHOOK)->count());
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $fulfillmentId,
            'channel' => 'discord:fulfillment',
            'status' => 'SENT',
        ]);
    }
}
