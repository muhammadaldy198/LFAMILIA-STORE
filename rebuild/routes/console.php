<?php

use App\Jobs\QueueHeartbeatJob;
use App\Jobs\ReconcileFulfillmentJob;
use App\Jobs\SendFulfillmentJob;
use App\Jobs\StartFulfillmentJob;
use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Services\AdminNotificationService;
use App\Services\CustomerCleanupService;
use App\Services\DigiflazzCatalogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

Artisan::command('lfamilia:bootstrap-super-admin', function (): int {
    if (AdminUser::where('role', 'SUPER_ADMIN')->exists()) {
        $this->error('Super Admin sudah ada. Kelola akses berikutnya dari panel.');

        return 1;
    }

    $name = $this->ask('Nama Super Admin');
    $email = strtolower(trim((string) $this->ask('Email Super Admin')));
    $password = $this->secret('Kata sandi (minimal 12 karakter)');
    $confirmation = $this->secret('Ulangi kata sandi');

    $data = Validator::make([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $confirmation,
    ], [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', Rule::unique('admin_users', 'email')],
        'password' => ['required', 'string', 'min:12', 'confirmed'],
    ])->validate();

    AdminUser::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
        'role' => 'SUPER_ADMIN',
        'is_active' => true,
    ]);

    $this->info('Super Admin dibuat.');

    return 0;
})->purpose('Bootstrap the first Super Admin without storing credentials in Git');

Artisan::command('lfamilia:cleanup-empty-customers', function (CustomerCleanupService $cleanup): void {
    $count = $cleanup->run();
    $this->info("Akun kosong yang dihapus: {$count}");
})->purpose('Remove inactive empty accounts using owner-controlled retention settings');

Schedule::command('lfamilia:cleanup-empty-customers')->dailyAt('03:30');

Artisan::command('lfamilia:recover-fulfillment', function (): void {
    $queued = 0;

    DB::table('orders')
        ->where('status', 'PAID')
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('fulfillment_attempts')
                ->whereColumn('fulfillment_attempts.order_id', 'orders.id');
        })
        ->orderBy('id')
        ->chunkById(100, function ($orders) use (&$queued): void {
            foreach ($orders as $order) {
                StartFulfillmentJob::dispatch((int) $order->id);
                $queued++;
            }
        });

    DB::table('fulfillment_attempts')
        ->where('status', 'CREATED')
        ->where('updated_at', '<=', now()->subMinute())
        ->orderBy('id')
        ->chunkById(100, function ($attempts) use (&$queued): void {
            foreach ($attempts as $attempt) {
                SendFulfillmentJob::dispatch((int) $attempt->id);
                $queued++;
            }
        });

    DB::table('fulfillment_attempts')
        ->whereIn('status', ['PENDING', 'UNKNOWN', 'SENDING'])
        ->where('updated_at', '<=', now()->subMinutes(2))
        ->orderBy('id')
        ->chunkById(100, function ($attempts) use (&$queued): void {
            foreach ($attempts as $attempt) {
                ReconcileFulfillmentJob::dispatch((int) $attempt->id);
                $queued++;
            }
        });

    $this->info("Fulfillment jobs queued: {$queued}");
})->purpose('Recover paid orders and reconcile uncertain provider attempts without creating duplicate fulfillment');

Schedule::command('lfamilia:recover-fulfillment')->everyMinute()->withoutOverlapping();

Schedule::job(new QueueHeartbeatJob)->everyMinute()->name('lfamilia-queue-worker-heartbeat');

Schedule::call(function (): void {
    DB::table('system_settings')->updateOrInsert(
        ['key' => 'system.scheduler_heartbeat'],
        [
            'value' => json_encode(now()->toIso8601String(), JSON_THROW_ON_ERROR),
            'updated_at' => now(),
            'created_at' => now(),
        ]
    );
})->everyMinute()->name('lfamilia-scheduler-heartbeat')->withoutOverlapping();

Schedule::call(function (): void {
    $notifications = app(AdminNotificationService::class);

    DB::table('fulfillment_attempts')
        ->whereIn('status', ['PENDING', 'UNKNOWN', 'SENDING'])
        ->where('updated_at', '<=', now()->subMinutes(15))
        ->orderBy('id')
        ->limit(100)
        ->get(['id', 'order_id', 'status'])
        ->each(function (object $attempt) use ($notifications): void {
            $already = DB::table('admin_notifications')
                ->where('event_type', 'fulfillment.pending.stale')
                ->where('target_type', 'fulfillment_attempt')
                ->where('target_id', (string) $attempt->id)
                ->exists();
            if (! $already) {
                $notifications->record(
                    'fulfillment.pending.stale',
                    'Fulfillment perlu reconciliation',
                    'Attempt #'.$attempt->id.' berstatus '.$attempt->status.' lebih dari 15 menit.',
                    'WARNING',
                    'fulfillment_attempt',
                    $attempt->id,
                    ['order_id' => (int) $attempt->order_id]
                );
            }
        });
})->everyFiveMinutes()->name('lfamilia-stale-fulfillment-alert')->withoutOverlapping();

Artisan::command('lfamilia:sync-digiflazz-catalog', function (DigiflazzCatalogService $catalog): int {
    $enabled = json_decode((string) DB::table('system_settings')->where('key', 'digiflazz.auto_sync')->value('value'), true) ?? true;
    if (! $enabled || ! IntegrationCredential::where('code', 'digiflazz')->where('is_active', true)->exists()) {
        $this->info('Sinkron otomatis nonaktif atau integrasi belum dikonfigurasi.');

        return 0;
    }

    $interval = max(5, min(1440, (int) (json_decode(
        (string) DB::table('system_settings')->where('key', 'digiflazz.auto_sync_interval_minutes')->value('value'),
        true
    ) ?? 15)));
    $last = json_decode(
        (string) DB::table('system_settings')->where('key', 'digiflazz.last_auto_sync')->value('value'),
        true
    );
    if (is_array($last) && !empty($last['at'])) {
        try {
            if (Carbon::parse($last['at'])->gt(now()->subMinutes($interval))) {
                $this->info('Belum mencapai jadwal sinkron otomatis berikutnya.');

                return 0;
            }
        } catch (Throwable) {
            // Invalid historical timestamp is ignored so the next sync can repair the state.
        }
    }

    try {
        $count = $catalog->sync();
        DB::table('system_settings')->updateOrInsert(['key' => 'digiflazz.last_auto_sync'], [
            'value' => json_encode(['at' => now()->toIso8601String(), 'count' => $count]), 'updated_at' => now(),
        ]);
        $this->info("SKU disinkronkan: {$count}");

        return 0;
    } catch (Throwable $error) {
        Log::warning('Automatic Digiflazz catalog sync failed.', ['exception_class' => $error::class]);
        $this->error('Sinkron gagal; harga tersimpan dipertahankan. Periksa Integrasi dan coba sinkron manual.');

        return 1;
    }
})->purpose('Refresh supplier costs and availability while preserving customer margin settings');

Schedule::command('lfamilia:sync-digiflazz-catalog')->everyFiveMinutes()->withoutOverlapping();
