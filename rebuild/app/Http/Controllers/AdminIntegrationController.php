<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Services\AdminAuditService;
use App\Services\AdminNotificationService;
use App\Services\IntegrationConnectionService;
use App\Services\IntegrationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminIntegrationController
{
    public function index(IntegrationRegistry $registry): Response
    {
        $records = IntegrationCredential::whereIn('code', array_keys($registry->all()))
            ->get()->keyBy('code');

        return Inertia::render('Admin/Integrations', [
            'integrations' => collect($registry->all())->map(function (array $definition, string $code) use ($records): array {
                $record = $records->get($code);
                $config = is_array($record?->config_ciphertext) ? $record->config_ciphertext : [];
                $fields = collect($definition['fields'])->map(function (array $field, string $key) use ($config): array {
                    $secret = (bool) ($field['secret'] ?? false);
                    $value = $config[$key] ?? null;
                    $publicValue = ($field['type'] ?? null) === 'csv' && is_array($value)
                        ? implode(', ', array_map('strval', $value))
                        : $value;

                    return [
                        ...$field,
                        'key' => $key,
                        'value' => $secret ? null : $publicValue,
                        'configured' => $secret
                            ? (is_scalar($value) && trim((string) $value) !== '')
                            : $value !== null && $value !== '',
                    ];
                })->values()->all();

                return [
                    'code' => $code,
                    'name' => $definition['name'],
                    'note' => $definition['note'] ?? null,
                    'is_active' => (bool) ($record?->is_active),
                    'fields' => $fields,
                ];
            })->values()->all(),
        ]);
    }

    public function update(
        Request $request,
        string $code,
        IntegrationRegistry $registry,
        AdminAuditService $audit,
    ): RedirectResponse {
        $definition = $registry->get($code);
        abort_unless($definition, 404);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'config' => ['required', 'array'],
        ]);

        $record = IntegrationCredential::firstOrNew(['code' => $code]);
        $before = [
            'code' => $code,
            'is_active' => (bool) $record->is_active,
            'config_ciphertext' => is_array($record->config_ciphertext) ? $record->config_ciphertext : [],
        ];
        $existing = is_array($record->config_ciphertext) ? $record->config_ciphertext : [];
        $config = [];

        foreach ($definition['fields'] as $key => $field) {
            $type = $field['type'] ?? 'string';
            $secret = (bool) ($field['secret'] ?? false);
            $incoming = data_get($data, 'config.'.$key);

            if ($type === 'boolean') {
                $config[$key] = filter_var($incoming, FILTER_VALIDATE_BOOLEAN);

                continue;
            }
            if ($type === 'csv') {
                $items = is_array($incoming)
                    ? $incoming
                    : explode(',', (string) ($incoming ?? ''));
                $config[$key] = array_values(array_unique(array_filter(
                    array_map(fn ($value): string => trim((string) $value), $items),
                    fn (string $value): bool => $value !== ''
                )));

                continue;
            }

            $value = trim((string) ($incoming ?? ''));
            if ($secret && $value === '' && isset($existing[$key])) {
                $config[$key] = $existing[$key];

                continue;
            }
            if ($value !== '') {
                if (in_array($key, ['base_url', 'callback_url', 'webhook_url'], true)
                    && ! str_starts_with(strtolower($value), 'https://')) {
                    throw ValidationException::withMessages([
                        'config.'.$key => 'URL integrasi wajib menggunakan HTTPS.',
                    ]);
                }
                if ($key === 'from_email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages([
                        'config.'.$key => 'Email pengirim tidak valid.',
                    ]);
                }
                $config[$key] = $value;
            }
        }

        if ($data['is_active']) {
            foreach ($this->requiredFields($code) as $required) {
                $value = $config[$required] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    throw ValidationException::withMessages([
                        'config.'.$required => 'Field ini wajib sebelum integrasi diaktifkan.',
                    ]);
                }
            }
        }

        $record->config_ciphertext = $config;
        $record->is_active = $data['is_active'];
        $record->save();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'integration.health.'.$code],
            [
                'value' => json_encode([
                    'status' => $record->is_active ? 'DEGRADED' : 'NOT_CONFIGURED',
                    'message' => $record->is_active ? 'Konfigurasi berubah; jalankan Tes Koneksi.' : 'Integrasi nonaktif.',
                    'tested_at' => null,
                ], JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $audit->record($request, 'integration.updated', 'integration_credential', $code, $before, [
            'code' => $code,
            'is_active' => (bool) $record->is_active,
            'config_ciphertext' => $config,
        ]);

        return back();
    }

    public function reveal(
        Request $request,
        string $code,
        string $field,
        IntegrationRegistry $registry,
        AdminAuditService $audit,
    ): JsonResponse {
        $definition = $registry->get($code);
        abort_unless($definition && in_array($field, $registry->secretFields($code), true), 404);

        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);
        $admin = $request->user('admin');
        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            $audit->record(
                $request,
                'integration.secret.reveal_denied',
                'integration_credential',
                $code,
                null,
                ['field' => $field]
            );

            throw ValidationException::withMessages([
                'password' => 'Password Super Admin tidak cocok.',
            ]);
        }

        $record = IntegrationCredential::where('code', $code)->firstOrFail();
        $config = is_array($record->config_ciphertext) ? $record->config_ciphertext : [];
        $value = $config[$field] ?? null;
        abort_unless(is_scalar($value) && trim((string) $value) !== '', 404);

        $audit->record($request, 'integration.secret.revealed', 'integration_credential', $code, null, [
            'field' => $field,
        ]);

        return response()->json(['value' => (string) $value])
            ->header('Cache-Control', 'no-store, private');
    }

    public function test(
        Request $request,
        string $code,
        IntegrationRegistry $registry,
        IntegrationConnectionService $connections,
        AdminAuditService $audit,
        AdminNotificationService $notifications,
    ): JsonResponse {
        abort_unless($registry->get($code), 404);
        $record = IntegrationCredential::where('code', $code)->first();
        $config = is_array($record?->config_ciphertext) ? $record->config_ciphertext : [];
        $result = $connections->test($code, $config);
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'integration.health.'.$code],
            [
                'value' => json_encode([
                    ...$result,
                    'tested_at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $audit->record($request, 'integration.connection.tested', 'integration_credential', $code, null, $result);
        if ($result['status'] === 'DOWN') {
            $notifications->record(
                'integration.error',
                'Integrasi bermasalah',
                strtoupper($code).' gagal melewati Tes Koneksi.',
                'ERROR',
                'integration_credential',
                $code
            );
        }

        return response()->json($result);
    }

    /**
     * @return array<int, string>
     */
    private function requiredFields(string $code): array
    {
        return match ($code) {
            'digiflazz' => ['username', 'api_key'],
            'kokinpay' => ['api_key'],
            'midtrans' => ['server_key'],
            'doku' => ['client_id', 'secret_key'],
            'resend' => ['api_key', 'from_email'],
            'google_oauth' => ['client_id', 'client_secret'],
            'telegram' => ['bot_token', 'chat_id'],
            'discord' => ['webhook_url'],
            'turnstile' => ['site_key', 'secret_key'],
            default => [],
        };
    }
}
