<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_invalid_state_fails(): void
    {
        $this->assertSame(1, $this->notify('meh'));
        $this->assertSame(0, DB::table('admin_notifications')->count());
    }
}
