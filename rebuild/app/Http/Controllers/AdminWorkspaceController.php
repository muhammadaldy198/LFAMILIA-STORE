<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminWorkspaceController
{
    public function audit(): Response
    {
        return Inertia::render('Admin/Workspace', [
            'kind' => 'audit',
            'title' => 'Audit Log',
            'rows' => DB::table('audit_logs')->orderByDesc('id')->paginate(50),
        ]);
    }

    public function health(): Response
    {
        $checks = [[
            'name' => 'Laravel',
            'status' => 'HEALTHY',
            'message' => 'Laravel '.app()->version().' berjalan.',
        ]];

        $queueHeartbeat = DB::table('system_settings')->where('key', 'system.queue_worker_heartbeat')->value('value');
        $queueHeartbeatAt = json_decode((string) $queueHeartbeat, true);
        $healthyQueue = is_string($queueHeartbeatAt)
            && now()->diffInMinutes(Carbon::parse($queueHeartbeatAt), true) <= 3;
        $checks[] = [
            'name' => 'Queue',
            'status' => $healthyQueue ? 'HEALTHY' : 'DEGRADED',
            'message' => $healthyQueue
                ? 'Worker queue '.config('queue.default').' aktif. Heartbeat '.$queueHeartbeatAt
                : 'Worker queue belum memberi heartbeat dalam 3 menit terakhir.',
        ];

        try {
            DB::select('SELECT 1');
            $checks[] = ['name' => 'MySQL', 'status' => 'HEALTHY', 'message' => 'Database dapat diakses.'];
        } catch (Throwable) {
            $checks[] = ['name' => 'MySQL', 'status' => 'DOWN', 'message' => 'Database tidak dapat diakses.'];
        }

        try {
            Redis::connection()->ping();
            $checks[] = ['name' => 'Redis', 'status' => 'HEALTHY', 'message' => 'Redis dapat diakses.'];
        } catch (Throwable) {
            $checks[] = ['name' => 'Redis', 'status' => 'DOWN', 'message' => 'Redis tidak dapat diakses.'];
        }

        $free = @disk_free_space(storage_path());
        $checks[] = [
            'name' => 'Storage',
            'status' => is_numeric($free) && $free > 512 * 1024 * 1024 ? 'HEALTHY' : 'DEGRADED',
            'message' => is_numeric($free) ? 'Free '.round($free / 1024 / 1024).' MB' : 'Kapasitas tidak dapat dibaca.',
        ];

        $heartbeat = DB::table('system_settings')->where('key', 'system.scheduler_heartbeat')->value('value');
        $heartbeatAt = json_decode((string) $heartbeat, true);
        $healthyScheduler = is_string($heartbeatAt)
            && now()->diffInMinutes(Carbon::parse($heartbeatAt), true) <= 3;
        $checks[] = [
            'name' => 'Scheduler',
            'status' => $healthyScheduler ? 'HEALTHY' : 'DEGRADED',
            'message' => $heartbeatAt ?: 'Heartbeat belum tercatat.',
        ];

        foreach (['digiflazz', 'kokinpay', 'midtrans', 'doku', 'resend'] as $code) {
            $active = DB::table('integration_credentials')->where('code', $code)->where('is_active', true)->exists();
            $stored = DB::table('system_settings')->where('key', 'integration.health.'.$code)->value('value');
            $health = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
            $status = $active ? (string) ($health['status'] ?? 'DEGRADED') : 'NOT_CONFIGURED';
            $message = $active
                ? (string) ($health['message'] ?? 'Credential aktif; Tes Koneksi belum dijalankan.')
                : 'Belum aktif.';

            if (in_array($code, ['midtrans', 'doku'], true)) {
                $gateway = DB::table('payment_gateways')
                    ->where('code', strtoupper($code))->first();
                if ($gateway?->is_maintenance) {
                    $status = 'MAINTENANCE';
                    $message = 'Gateway sedang maintenance.';
                }
            }

            $checks[] = [
                'name' => strtoupper($code),
                'status' => $status,
                'message' => $message,
            ];
        }

        return Inertia::render('Admin/Workspace', [
            'kind' => 'health',
            'title' => 'System Health',
            'checks' => $checks,
        ]);
    }

}

