<?php

namespace Tests\Feature;

use App\Jobs\SendAdminNotificationJob;
use App\Models\IntegrationCredential;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Keputusan D8 (grand opening): healthcheck kirim kabar saat gagal
 * (ke Telegram + Discord channel VPS). Alert gagal di-dedupe 30 menit;
 * kabar pulih hanya dikirim bila sebelumnya ada alert gagal.
 */
class HealthcheckNotifyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function notify(string $state, ?string $reason = null): int
    {
        $params = ['state' => $state];
        if ($reason !== null) {
            $params['--reason'] = $reason;
        }

        return Artisan::call('lfamilia:notify-healthcheck', $params);
    }

    public function test_down_records_vps_notification_with_reason(): void
    {
        $this->assertSame(0, $this->notify('down', 'Edge readiness is not healthy'));

        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'system.healthcheck.failed',
            'severity' => 'ERROR',
            'target_type' => 'system',
            'target_id' => 'healthcheck',
        ]);
        $row = DB::table('admin_notifications')->where('event_type', 'system.healthcheck.failed')->first();
        $this->assertStringContainsString('Edge readiness is not healthy', (string) $row->message);
    }

    public function test_down_is_deduped_within_thirty_minutes(): void
    {
        $this->notify('down', 'Edge readiness is not healthy');
        $this->notify('down', 'Origin readiness is not healthy');

        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
    }

    public function test_down_after_recovery_alerts_again_within_thirty_minutes(): void
    {
        // down → up → down dalam 30 menit = outage BARU: alert kedua
        // wajib bunyi, bukan ditelan dedupe (dedupe hanya untuk outage
        // yang sama yang belum pulih).
        $this->notify('down', 'Edge readiness is not healthy');
        $this->notify('up');
        $this->notify('down', 'Origin readiness is not healthy');

        $this->assertSame(2, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.recovered')->count());
    }

    public function test_up_retries_only_failed_delivery_channels_without_redis_or_duplicate_rows(): void
    {
        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => ['test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat']],
            ],
        ]);
        IntegrationCredential::create([
            'code' => 'discord',
            'is_active' => true,
            'config_ciphertext' => ['webhook_url' => 'https://discord.com/api/webhooks/123/test-token'],
        ]);

        Http::swap(new Factory);
        $discordAttempts = 0;
        Http::fake([
            '*' => function ($request) use (&$discordAttempts) {
                if (str_contains($request->url(), 'sendMessage')) {
                    return Http::response(['ok' => true], 200);
                }
                if (str_contains($request->url(), 'discord.com/api/webhooks')) {
                    $discordAttempts++;

                    return $discordAttempts === 2
                        ? Http::response([], 500)
                        : Http::response([], 204);
                }

                return Http::response([], 200);
            },
        ]);
        Queue::fake();
        Cache::shouldReceive('lock')->never();
        $telegramPosts = fn () => Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))->count();
        $discordPosts = fn () => Http::recorded(fn ($r) => str_contains($r->url(), 'discord.com/api/webhooks'))->count();

        $this->assertSame(0, $this->notify('down', 'Outage'));
        $this->assertSame(0, $this->notify('up'));
        $recoveryId = (int) DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.recovered')->value('id');
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $recoveryId, 'channel' => 'telegram', 'status' => 'SENT',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $recoveryId, 'channel' => 'discord:vps', 'status' => 'FAILED',
        ]);
        $this->assertSame(2, $telegramPosts());
        $this->assertSame(2, $discordPosts());

        $this->assertSame(0, $this->notify('up'));
        $this->assertSame(2, $telegramPosts());
        $this->assertSame(3, $discordPosts());
        $this->assertDatabaseHas('notification_deliveries', [
            'admin_notification_id' => $recoveryId, 'channel' => 'discord:vps', 'status' => 'SENT',
        ]);

        // All channels are now SENT; a fourth 'up' does not resend.
        $this->assertSame(0, $this->notify('up'));
        $this->assertSame(2, $telegramPosts());
        $this->assertSame(3, $discordPosts());
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.recovered')->count());
        Queue::assertNotPushed(SendAdminNotificationJob::class);
    }

    public function test_up_without_prior_down_records_nothing(): void
    {
        $this->assertSame(0, $this->notify('up'));

        $this->assertSame(0, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.recovered')->count());
    }

    public function test_up_after_down_records_recovery_exactly_once(): void
    {
        $this->notify('down', 'Edge readiness is not healthy');
        $this->notify('up');
        $this->notify('up');

        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.recovered')->count());
    }

    public function test_down_delivers_synchronously_without_redis_queue_or_lock(): void
    {
        // Jalur alert healthcheck tidak boleh bergantung Redis: bila
        // Redis mati, dispatch job ke antrean Redis melempar dan lock
        // Redis melempar — alert hilang total (tidak ada sweeper yang
        // mengirimkan ulang baris tertunda). Bukti jalur baru:
        // job TIDAK di-push, Cache::lock TIDAK PERNAH dipanggil,
        // tapi pengiriman sinkron tetap jalan (post Telegram terkirim).
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
        Queue::fake();
        Cache::shouldReceive('lock')->never();

        $this->assertSame(0, $this->notify('down', 'Redis is down'));

        Queue::assertNotPushed(SendAdminNotificationJob::class);
        $this->assertSame(1, Http::recorded(
            fn ($request) => str_contains($request->url(), 'sendMessage')
        )->count());
    }

    public function test_down_retries_failed_delivery_on_next_run_and_does_not_resend_after_success(): void
    {
        // Satu kegagalan transient TIDAK BOLEH membungkam retry
        // sampai 30 menit: run berikutnya mengirim ULANG notifikasi
        // yang SAMA (ID sama, tanpa baris baru), dan setelah sukses
        // run berikutnya diam (tidak spam — channel SENT dilewati).
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
        Http::swap(new Factory);
        $attempts = 0;
        Http::fake([
            '*' => function ($request) use (&$attempts) {
                if (str_contains($request->url(), 'sendMessage')) {
                    $attempts++;

                    return $attempts === 1
                        ? Http::response([], 500)
                        : Http::response(['ok' => true], 200);
                }

                return Http::response([], 200);
            },
        ]);
        $sent = fn () => Http::recorded(
            fn ($request) => str_contains($request->url(), 'sendMessage')
        )->count();

        // Run 1: delivery gagal (respons non-sukses).
        $this->assertSame(0, $this->notify('down', 'Flaky provider'));
        $this->assertSame(1, $sent());
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());

        // Run 2 (outage sama, 5 menit kemudian): RETRY, bukan diam.
        $this->assertSame(0, $this->notify('down', 'Flaky provider'));
        $this->assertSame(2, $sent());
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'SENT']);

        // Run 3: sudah SENT → diam, tidak kirim ulang.
        $this->assertSame(0, $this->notify('down', 'Flaky provider'));
        $this->assertSame(2, $sent());
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
    }

    public function test_down_retry_sends_only_failed_channels_not_already_sent(): void
    {
        // Sukses SEBAGIAN: Telegram kekirim, Discord gagal → retry
        // hanya mengirim Discord (yang gagal), TIDAK spam Telegram
        // yang sudah SENT.
        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => ['test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat']],
            ],
        ]);
        IntegrationCredential::create([
            'code' => 'discord',
            'is_active' => true,
            'config_ciphertext' => ['webhook_url' => 'https://discord.com/api/webhooks/123/test-token'],
        ]);
        Http::swap(new Factory);
        $discordAttempts = 0;
        Http::fake([
            '*' => function ($request) use (&$discordAttempts) {
                if (str_contains($request->url(), 'sendMessage')) {
                    return Http::response(['ok' => true], 200);
                }
                if (str_contains($request->url(), 'discord.com/api/webhooks')) {
                    $discordAttempts++;

                    return $discordAttempts === 1
                        ? Http::response([], 500)
                        : Http::response([], 204);
                }

                return Http::response([], 200);
            },
        ]);
        $telegramPosts = fn () => Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))->count();
        $discordPosts = fn () => Http::recorded(fn ($r) => str_contains($r->url(), 'discord.com/api/webhooks'))->count();

        $this->assertSame(0, $this->notify('down', 'Partial failure'));
        $this->assertSame(1, $telegramPosts());
        $this->assertSame(1, $discordPosts());
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'telegram', 'status' => 'SENT']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'discord:vps', 'status' => 'FAILED']);

        // Retry: Telegram TIDAK dikirim ulang, Discord dicoba lagi.
        $this->assertSame(0, $this->notify('down', 'Partial failure'));
        $this->assertSame(1, $telegramPosts());
        $this->assertSame(2, $discordPosts());
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'discord:vps', 'status' => 'SENT']);
    }

    public function test_down_retries_notification_recorded_but_never_delivered(): void
    {
        // Record sukses tapi proses mati sebelum deliver (baris
        // ada, tanpa delivery) → run berikutnya harus RETRY
        // (mengirim baris itu), bukan diam karena barisnya ada.
        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => ['test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat']],
            ],
        ]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        DB::table('admin_notifications')->insert([
            'event_type' => 'system.healthcheck.failed',
            'severity' => 'ERROR',
            'title' => 'Website tidak sehat - healthcheck gagal',
            'message' => 'Healthcheck gagal: proses mati sebelum deliver.',
            'target_type' => 'system',
            'target_id' => 'healthcheck',
            'created_at' => now(),
        ]);
        $this->assertSame(0, DB::table('notification_deliveries')->count());

        $this->assertSame(0, $this->notify('down', 'Healthcheck gagal: proses mati sebelum deliver.'));
        $this->assertSame(1, Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))->count());
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'SENT']);
        // Tetap SATU baris — retry memakai ID yang sama.
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
    }

    public function test_down_realerts_with_new_row_after_thirty_minutes_of_failure(): void
    {
        // Batas retry: dalam 30 menit, retry memakai ID yang sama
        // (tanpa baris baru). Setelah 30 menit outage masih gagal,
        // re-alert dengan baris BARU — retry tiap 5 menit TIDAK
        // tanpa batas membuat baris baru, dan dedupe 30 menit
        // setelah SUKSES tetap menahan spam.
        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => ['test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat']],
            ],
        ]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 500)]);

        $this->assertSame(0, $this->notify('down', 'Provider mati'));
        $this->assertSame(0, $this->notify('down', 'Provider mati'));
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
        $this->assertSame(2, Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))->count());

        Carbon::setTestNow(now()->addMinutes(31));
        try {
            $this->assertSame(0, $this->notify('down', 'Provider mati'));
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(2, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
        $this->assertSame(3, Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))->count());
    }

    public function test_down_retry_stays_free_of_redis_queue_and_lock(): void
    {
        // Jalur retry TETAP bebas Redis: tidak ada job antrean,
        // Cache::lock tidak pernah dipanggil — termasuk pada run
        // retry (bukan hanya run pertama).
        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => ['test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat']],
            ],
        ]);
        Queue::fake();
        Cache::shouldReceive('lock')->never();
        Http::swap(new Factory);
        $attempts = 0;
        Http::fake([
            '*' => function ($request) use (&$attempts) {
                if (str_contains($request->url(), 'sendMessage')) {
                    $attempts++;

                    return $attempts === 1
                        ? Http::response([], 500)
                        : Http::response(['ok' => true], 200);
                }

                return Http::response([], 200);
            },
        ]);

        $this->assertSame(0, $this->notify('down', 'Redis is down'));
        $this->assertSame(0, $this->notify('down', 'Redis is down'));

        Queue::assertNotPushed(SendAdminNotificationJob::class);
        $this->assertSame(2, Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))->count());
    }

    public function test_flapping_down_up_down_up_sends_no_duplicate_alert_or_recovery(): void
    {
        // Flapping down→down(retry)→up→down→up: tiap outage baru
        // bunyi sekali, tiap recovery tercatat sekali — retry tidak
        // boleh kirim alert ganda / recovery palsu. Dua POST pertama
        // gagal (down + retry-nya), sisanya sukses.
        IntegrationCredential::create([
            'code' => 'telegram',
            'is_active' => true,
            'config_ciphertext' => [
                'environment' => 'test',
                'profiles' => ['test' => ['bot_token' => '111:test-token', 'chat_id' => 'test-chat']],
            ],
        ]);
        Http::swap(new Factory);
        $attempts = 0;
        Http::fake([
            '*' => function ($request) use (&$attempts) {
                if (str_contains($request->url(), 'sendMessage')) {
                    $attempts++;

                    return $attempts <= 2
                        ? Http::response([], 500)
                        : Http::response(['ok' => true], 200);
                }

                return Http::response([], 200);
            },
        ]);

        $this->assertSame(0, $this->notify('down', 'Flap 1')); // gagal
        $this->assertSame(0, $this->notify('down', 'Flap 1')); // retry, gagal
        $this->assertSame(0, $this->notify('up')); // recovery tercatat (delivery gagal, baris tetap menutup outage)
        $this->assertSame(0, $this->notify('down', 'Flap 2')); // outage BARU, sukses
        $this->assertSame(0, $this->notify('up')); // recovery kedua, sukses

        // Outage pertama = 1 baris (retry pakai ID sama), outage
        // kedua = 1 baris baru; recovery tercatat sekali per outage.
        $this->assertSame(2, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')->count());
        $this->assertSame(2, DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.recovered')->count());
        // Satu POST per aksi (down, retry, up, down, up) — tidak
        // ada alert/recovery ganda dari retry.
        $this->assertSame(5, Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage'))->count());
    }

    public function test_invalid_state_fails(): void
    {
        $notificationCount = DB::table('admin_notifications')->count();

        $this->assertSame(1, $this->notify('meh'));
        $this->assertSame($notificationCount, DB::table('admin_notifications')->count());
    }
}
