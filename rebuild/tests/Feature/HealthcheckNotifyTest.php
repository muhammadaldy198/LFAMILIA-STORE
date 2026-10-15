<?php

namespace Tests\Feature;

use App\Jobs\SendAdminNotificationJob;
use App\Models\IntegrationCredential;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
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

    public function test_invalid_state_fails(): void
    {
        $this->assertSame(1, $this->notify('meh'));
        $this->assertSame(0, DB::table('admin_notifications')->count());
    }
}
