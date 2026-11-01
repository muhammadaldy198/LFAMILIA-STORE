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
use App\Services\ExternalNotificationService;
use App\Services\MidtransStatusVerification;
use App\Services\Payment\MidtransGateway;
use App\Services\OperationalSettings;
use App\Services\PaymentStateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    $admin = AdminUser::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
    ]);
    $admin->forceFill([
        'role' => 'SUPER_ADMIN',
        'is_active' => true,
    ])->save();

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
        // Continue checking the same provider reference until final or 24-hour safety limit.
        ->where('created_at', '>=', now()->subDay())
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

Artisan::command('lfamilia:reconcile-pending-payments', function (
    MidtransGateway $midtrans,
    MidtransStatusVerification $verification,
    PaymentStateService $states,
): int {
    $checked = 0;
    $resolved = 0;

    // Only read existing transactions. Never create a new invoice or send funds.
    DB::table('payment_transactions')
        ->where('gateway_code', 'MIDTRANS')
        ->whereIn('status', ['PENDING', 'UNKNOWN', 'SENDING'])
        ->whereNotNull('merchant_reference')
        ->where('created_at', '>=', now()->subDays(2))
        ->where('created_at', '<=', now()->subMinutes(2))
        ->where('updated_at', '<=', now()->subMinutes(2))
        ->orderBy('id')
        ->chunkById(100, function ($payments) use ($midtrans, $verification, $states, &$checked, &$resolved): void {
            foreach ($payments as $payment) {
                // Atomically throttle simultaneous scheduler invocations and retries.
                $claimed = DB::table('payment_transactions')
                    ->where('id', $payment->id)
                    ->where('status', $payment->status)
                    ->where('updated_at', $payment->updated_at)
                    ->update(['updated_at' => now()]);
                if ($claimed !== 1) {
                    continue;
                }

                $checked++;
                try {
                    $verified = $midtrans->status((string) $payment->merchant_reference);
                    if (hash_equals((string) $payment->merchant_reference, (string) $verified['order_id']) === false
                        || $verification->amount($verified['gross_amount']) !== (int) $payment->amount_idr) {
                        Log::warning('Scheduled payment verification rejected', [
                            'payment_transaction_id' => $payment->id,
                            'reason' => 'IDENTITY_OR_AMOUNT_MISMATCH',
                        ]);
                        continue;
                    }

                    $status = $verification->status($verified);
                    $states->apply((int) $payment->id, $status, ['source' => 'scheduled_status_check']);
                    if ($status !== 'PENDING') {
                        $resolved++;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Scheduled payment status unavailable', [
                        'payment_transaction_id' => $payment->id,
                        'exception' => class_basename($exception),
                    ]);
                }
            }
        });

    $this->info("Payment status checks: {$checked}; terminal responses: {$resolved}");

    return 0;
})->purpose('Recover payment settlements missed by callbacks using verified Midtrans status');

Schedule::command('lfamilia:reconcile-pending-payments')->everyMinute()->withoutOverlapping(10);

Artisan::command('lfamilia:expire-unpaid-orders', function (PaymentStateService $states): int {
    $expired = 0;
    DB::table('orders')
        ->where('status', 'PENDING_PAYMENT')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->orderBy('id')
        ->chunkById(100, function ($orders) use ($states, &$expired): void {
            foreach ($orders as $candidate) {
                DB::transaction(function () use ($states, $candidate, &$expired): void {
                    $order = DB::table('orders')->where('id', $candidate->id)->lockForUpdate()->first();
                    if (! $order || $order->status !== 'PENDING_PAYMENT'
                        || ! $order->expires_at || now()->lt($order->expires_at)) {
                        return;
                    }

                    $states->expireOrder($order);
                    $expired++;
                }, 3);
            }
        });
    $this->info("Expired unpaid orders: {$expired}");

    return 0;
})->purpose('Expire overdue unpaid orders once and release vouchers using the existing state service');

Schedule::command('lfamilia:expire-unpaid-orders')->everyMinute()->withoutOverlapping();

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
    $staleMinutes = app(OperationalSettings::class)->value('stale_fulfillment_minutes');

    DB::table('fulfillment_attempts')
        ->whereIn('status', ['PENDING', 'UNKNOWN', 'SENDING'])
        ->where('updated_at', '<=', now()->subMinutes($staleMinutes))
        ->orderBy('id')
        ->limit(100)
        ->get(['id', 'order_id', 'status'])
        ->each(function (object $attempt) use ($notifications, $staleMinutes): void {
            $already = DB::table('admin_notifications')
                ->where('event_type', 'fulfillment.pending.stale')
                ->where('target_type', 'fulfillment_attempt')
                ->where('target_id', (string) $attempt->id)
                ->exists();
            if (! $already) {
                $notifications->record(
                    'fulfillment.pending.stale',
                    'Fulfillment perlu reconciliation',
                    'Attempt #'.$attempt->id.' berstatus '.$attempt->status.' lebih dari '.$staleMinutes.' menit.',
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

    $interval = max(15, min(1440, (int) (json_decode(
        (string) DB::table('system_settings')->where('key', 'digiflazz.auto_sync_interval_minutes')->value('value'),
        true
    ) ?? 15)));
    // Respect the interval after failures as well as successes, avoiding a retry every scheduler tick.
    foreach (['digiflazz.last_auto_sync', 'digiflazz.last_auto_sync_attempt'] as $setting) {
        $last = json_decode((string) DB::table('system_settings')->where('key', $setting)->value('value'), true);
        if (is_array($last) && ! empty($last['at'])) {
            try {
                if (Carbon::parse($last['at'])->gt(now()->subMinutes($interval))) {
                    $this->info('Belum mencapai jadwal sinkron otomatis berikutnya.');

                    return 0;
                }
            } catch (Throwable) {
                // Invalid historical timestamp is ignored so the next sync can repair the state.
            }
        }
    }

    try {
        DB::table('system_settings')->updateOrInsert(['key' => 'digiflazz.last_auto_sync_attempt'], [
            'value' => json_encode(['at' => now()->toIso8601String()], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $count = $catalog->sync();
        DB::table('system_settings')->updateOrInsert(['key' => 'digiflazz.last_auto_sync'], [
            'value' => json_encode(['at' => now()->toIso8601String(), 'count' => $count]), 'updated_at' => now(),
        ]);
        $this->info("SKU disinkronkan: {$count}");

        return 0;
    } catch (Throwable $error) {
        $validationErrors = $error instanceof ValidationException ? $error->errors() : [];
        $syncReason = $validationErrors['sync'][0] ?? null;
        // Only log messages owned by this service; never log raw supplier responses or credentials.
        $safeReasons = [
            'Sinkronisasi masih berjalan.',
            'Lengkapi dan aktifkan Digiflazz di Integrasi.',
            'Digiflazz tidak merespons. Data sebelumnya tetap tersimpan.',
            'Daftar harga Digiflazz kosong atau tidak valid.',
            'Format daftar harga tidak valid. Tidak ada perubahan disimpan.',
        ];
        // Same dedupe key as DigiflazzCatalogService; no duplicate channel delivery.
        try {
            app(AdminNotificationService::class)->recordDigiflazzSyncFailure($error);
        } catch (Throwable $notificationError) {
            Log::warning('Automatic Digiflazz sync notification failed.', ['exception_class' => $notificationError::class]);
        }
        Log::warning('Automatic Digiflazz catalog sync failed.', [
            'exception_class' => $error::class,
            'validation_fields' => array_keys($validationErrors),
            'sync_reason' => in_array($syncReason, $safeReasons, true) ? $syncReason : null,
        ]);
        $this->error('Sinkron gagal; harga tersimpan dipertahankan. Periksa Integrasi dan coba sinkron manual.');

        return 1;
    }
})->purpose('Refresh supplier costs and availability while preserving customer margin settings');

Artisan::command('lfamilia:purge-guest-data', function (): int {
    $days = app(OperationalSettings::class)->value('guest_pii_days');
    $cutoff = now()->subDays($days);

    $purged = 0;
    DB::table('orders')
        ->whereNull('user_id')
        ->where('created_at', '<', $cutoff)
        ->where(function ($query): void {
            $query->whereNotNull('guest_email')
                ->orWhereNotNull('guest_phone')
                ->orWhereNotNull('guest_phone_normalized');
        })
        ->orderBy('id')
        ->chunkById(500, function ($orders) use (&$purged): void {
            $ids = $orders->pluck('id')->all();
            $purged += DB::table('orders')->whereIn('id', $ids)->update([
                'guest_email' => null,
                'guest_phone' => null,
                'guest_phone_normalized' => null,
                'updated_at' => now(),
            ]);
        });

    $this->info('Data pribadi pesanan tamu dibersihkan: '.$purged.' pesanan (lebih dari '.$days.' hari). Catatan transaksi tetap disimpan.');

    return 0;
})->purpose('Hapus email dan nomor HP pesanan tamu yang lebih tua dari 30 hari, tanpa menghapus catatan transaksi');

Schedule::command('lfamilia:purge-guest-data')->dailyAt('03:45');

// Tick tiap menit; command sendiri menahan sampai interval tersimpan terpenuhi,
// sehingga pilihan 15/20/25/... menit benar-benar dihormati.
Schedule::command('lfamilia:sync-digiflazz-catalog')->everyMinute()->withoutOverlapping();

// D8: healthcheck (deploy/healthcheck.sh) memanggil command ini saat
// gagal (down) dan saat pulih (up). Alert down di-dedupe 30 menit agar
// tidak spam tiap 5 menit — satu baris per outage, dan delivery yang
// gagal DICOBA LAGI tiap healthcheck (kirim ulang ID yang sama;
// deliver() idempotent per-channel via baris SENT) sampai sukses.
// Kabar pulih hanya bila ada alert down yang belum ditutup kabar pulih.
// Pengiriman sinkron best-effort agar kabar keluar segera meski queue
// worker sedang mati. SELURUH jalur ini bebas Redis: record() tanpa
// dispatch job + deliver() tanpa Cache::lock. Bila Redis yang mati,
// dispatch job ke antrean Redis melempar (dan tidak ada sweeper yang
// mengirimkan ulang), dan lock Redis melempar — alert hilang total.
// Dedupe tetap jalan via DB (cek 30 menit di bawah + cek SENT di
// notification_deliveries).
Artisan::command('lfamilia:notify-healthcheck {state} {--reason=}', function (
    AdminNotificationService $notifications,
    ExternalNotificationService $external,
): int {
    $state = (string) $this->argument('state');
    if (! in_array($state, ['down', 'up'], true)) {
        $this->error('State harus down atau up.');

        return 1;
    }

    if ($state === 'down') {
        // Redam HANYA selama outage yang sama belum pulih: ada alert
        // gagal ≤30 menit lalu DAN belum ada recovery setelahnya.
        // down → up → down dalam 30 menit adalah outage BARU dan
        // wajib bunyi lagi — versi lama menelannya karena hanya
        // melihat failed terbaru tanpa membandingkannya dengan
        // recovery terbaru.
        //
        // Satu baris per outage — TAPI baris itu bukan tanda
        // "sudah terkirim": bila delivery pertama gagal (error
        // transport / respons non-sukses), healthcheck berikutnya
        // mengirim ULANG notifikasi yang SAMA (ID sama), bukan
        // dibungkam hanya karena barisnya ada. deliver() idempotent
        // per-channel via baris SENT di notification_deliveries —
        // channel yang sudah SENT dilewati, yang FAILED dicoba lagi
        // — jadi retry tidak spam, dan satu kegagalan transient
        // tidak menekan semua retry sampai 30 menit.
        $latestRecoveredId = DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.recovered')
            ->orderByDesc('id')->value('id');
        $downQuery = DB::table('admin_notifications')
            ->where('event_type', 'system.healthcheck.failed')
            ->where('created_at', '>=', now()->subMinutes(30));
        if ($latestRecoveredId !== null) {
            $downQuery->where('id', '>', (int) $latestRecoveredId);
        }
        $existingId = $downQuery->orderByDesc('id')->value('id');
        if ($existingId !== null) {
            try {
                $external->deliver((int) $existingId, withLock: false);
                $this->info('Alert healthcheck gagal sudah dikirim dalam 30 menit terakhir.');
            } catch (Throwable) {
                $this->info('Pengiriman alert healthcheck gagal — akan dicoba lagi pada healthcheck berikutnya.');
            }

            return 0;
        }

        $reason = trim((string) ($this->option('reason') ?? ''));
        $id = $notifications->record(
            'system.healthcheck.failed',
            'Website tidak sehat - healthcheck gagal',
            'Healthcheck gagal'.($reason !== '' ? ': '.$reason : '').'. Periksa VPS / layanan.',
            'ERROR',
            'system',
            'healthcheck',
            dispatchJob: false,
        );
        try {
            $external->deliver($id, withLock: false);
        } catch (Throwable) {
            // Pengiriman sinkron gagal (mis. provider down) — tidak
            // ada job antrean yang mencoba ulang untuk jalur ini.
        }

        return 0;
    }

    $latestDown = DB::table('admin_notifications')
        ->where('event_type', 'system.healthcheck.failed')
        ->orderByDesc('id')->value('id');
    $latestRecovered = DB::table('admin_notifications')
        ->where('event_type', 'system.healthcheck.recovered')
        ->orderByDesc('id')->value('id');
    if ($latestDown === null) {
        return 0;
    }
    if ($latestRecovered !== null && (int) $latestRecovered > (int) $latestDown) {
        // A recovery row marks the outage as closed, not its delivery as
        // complete. Retry that SAME notification ID on subsequent 'up'
        // checks: deliver() skips SENT channels and retries FAILED ones.
        // Keep healthcheck delivery synchronous and Redis-independent.
        try {
            $external->deliver((int) $latestRecovered, withLock: false);
        } catch (Throwable) {
            $this->info('Pengiriman kabar healthcheck pulih akan dicoba lagi pada healthcheck berikutnya.');
        }

        return 0;
    }

    $id = $notifications->record(
        'system.healthcheck.recovered',
        'Website pulih - healthcheck lolos',
        'Healthcheck kembali lolos setelah sebelumnya gagal.',
        'INFO',
        'system',
        'healthcheck',
        dispatchJob: false,
    );
    try {
        $external->deliver($id, withLock: false);
    } catch (Throwable) {
        // Pengiriman sinkron gagal (mis. provider down) — tidak
        // ada job antrean yang mencoba ulang untuk jalur ini.
    }

    return 0;
})->purpose('Notify admin when the production healthcheck fails or recovers');
