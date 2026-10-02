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
        $definitions = $registry->all();
        $records = IntegrationCredential::whereIn('code', array_keys($definitions))
            ->get()->keyBy('code');

        $healthKeys = collect(array_keys($definitions))
            ->map(fn (string $code): string => 'integration.health.'.$code)
            ->all();
        $healthStates = DB::table('system_settings')
            ->whereIn('key', $healthKeys)
            ->pluck('value', 'key')
            ->map(function ($value): array {
                $decoded = json_decode((string) $value, true);

                return is_array($decoded) ? $decoded : [];
            });

        $integrations = collect($definitions)->map(function (array $definition, string $code) use ($records, $healthStates): array {
            $record = $records->get($code);
            $config = is_array($record?->config_ciphertext) ? $record->config_ciphertext : [];
            $requiredTotal = 0;
            $configuredRequired = 0;

            $fields = collect($definition['fields'])->map(function (array $field, string $key) use (
                $config,
                &$requiredTotal,
                &$configuredRequired
            ): array {
                $secret = (bool) ($field['secret'] ?? false);
                $required = (bool) ($field['required'] ?? false);
                $value = $config[$key] ?? null;
                $configured = is_array($value)
                    ? $value !== []
                    : $value !== null && trim((string) $value) !== '';

                if (($field['type'] ?? null) === 'boolean') {
                    $configured = array_key_exists($key, $config);
                }

                if ($required) {
                    $requiredTotal++;
                    if ($configured) {
                        $configuredRequired++;
                    }
                }

                $publicValue = ($field['type'] ?? null) === 'csv' && is_array($value)
                    ? implode(', ', array_map('strval', $value))
                    : $value;

                return [
                    ...$field,
                    'key' => $key,
                    'value' => $secret ? null : $publicValue,
                    'configured' => $configured,
                ];
            })->values()->all();

            $active = (bool) ($record?->is_active);
            $storedHealth = $healthStates->get('integration.health.'.$code, []);
            $status = $active
                ? (string) ($storedHealth['status'] ?? 'UNTESTED')
                : 'NOT_CONFIGURED';
            $message = $active
                ? (string) ($storedHealth['message'] ?? 'Belum pernah menjalankan Tes Koneksi.')
                : 'Integrasi nonaktif.';

            return [
                'code' => $code,
                'name' => $definition['name'],
                'group' => $definition['group'] ?? 'Lainnya',
                'description' => $definition['description'] ?? null,
                'note' => $definition['note'] ?? null,
                'is_active' => $active,
                'fields' => $fields,
                'required_total' => $requiredTotal,
                'configured_required' => $configuredRequired,
                'required_complete' => $requiredTotal === $configuredRequired,
                'health' => [
                    'status' => $status,
                    'message' => $message,
                    'tested_at' => $storedHealth['tested_at'] ?? null,
                ],
            ];
        })->values();

        $appUrl = rtrim((string) config('app.url'), '/');

        return Inertia::render('Admin/Integrations', [
            'integrations' => $integrations->all(),
            'summary' => [
                'total' => $integrations->count(),
                'active' => $integrations->where('is_active', true)->count(),
                'healthy' => $integrations
                    ->filter(fn (array $item): bool => $item['is_active'] && $item['health']['status'] === 'HEALTHY')
                    ->count(),
                'attention' => $integrations
                    ->filter(fn (array $item): bool => $item['is_active'] && $item['health']['status'] !== 'HEALTHY')
                    ->count(),
                'configuration_complete' => $integrations->where('required_complete', true)->count(),
            ],
            'callbackUrls' => [
                'midtrans' => $appUrl.'/api/payments/midtrans/notification',
                'doku' => $appUrl.'/api/payments/doku/notification',
                'digiflazz' => $appUrl.'/api/fulfillment/digiflazz/webhook',
                'google' => $appUrl.'/auth/google/callback',
            ],
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
        $existing = is_array($record->config_ciphertext) ? $record->config_ciphertext : [];
        $before = [
            'code' => $code,
            'is_active' => (bool) $record->is_active,
            'config_ciphertext' => $existing,
        ];

        // Preserve unknown legacy metadata so saving a current field never destroys
        // older provider options that are still needed during migration/regression.
        $config = $existing;

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
                $items = array_values(array_unique(array_filter(
                    array_map(fn ($value): string => trim((string) $value), $items),
                    fn (string $value): bool => $value !== ''
                )));

                $this->validateCsvField($key, $items);
                $config[$key] = $items;

                continue;
            }

            $value = trim((string) ($incoming ?? ''));

            if ($secret && $value === '' && array_key_exists($key, $existing)) {
                continue;
            }

            if ($value === '') {
                unset($config[$key]);

                continue;
            }

            if (in_array($key, ['base_url', 'callback_url', 'webhook_url'], true)
                && ! str_starts_with(strtolower($value), 'https://')) {
                throw ValidationException::withMessages([
                    'config.'.$key => 'Alamat integrasi wajib menggunakan HTTPS.',
                ]);
            }

            if (str_ends_with($key, '_path') && ! $this->validEndpointPath($value)) {
                throw ValidationException::withMessages([
                    'config.'.$key => 'Path endpoint harus diawali / dan tidak boleh berupa URL penuh.',
                ]);
            }

            if ($key === 'from_email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'config.'.$key => 'Email pengirim tidak valid.',
                ]);
            }

            $config[$key] = $value;
        }

        if ($data['is_active']) {
            foreach ($registry->requiredFields($code) as $required) {
                $value = $config[$required] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    throw ValidationException::withMessages([
                        'config.'.$required => 'Field ini wajib dilengkapi sebelum integrasi diaktifkan.',
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
                    'message' => $record->is_active
                        ? 'Konfigurasi berubah. Jalankan Tes Koneksi untuk memverifikasi.'
                        : 'Integrasi nonaktif.',
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

        return back()->with('status', 'Pengaturan '.$definition['name'].' berhasil disimpan.');
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
        $definition = $registry->get($code);
        abort_unless($definition, 404);

        $record = IntegrationCredential::where('code', $code)->first();
        $config = is_array($record?->config_ciphertext) ? $record->config_ciphertext : [];

        $missing = collect($registry->requiredFields($code))
            ->filter(function (string $field) use ($config): bool {
                $value = $config[$field] ?? null;

                return $value === null || $value === '' || $value === [];
            })
            ->values();

        $result = $missing->isNotEmpty()
            ? [
                'status' => 'NOT_CONFIGURED',
                'message' => 'Lengkapi semua field wajib sebelum menjalankan Tes Koneksi.',
            ]
            : $connections->test($code, $config);

        $health = [
            ...$result,
            'tested_at' => now()->toIso8601String(),
        ];

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'integration.health.'.$code],
            [
                'value' => json_encode($health, JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $audit->record($request, 'integration.connection.tested', 'integration_credential', $code, null, $health);

        if ($result['status'] === 'DOWN') {
            $notifications->record(
                'integration.error',
                'Integrasi bermasalah',
                $definition['name'].' gagal melewati Tes Koneksi.',
                'ERROR',
                'integration_credential',
                $code
            );
        }

        return response()->json($health)
            ->header('Cache-Control', 'no-store, private');
    }

    private function validEndpointPath(string $path): bool
    {
        return $path !== ''
            && mb_strlen($path) <= 255
            && str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '://')
            && ! str_contains($path, '..');
    }

    /**
     * @param  array<int, string>  $items
     */
    private function validateCsvField(string $key, array $items): void
    {
        if ($key === 'admin_recipients') {
            foreach ($items as $item) {
                if (! filter_var($item, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages([
                        'config.'.$key => 'Daftar email penerima berisi alamat yang tidak valid.',
                    ]);
                }
            }
        }

        if ($key === 'allowed_hostnames') {
            foreach ($items as $item) {
                if (str_contains($item, '://')
                    || str_contains($item, '/')
                    || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $item)) {
                    throw ValidationException::withMessages([
                        'config.'.$key => 'Hostname harus ditulis persis tanpa wildcard, http://, https://, atau path.',
                    ]);
                }
            }
        }
    }
}
