<?php

namespace App\Services;

use App\Jobs\SendAdminNotificationJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminNotificationService
{
    /** Dedupe safe supplier sync alerts for one rolling hour. */
    public function recordDigiflazzSyncFailure(\Throwable $error): void
    {
        $reason = $error instanceof ValidationException ? ($error->errors()['sync'][0] ?? null) : null;
        $known = [
            'Sinkronisasi masih berjalan.',
            'Lengkapi dan aktifkan Digiflazz di Integrasi.',
            'Digiflazz tidak merespons. Data sebelumnya tetap tersimpan.',
            'Daftar harga Digiflazz kosong atau tidak valid.',
            'Format daftar harga tidak valid. Tidak ada perubahan disimpan.',
            'Jumlah SKU Digiflazz turun drastis. Data lama tidak diubah; ulangi sinkronisasi setelah memeriksa penyedia.',
        ];
        $safeReason = in_array($reason, $known, true)
            ? $reason : 'Sinkronisasi gagal ('.$error::class.').';
        $key = 'digiflazz.sync.failed.'.hash('sha256', $safeReason);
        if (! Cache::add($key, true, now()->addHour())) {
            return;
        }
        try {
            $this->record(
                'digiflazz.sync.failed', 'Sinkronisasi Digiflazz gagal',
                'Sinkronisasi katalog Digiflazz gagal: '.$safeReason,
                'ERROR', 'provider', 'DIGIFLAZZ', ['reason' => $safeReason]
            );
        } catch (\Throwable $notificationError) {
            Cache::forget($key);
            throw $notificationError;
        }
    }

    public function record(
        string $eventType,
        string $title,
        string $message,
        string $severity = 'INFO',
        ?string $targetType = null,
        string|int|null $targetId = null,
        array $data = [],
        // Healthcheck alert (lfamilia:notify-healthcheck) memanggil
        // dengan dispatchJob: false — jalur alert itu tidak boleh
        // bergantung Redis: bila Redis mati, dispatch() ke antrean
        // Redis melempar, baris notif tertulis tapi job tidak pernah
        // ter-enqueue dan tidak ada sweeper yang mengirimkan ulang —
        // alert hilang total. Tanpa job, pengiriman ditangani
        // langsung oleh pemanggil (deliver sinkron).
        bool $dispatchJob = true,
    ): int {
        $copy = app(EditableMessageTemplateService::class)
            ->renderNotification($eventType, $title, $message);
        $id = DB::table('admin_notifications')->insertGetId([
            'event_type' => $eventType,
            'severity' => strtoupper($severity),
            'title' => $copy['title'],
            'message' => $copy['message'],
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        if ($dispatchJob) {
            SendAdminNotificationJob::dispatch($id)->afterCommit();
        }

        return $id;
    }
}
