<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminAuditService
{
    private const SECRET_KEYS = [
        'password', 'api_key', 'server_key', 'client_secret', 'secret_key',
        'access_token', 'private_key', 'bearer_token', 'webhook_secret',
        'bot_token', 'client_id', 'client_key', 'chat_id', 'webhook_url',
        'authorization', 'cookie', 'set_cookie',
    ];

    public function record(
        Request $request,
        string $action,
        string $targetType,
        string|int|null $targetId,
        mixed $before = null,
        mixed $after = null,
    ): void {
        $admin = $request->user('admin');

        DB::table('audit_logs')->insert([
            'actor_type' => $admin ? 'admin_user' : null,
            'actor_id' => $admin ? (string) $admin->id : null,
            'actor_role' => $admin?->role,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'before' => $before === null ? null : json_encode($this->redact($before), JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($this->redact($after), JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => (string) ($request->attributes->get('correlation_id') ?: Str::uuid()),
            'created_at' => now(),
        ]);
    }

    public function redact(mixed $value): mixed
    {
        if (! is_array($value) && ! is_object($value)) {
            return $value;
        }

        $array = (array) $value;
        foreach ($array as $key => $item) {
            $normalized = strtolower((string) $key);
            $compact = preg_replace('/[^a-z0-9]/', '', $normalized) ?: $normalized;
            $knownSecrets = array_map(
                fn (string $secret): string => preg_replace('/[^a-z0-9]/', '', $secret) ?: $secret,
                self::SECRET_KEYS,
            );

            $array[$key] = in_array($normalized, self::SECRET_KEYS, true)
                || collect($knownSecrets)->contains(fn (string $secret): bool => str_ends_with($compact, $secret))
                || str_contains($normalized, 'password')
                || str_contains($normalized, 'credential')
                || str_contains($normalized, 'secret')
                || str_contains($normalized, 'token')
                || str_contains($normalized, 'signature')
                ? '[REDACTED]'
                : $this->redact($item);
        }

        return $array;
    }
}
