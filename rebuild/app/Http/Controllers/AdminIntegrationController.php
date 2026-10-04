<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Services\AdminAuditService;
use App\Services\AdminNotificationService;
use App\Services\IntegrationConnectionService;
use App\Services\IntegrationRegistry;
use App\Services\IntegrationRuntimeConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminIntegrationController
{
    public function index(IntegrationRegistry $registry, IntegrationRuntimeConfig $runtime): Response
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

        $integrations = collect($definitions)->map(function (
            array $definition,
            string $code
        ) use ($records, $healthStates, $registry, $runtime): array {
            $record = $records->get($code);
            try {
                $storedConfig = $record?->config_ciphertext;
            } catch (DecryptException) {
                $storedConfig = null;
            }

            $stored = is_array($storedConfig) ? $storedConfig : [];
            $environment = $runtime->selectedEnvironment($code, $stored);
            $environments = $registry->environments($code);

            $profiles = [];
            if ($environments !== []) {
                foreach ($environments as $environmentCode => $metadata) {
                    $profiles[(string) $environmentCode] = $this->presentProfile(
                        $definition,
                        $runtime->profile($code, $stored, (string) $environmentCode)
                    );
                }
            }

            $currentConfig = $runtime->profile($code, $stored, $environment);
            $currentProfile = $environment !== null && isset($profiles[$environment])
                ? $profiles[$environment]
                : $this->presentProfile($definition, $currentConfig);

            $active = (bool) ($record?->is_active);
            $storedHealth = $healthStates->get('integration.health.'.$code, []);
            $storedHealthEnvironment = $storedHealth['environment'] ?? null;
            $healthMatchesEnvironment = $environment === null || $storedHealthEnvironment === null
                || hash_equals((string) $environment, (string) $storedHealthEnvironment);

            $status = ! $active || ! $currentProfile['required_complete']
                ? 'NOT_CONFIGURED'
                : ($healthMatchesEnvironment ? (string) ($storedHealth['status'] ?? 'UNTESTED') : 'UNTESTED');
            $message = ! $active
                ? 'Integrasi nonaktif.'
                : (! $currentProfile['required_complete']
                    ? 'Credential wajib untuk environment yang dipilih belum lengkap.'
                    : ($healthMatchesEnvironment
                        ? (string) ($storedHealth['message'] ?? 'Belum pernah menjalankan Tes Koneksi.')
                        : 'Environment berubah. Tes koneksi sebelumnya tidak berlaku untuk environment ini.'));

            $environmentOptions = collect($environments)->map(
                fn (array $metadata, string $value): array => [
                    'value' => $value,
                    'label' => (string) ($metadata['label'] ?? strtoupper($value)),
                    'live' => (bool) ($metadata['live'] ?? false),
                    'description' => $metadata['description'] ?? null,
                ]
            )->values()->all();
            $selectedMetadata = $environment !== null ? ($environments[$environment] ?? []) : [];

            $connection = $this->connectionReadiness(
                $active,
                $currentProfile['required_complete'],
                $status,
                $healthMatchesEnvironment ? (bool) ($storedHealth['verified'] ?? false) : false,
                $message,
                $healthMatchesEnvironment ? ($storedHealth['tested_at'] ?? null) : null,
                $healthMatchesEnvironment ? ($storedHealth['reason'] ?? null) : null,
            );
            $callback = $this->callbackReadiness($definition, $currentConfig, $active);
            $readiness = $this->overallReadiness(
                $active,
                $currentProfile['required_complete'],
                $connection,
                $callback,
            );

            return [
                'code' => $code,
                'name' => $definition['name'],
                'group' => $definition['group'] ?? 'Lainnya',
                'description' => $definition['description'] ?? null,
                'note' => $definition['note'] ?? null,
                'environment_note' => $definition['environment_note'] ?? null,
                'is_active' => $active,
                'environment' => $environment,
                'environment_label' => $selectedMetadata['label'] ?? null,
                'environment_live' => (bool) ($selectedMetadata['live'] ?? false),
                'environment_options' => $environmentOptions,
                'credential_scope' => $definition['credential_scope'] ?? 'shared',
                'environment_profiles' => $profiles,
                'fields' => $currentProfile['fields'],
                'required_total' => $currentProfile['required_total'],
                'configured_required' => $currentProfile['configured_required'],
                'required_complete' => $currentProfile['required_complete'],
                'credential_status' => $currentProfile['required_complete']
                    ? 'Credential configured'
                    : 'Belum dikonfigurasi',
                'credential' => [
                    'status' => $currentProfile['required_complete'] ? 'CONFIGURED' : 'MISSING',
                    'label' => $currentProfile['required_complete'] ? 'Configured' : 'Credential Missing',
                ],
                'connection' => $connection,
                'callback' => $callback,
                'e2e' => [
                    'status' => 'DEFERRED',
                    'label' => 'DEFERRED TO TAHAP 9',
                ],
                'readiness' => $readiness,
                'health' => [
                    'status' => $status,
                    'message' => $message,
                    'reason' => $healthMatchesEnvironment ? ($storedHealth['reason'] ?? null) : null,
                    'verified' => $healthMatchesEnvironment ? (bool) ($storedHealth['verified'] ?? false) : false,
                    'tested_at' => $healthMatchesEnvironment ? ($storedHealth['tested_at'] ?? null) : null,
                ],
            ];
        })->values();

        return Inertia::render('Admin/Integrations', [
            'integrations' => $integrations->all(),
            'summary' => [
                'total' => $integrations->count(),
                'active' => $integrations->where('is_active', true)->count(),
                'healthy' => $integrations
                    ->filter(fn (array $item): bool => $item['is_active'] && $item['connection']['status'] === 'VERIFIED')
                    ->count(),
                'attention' => $integrations
                    ->filter(fn (array $item): bool => $item['is_active'] && $item['connection']['status'] !== 'VERIFIED')
                    ->count(),
                'configuration_complete' => $integrations->where('required_complete', true)->count(),
            ],
            'callbackUrls' => [
                'midtrans' => $this->canonicalRouteUrl('api.payments.midtrans.notification'),
                'doku' => $this->canonicalRouteUrl('api.payments.doku.notification'),
                'digiflazz' => $this->canonicalRouteUrl('api.fulfillment.digiflazz.webhook'),
                'google' => $this->canonicalRouteUrl('google.callback'),
            ],
        ]);
    }

    public function update(
        Request $request,
        string $code,
        IntegrationRegistry $registry,
        IntegrationRuntimeConfig $runtime,
        AdminAuditService $audit,
    ): RedirectResponse {
        $this->ensureSuperAdmin($request);

        $definition = $registry->get($code);
        abort_unless($definition, 404);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'environment' => ['nullable', 'string', 'max:40'],
            'config' => ['required', 'array'],
            'clear_secrets' => ['sometimes', 'array'],
            'clear_secrets.*' => ['string', 'max:80'],
        ]);

        $record = IntegrationCredential::firstOrNew(['code' => $code]);
        $configWasUnreadable = false;
        try {
            $storedConfig = $record->config_ciphertext;
        } catch (DecryptException) {
            $storedConfig = null;
            $configWasUnreadable = true;
        }
        $existing = is_array($storedConfig) ? $storedConfig : [];
        $before = [
            'code' => $code,
            'is_active' => (bool) $record->is_active,
            'config_ciphertext' => $existing,
        ];

        $environments = $registry->environments($code);
        $environment = null;
        if ($environments !== []) {
            $environment = strtolower(trim((string) ($data['environment'] ?? '')));
            if ($environment === '') {
                $environment = $runtime->selectedEnvironment($code, $existing);
            }
            if ($environment === null || ! array_key_exists($environment, $environments)) {
                throw ValidationException::withMessages([
                    'environment' => 'Environment provider tidak valid.',
                ]);
            }
        }

        $clearSecrets = array_values(array_unique($data['clear_secrets'] ?? []));
        $allowedSecretFields = $registry->secretFields($code);
        foreach ($clearSecrets as $secretField) {
            if (! in_array($secretField, $allowedSecretFields, true)) {
                throw ValidationException::withMessages([
                    'clear_secrets' => 'Permintaan penghapusan credential tidak valid.',
                ]);
            }
        }

        $config = $existing;
        if ($environments !== [] && $registry->credentialsArePerEnvironment($code)) {
            $profiles = $runtime->profiles($code, $existing);
            $target = $profiles[$environment] ?? [];
            $target = $this->applyFields($definition, $target, $data['config'], $clearSecrets);
            $profiles[$environment] = $target;

            $config = $this->preserveUnknownMetadata($definition, $existing);
            $config['environment'] = $environment;
            $config['profiles'] = $profiles;
        } else {
            $config = $this->applyFields($definition, $config, $data['config'], $clearSecrets);
            if ($environment !== null) {
                $config['environment'] = $environment;
            }

            if ($code === 'digiflazz') {
                unset($config['testing'], $config['base_url'], $config['callback_url']);
            }
        }

        $selectedConfig = $environments !== [] && $registry->credentialsArePerEnvironment($code)
            ? (is_array($config['profiles'][$environment] ?? null) ? $config['profiles'][$environment] : [])
            : $config;

        if ($data['is_active']) {
            foreach ($registry->requiredFields($code) as $required) {
                $value = $selectedConfig[$required] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    throw ValidationException::withMessages([
                        'config.'.$required => 'Field ini wajib dilengkapi untuk environment yang dipilih sebelum integrasi diaktifkan.',
                    ]);
                }
            }
        }

        if ($configWasUnreadable && $record->exists) {
            DB::table('integration_credentials')->where('id', $record->id)->update([
                'config_ciphertext' => Crypt::encryptString(json_encode($config, JSON_THROW_ON_ERROR)),
                'is_active' => (bool) $data['is_active'],
                'updated_at' => now(),
            ]);
            $record = IntegrationCredential::findOrFail($record->id);
        } else {
            $record->config_ciphertext = $config;
            $record->is_active = $data['is_active'];
            $record->save();
        }

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'integration.health.'.$code],
            [
                'value' => json_encode([
                    'status' => $record->is_active ? 'UNTESTED' : 'NOT_CONFIGURED',
                    'message' => $record->is_active
                        ? 'Konfigurasi atau environment berubah. Jalankan Tes Koneksi untuk memverifikasi.'
                        : 'Integrasi nonaktif.',
                    'reason' => $record->is_active ? 'CONFIG_CHANGED' : 'INACTIVE',
                    'verified' => false,
                    'environment' => $environment,
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
            'environment' => $environment,
            'cleared_secret_fields' => $clearSecrets,
            'config_ciphertext' => $config,
        ]);

        return back()->with('status', 'Pengaturan '.$definition['name'].' berhasil disimpan.');
    }

    public function test(
        Request $request,
        string $code,
        IntegrationRegistry $registry,
        IntegrationRuntimeConfig $runtime,
        IntegrationConnectionService $connections,
        AdminAuditService $audit,
        AdminNotificationService $notifications,
    ): JsonResponse {
        $this->ensureSuperAdmin($request);

        $definition = $registry->get($code);
        abort_unless($definition, 404);

        $resolved = $runtime->resolve($code, false);
        $config = $resolved['config'] ?? [];
        $environment = $resolved['environment'] ?? null;

        $missing = collect($registry->requiredFields($code))
            ->filter(function (string $field) use ($config): bool {
                $value = $config[$field] ?? null;

                return $value === null || $value === '' || $value === [];
            })
            ->values();

        $result = $missing->isNotEmpty()
            ? [
                'status' => 'NOT_CONFIGURED',
                'message' => 'Lengkapi credential wajib untuk environment yang dipilih sebelum menjalankan Tes Koneksi.',
                'reason' => 'CREDENTIAL_MISSING',
                'verified' => false,
            ]
            : $connections->test($code, $config, $environment);

        $health = [
            ...$result,
            'environment' => $environment,
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

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $config
     * @return array{fields:array<int,array<string,mixed>>,required_total:int,configured_required:int,required_complete:bool}
     */
    private function presentProfile(array $definition, array $config): array
    {
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

            $publicValue = match ($field['type'] ?? null) {
                'csv' => is_array($value)
                    ? implode(', ', array_map('strval', $value))
                    : (string) ($value ?? ''),
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value,
            };

            return [
                ...$field,
                'key' => $key,
                'value' => $secret ? null : $publicValue,
                'configured' => $configured,
            ];
        })->values()->all();

        return [
            'fields' => $fields,
            'required_total' => $requiredTotal,
            'configured_required' => $configuredRequired,
            'required_complete' => $requiredTotal === $configuredRequired,
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $config
     * @return array{required:bool,ready:bool,status:string,label:string,message:string,url:?string}
     */
    private function callbackReadiness(array $definition, array $config, bool $active): array
    {
        $callback = $definition['callback'] ?? null;
        if (! is_array($callback)) {
            return [
                'required' => false,
                'ready' => true,
                'status' => 'NOT_REQUIRED',
                'label' => 'Tidak diperlukan',
                'message' => 'Integrasi ini tidak memerlukan callback/redirect aplikasi.',
                'url' => null,
            ];
        }

        $routeName = (string) ($callback['route'] ?? '');
        $route = $routeName !== '' ? Route::getRoutes()->getByName($routeName) : null;
        $appUrl = rtrim((string) config('app.url'), '/');
        $path = $route ? parse_url(route($routeName), PHP_URL_PATH) : null;
        $url = is_string($path) && $path !== '' ? $appUrl.$path : null;

        $missing = collect($callback['required_fields'] ?? [])
            ->filter(function ($field) use ($config): bool {
                $value = $config[(string) $field] ?? null;

                return $value === null || $value === '' || $value === [];
            })->values();

        $middleware = $route?->gatherMiddleware() ?? [];
        $protectedBySessionAuth = collect($middleware)->contains(
            fn ($item): bool => is_string($item)
                && (str_starts_with($item, 'auth:') || str_starts_with($item, 'admin.'))
        );

        if (! $active) {
            return [
                'required' => true,
                'ready' => false,
                'status' => 'NOT_READY',
                'label' => 'Belum siap',
                'message' => 'Integrasi nonaktif.',
                'url' => $url,
            ];
        }
        if (! $route || $url === null) {
            return [
                'required' => true,
                'ready' => false,
                'status' => 'NOT_READY',
                'label' => 'Belum siap',
                'message' => 'Route callback/redirect belum tersedia.',
                'url' => $url,
            ];
        }
        if (! str_starts_with(strtolower($appUrl), 'https://')) {
            return [
                'required' => true,
                'ready' => false,
                'status' => 'NOT_READY',
                'label' => 'Belum siap',
                'message' => 'Canonical application URL wajib HTTPS.',
                'url' => $url,
            ];
        }
        if ($protectedBySessionAuth) {
            return [
                'required' => true,
                'ready' => false,
                'status' => 'NOT_READY',
                'label' => 'Belum siap',
                'message' => 'Route callback tidak boleh dilindungi autentikasi customer/Admin.',
                'url' => $url,
            ];
        }
        if ($missing->isNotEmpty()) {
            return [
                'required' => true,
                'ready' => false,
                'status' => 'NOT_READY',
                'label' => 'Credential Missing',
                'message' => 'Credential verifikasi callback/redirect belum lengkap.',
                'url' => $url,
            ];
        }

        return [
            'required' => true,
            'ready' => true,
            'status' => 'READY',
            'label' => 'Callback Ready',
            'message' => 'URL HTTPS, route, middleware, dan credential verifikasi callback siap.',
            'url' => $url,
        ];
    }

    /**
     * @return array{status:string,label:string,message:string,tested_at:mixed,reason:mixed}
     */
    private function connectionReadiness(
        bool $active,
        bool $credentialComplete,
        string $status,
        bool $verified,
        string $message,
        mixed $testedAt,
        mixed $reason,
    ): array {
        $connectionStatus = match (true) {
            ! $active, ! $credentialComplete, $status === 'NOT_CONFIGURED' => 'NOT_CONFIGURED',
            $status === 'HEALTHY' && $verified => 'VERIFIED',
            $status === 'HEALTHY' => 'UNVERIFIED',
            $status === 'DOWN' => 'FAILED',
            $status === 'DEGRADED' => 'UNVERIFIED',
            default => 'NOT_TESTED',
        };

        $label = match ($connectionStatus) {
            'VERIFIED' => 'Connection Verified',
            'FAILED' => 'Koneksi bermasalah',
            'UNVERIFIED' => 'Belum dapat diverifikasi',
            'NOT_CONFIGURED' => 'Belum dikonfigurasi',
            default => 'Belum dites',
        };

        return [
            'status' => $connectionStatus,
            'label' => $label,
            'message' => $message,
            'tested_at' => $testedAt,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $connection
     * @param  array<string,mixed>  $callback
     * @return array{status:string,label:string}
     */
    private function overallReadiness(
        bool $active,
        bool $credentialComplete,
        array $connection,
        array $callback,
    ): array {
        if (! $active) {
            return ['status' => 'INACTIVE', 'label' => 'Nonaktif'];
        }
        if (! $credentialComplete) {
            return ['status' => 'INCOMPLETE', 'label' => 'Credential Missing'];
        }
        if (($callback['status'] ?? null) === 'NOT_READY') {
            return ['status' => 'BLOCKED', 'label' => 'Callback belum siap'];
        }

        return match ($connection['status'] ?? null) {
            'VERIFIED' => ['status' => 'READY_FOR_E2E', 'label' => 'Siap untuk E2E'],
            'FAILED' => ['status' => 'BLOCKED', 'label' => 'Koneksi bermasalah'],
            'UNVERIFIED' => ['status' => 'CONFIGURED', 'label' => 'Configured — E2E belum diverifikasi'],
            default => ['status' => 'CONFIGURED_UNTESTED', 'label' => 'Configured — belum dites'],
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $incoming
     * @param  array<int,string>  $clearSecrets
     * @return array<string,mixed>
     */
    private function applyFields(array $definition, array $existing, array $incoming, array $clearSecrets): array
    {
        $config = $existing;

        foreach ($definition['fields'] as $key => $field) {
            $type = $field['type'] ?? 'string';
            $secret = (bool) ($field['secret'] ?? false);
            $value = $incoming[$key] ?? null;

            if ($secret && in_array($key, $clearSecrets, true)) {
                unset($config[$key]);

                continue;
            }

            if ($type === 'boolean') {
                $config[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            if ($type === 'csv') {
                $items = is_array($value) ? $value : explode(',', (string) ($value ?? ''));
                $items = array_values(array_unique(array_filter(
                    array_map(fn ($item): string => trim((string) $item), $items),
                    fn (string $item): bool => $item !== ''
                )));
                $this->validateCsvField($key, $items);
                $config[$key] = $items;

                continue;
            }

            $value = trim((string) ($value ?? ''));

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

        return $config;
    }

    /**
     * Keep only metadata that is not a declared provider credential/control field.
     *
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $existing
     * @return array<string,mixed>
     */
    private function preserveUnknownMetadata(array $definition, array $existing): array
    {
        $reserved = [
            ...array_keys($definition['fields'] ?? []),
            'environment', 'profiles', 'is_production', 'testing', 'base_url', 'callback_url',
        ];

        return collect($existing)->except($reserved)->all();
    }

    private function canonicalRouteUrl(string $routeName): string
    {
        $path = route($routeName, [], false);

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless($request->user('admin')?->role === 'SUPER_ADMIN', 403);
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
