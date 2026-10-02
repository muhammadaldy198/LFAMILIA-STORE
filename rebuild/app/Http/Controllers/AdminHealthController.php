<?php

namespace App\Http\Controllers;

use App\Services\IntegrationRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminHealthController
{
    private const HEALTHY_HEARTBEAT_SECONDS = 180;

    public function index(IntegrationRegistry $registry): Response
    {
        $checkedAt = now();
        $checks = [[
            'key' => 'application',
            'name' => 'Aplikasi',
            'status' => 'HEALTHY',
            'message' => 'Laravel berjalan dan halaman Admin dapat dirender.',
            'detail' => 'Laravel '.app()->version().' · PHP '.PHP_VERSION,
            'checked_at' => $checkedAt->toIso8601String(),
        ]];

        $databaseHealthy = false;
        try {
            DB::select('SELECT 1');
            $databaseHealthy = true;
            $checks[] = [
                'key' => 'database',
                'name' => 'MySQL',
                'status' => 'HEALTHY',
                'message' => 'Database dapat diakses.',
                'detail' => 'Koneksi '.config('database.default'),
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        } catch (Throwable) {
            $checks[] = [
                'key' => 'database',
                'name' => 'MySQL',
                'status' => 'DOWN',
                'message' => 'Database tidak dapat diakses.',
                'detail' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        try {
            Redis::connection()->ping();
            $checks[] = [
                'key' => 'redis',
                'name' => 'Redis',
                'status' => 'HEALTHY',
                'message' => 'Redis dapat diakses.',
                'detail' => 'Cache, queue, dan sesi menggunakan konfigurasi aplikasi.',
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        } catch (Throwable) {
            $checks[] = [
                'key' => 'redis',
                'name' => 'Redis',
                'status' => 'DOWN',
                'message' => 'Redis tidak dapat diakses.',
                'detail' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        $checks[] = $this->storageCheck($checkedAt);

        if ($databaseHealthy) {
            $checks[] = $this->heartbeatCheck(
                'system.queue_worker_heartbeat',
                'queue',
                'Queue Worker',
                'Worker queue belum memberi heartbeat dalam 3 menit terakhir.',
                $checkedAt,
            );
            $checks[] = $this->heartbeatCheck(
                'system.scheduler_heartbeat',
                'scheduler',
                'Scheduler',
                'Scheduler belum memberi heartbeat dalam 3 menit terakhir.',
                $checkedAt,
            );
            $checks[] = $this->failedJobsCheck($checkedAt);
            $checks[] = $this->fulfillmentCheck($checkedAt);
        } else {
            foreach ([
                ['queue', 'Queue Worker'],
                ['scheduler', 'Scheduler'],
                ['failed_jobs', 'Failed Jobs'],
                ['fulfillment', 'Rekonsiliasi Pesanan'],
            ] as [$key, $name]) {
                $checks[] = [
                    'key' => $key,
                    'name' => $name,
                    'status' => 'UNKNOWN',
                    'message' => 'Status tidak dapat dibaca karena database tidak tersedia.',
                    'detail' => null,
                    'checked_at' => $checkedAt->toIso8601String(),
                ];
            }
        }

        $integrations = $databaseHealthy
            ? $this->integrationChecks($registry)
            : collect($registry->all())->map(fn (array $definition, string $code): array => [
                'code' => $code,
                'name' => $definition['name'],
                'group' => $definition['group'] ?? 'Lainnya',
                'active' => false,
                'status' => 'UNKNOWN',
                'message' => 'Status belum dapat dibaca.',
                'tested_at' => null,
            ])->values()->all();

        $gateways = $databaseHealthy ? $this->gatewayChecks() : [];
        $failedJobs = collect($checks)->firstWhere('key', 'failed_jobs')['count'] ?? null;
        $staleFulfillment = collect($checks)->firstWhere('key', 'fulfillment')['count'] ?? null;

        $critical = collect($checks)->where('status', 'DOWN')->count()
            + collect($integrations)
                ->filter(fn (array $item): bool => $item['active'] && $item['status'] === 'DOWN')
                ->count();
        $attention = collect($checks)->whereIn('status', ['DEGRADED', 'DOWN', 'UNKNOWN'])->count();
        $integrationAttention = collect($integrations)
            ->filter(fn (array $item): bool => $item['active'] && $item['status'] !== 'HEALTHY')
            ->count();

        // Midtrans/DOKU maintenance is already represented by their active integration
        // row. Count only gateway problems that are not already represented there so
        // the summary stays actionable without double-counting the same condition.
        $activeIntegrationCodes = collect($integrations)
            ->filter(fn (array $item): bool => $item['active'])
            ->map(fn (array $item): string => strtoupper((string) $item['code']))
            ->all();
        $gatewayAttention = collect($gateways)
            ->filter(fn (array $gateway): bool => $gateway['active']
                && $gateway['status'] !== 'HEALTHY'
                && ! in_array(strtoupper((string) $gateway['code']), $activeIntegrationCodes, true))
            ->count();

        $overall = $critical > 0
            ? 'DOWN'
            : ($attention > 0 || $integrationAttention > 0 || $gatewayAttention > 0 ? 'DEGRADED' : 'HEALTHY');

        return Inertia::render('Admin/Health', [
            'summary' => [
                'overall' => $overall,
                'healthy_core' => collect($checks)->where('status', 'HEALTHY')->count(),
                'core_total' => count($checks),
                'attention' => $attention + $integrationAttention + $gatewayAttention,
                'failed_jobs' => $failedJobs,
                'stale_fulfillment' => $staleFulfillment,
            ],
            'checks' => $checks,
            'integrations' => $integrations,
            'gateways' => $gateways,
            'environment' => [
                'app_env' => (string) config('app.env'),
                'debug_enabled' => (bool) config('app.debug'),
                'queue_connection' => (string) config('queue.default'),
                'cache_store' => (string) config('cache.default'),
                'session_driver' => (string) config('session.driver'),
            ],
            'checkedAt' => $checkedAt->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storageCheck(Carbon $checkedAt): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if (! is_numeric($free) || ! is_numeric($total) || $total <= 0) {
            return [
                'key' => 'storage',
                'name' => 'Storage',
                'status' => 'DEGRADED',
                'message' => 'Kapasitas storage tidak dapat dibaca.',
                'detail' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        $minimum = 512 * 1024 * 1024;
        $status = $free > $minimum ? 'HEALTHY' : 'DEGRADED';

        return [
            'key' => 'storage',
            'name' => 'Storage',
            'status' => $status,
            'message' => $status === 'HEALTHY'
                ? 'Ruang storage masih tersedia.'
                : 'Sisa storage kurang dari 512 MB.',
            'detail' => $this->formatBytes((float) $free).' bebas dari '.$this->formatBytes((float) $total),
            'checked_at' => $checkedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeatCheck(
        string $settingKey,
        string $key,
        string $name,
        string $staleMessage,
        Carbon $checkedAt,
    ): array {
        $raw = DB::table('system_settings')->where('key', $settingKey)->value('value');
        $decoded = json_decode((string) $raw, true);

        if (! is_string($decoded) || trim($decoded) === '') {
            return [
                'key' => $key,
                'name' => $name,
                'status' => 'DEGRADED',
                'message' => 'Heartbeat belum tercatat.',
                'detail' => null,
                'last_seen_at' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        try {
            $lastSeen = Carbon::parse($decoded);
        } catch (Throwable) {
            return [
                'key' => $key,
                'name' => $name,
                'status' => 'DEGRADED',
                'message' => 'Heartbeat tersimpan tidak valid.',
                'detail' => null,
                'last_seen_at' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        $ageSeconds = $checkedAt->getTimestamp() - $lastSeen->getTimestamp();
        $healthy = $ageSeconds >= -60 && $ageSeconds <= self::HEALTHY_HEARTBEAT_SECONDS;

        return [
            'key' => $key,
            'name' => $name,
            'status' => $healthy ? 'HEALTHY' : 'DEGRADED',
            'message' => $healthy ? $name.' aktif.' : $staleMessage,
            'detail' => $ageSeconds < -60
                ? 'Waktu heartbeat lebih maju dari waktu aplikasi.'
                : 'Heartbeat terakhir '.$lastSeen->toIso8601String(),
            'last_seen_at' => $lastSeen->toIso8601String(),
            'age_seconds' => $ageSeconds,
            'checked_at' => $checkedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobsCheck(Carbon $checkedAt): array
    {
        try {
            $count = DB::table('failed_jobs')->count();
            $latest = DB::table('failed_jobs')->max('failed_at');
        } catch (Throwable) {
            return [
                'key' => 'failed_jobs',
                'name' => 'Failed Jobs',
                'status' => 'UNKNOWN',
                'message' => 'Antrean gagal tidak dapat dibaca.',
                'detail' => null,
                'count' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        return [
            'key' => 'failed_jobs',
            'name' => 'Failed Jobs',
            'status' => $count === 0 ? 'HEALTHY' : 'DEGRADED',
            'message' => $count === 0
                ? 'Tidak ada job gagal yang tersimpan.'
                : $count.' job gagal perlu diperiksa.',
            'detail' => $latest ? 'Kegagalan terbaru '.$latest : null,
            'count' => $count,
            'checked_at' => $checkedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fulfillmentCheck(Carbon $checkedAt): array
    {
        try {
            $latestIds = DB::table('fulfillment_attempts')
                ->selectRaw('MAX(id) AS id')
                ->groupBy('order_id');

            $count = DB::table('fulfillment_attempts as attempts')
                ->joinSub($latestIds, 'latest_attempts', fn ($join) => $join->on('latest_attempts.id', '=', 'attempts.id'))
                ->whereIn('attempts.status', ['PENDING', 'UNKNOWN', 'SENDING'])
                ->where('attempts.updated_at', '<=', $checkedAt->copy()->subMinutes(15))
                ->count();
        } catch (Throwable) {
            return [
                'key' => 'fulfillment',
                'name' => 'Rekonsiliasi Pesanan',
                'status' => 'UNKNOWN',
                'message' => 'Status proses pesanan tidak dapat dibaca.',
                'detail' => null,
                'count' => null,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        }

        return [
            'key' => 'fulfillment',
            'name' => 'Rekonsiliasi Pesanan',
            'status' => $count === 0 ? 'HEALTHY' : 'DEGRADED',
            'message' => $count === 0
                ? 'Tidak ada proses tidak pasti yang melewati 15 menit.'
                : $count.' proses perlu rekonsiliasi.',
            'detail' => 'PENDING / UNKNOWN / SENDING lebih dari 15 menit.',
            'count' => $count,
            'checked_at' => $checkedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function integrationChecks(IntegrationRegistry $registry): array
    {
        $definitions = $registry->all();
        $codes = array_keys($definitions);
        $records = DB::table('integration_credentials')
            ->whereIn('code', $codes)
            ->get(['code', 'is_active'])
            ->keyBy('code');

        $health = DB::table('system_settings')
            ->whereIn('key', collect($codes)->map(fn (string $code): string => 'integration.health.'.$code)->all())
            ->pluck('value', 'key');

        $maintenance = DB::table('payment_gateways')
            ->whereIn('code', ['MIDTRANS', 'DOKU'])
            ->pluck('is_maintenance', 'code');

        return collect($definitions)->map(function (array $definition, string $code) use ($records, $health, $maintenance): array {
            $active = (bool) ($records->get($code)?->is_active);
            $stored = json_decode((string) $health->get('integration.health.'.$code, '{}'), true);
            $stored = is_array($stored) ? $stored : [];

            $status = $active
                ? $this->normalizeStatus((string) ($stored['status'] ?? 'UNTESTED'))
                : 'NOT_CONFIGURED';

            if ($active && in_array($code, ['midtrans', 'doku'], true)
                && (bool) $maintenance->get(strtoupper($code), false)) {
                $status = 'MAINTENANCE';
            }

            return [
                'code' => $code,
                'name' => $definition['name'],
                'group' => $definition['group'] ?? 'Lainnya',
                'active' => $active,
                'status' => $status,
                'message' => $this->integrationMessage($status),
                'tested_at' => $this->safeTimestamp($stored['tested_at'] ?? null),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gatewayChecks(): array
    {
        return DB::table('payment_gateways')
            ->orderBy('id')
            ->get(['code', 'internal_name', 'kind', 'is_active', 'is_maintenance'])
            ->map(function (object $gateway): array {
                $status = (bool) $gateway->is_maintenance
                    ? 'MAINTENANCE'
                    : ((bool) $gateway->is_active ? 'HEALTHY' : 'NOT_CONFIGURED');

                return [
                    'code' => (string) $gateway->code,
                    'name' => (string) $gateway->internal_name,
                    'kind' => (string) $gateway->kind,
                    'active' => (bool) $gateway->is_active,
                    'maintenance' => (bool) $gateway->is_maintenance,
                    'status' => $status,
                ];
            })->all();
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtoupper(trim($status));

        return in_array($status, [
            'HEALTHY', 'DEGRADED', 'DOWN', 'NOT_CONFIGURED', 'UNTESTED', 'MAINTENANCE',
        ], true) ? $status : 'UNTESTED';
    }

    private function integrationMessage(string $status): string
    {
        return match ($status) {
            'HEALTHY' => 'Tes koneksi terakhir berhasil.',
            'DEGRADED' => 'Konfigurasi perlu diperiksa atau belum dapat diverifikasi penuh.',
            'DOWN' => 'Tes koneksi terakhir gagal.',
            'MAINTENANCE' => 'Gateway sedang dalam mode maintenance.',
            'NOT_CONFIGURED' => 'Integrasi belum aktif.',
            default => 'Tes koneksi belum dijalankan.',
        };
    }

    private function safeTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / (1024 ** 3), 1).' GB';
        }

        return number_format($bytes / (1024 ** 2), 0).' MB';
    }
}
