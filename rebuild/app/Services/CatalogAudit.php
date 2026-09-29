<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogAudit
{
    public function record(Request $request, string $action, string $type, int $id, ?array $before, array $after): void
    {
        $admin = $request->user('admin');

        DB::table('audit_logs')->insert([
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'actor_role' => $admin->role,
            'action' => $action,
            'target_type' => $type,
            'target_id' => (string) $id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
