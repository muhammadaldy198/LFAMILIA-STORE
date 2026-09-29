<?php

namespace App\Services;

use App\Jobs\SendAdminNotificationJob;
use Illuminate\Support\Facades\DB;

class AdminNotificationService
{
    public function record(
        string $eventType,
        string $title,
        string $message,
        string $severity = 'INFO',
        ?string $targetType = null,
        string|int|null $targetId = null,
        array $data = [],
    ): int {
        $id = DB::table('admin_notifications')->insertGetId([
            'event_type' => $eventType,
            'severity' => strtoupper($severity),
            'title' => $title,
            'message' => $message,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        SendAdminNotificationJob::dispatch($id)->afterCommit();

        return $id;
    }
}
