<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Services\IntegrationRegistry;
use App\Services\IntegrationRuntimeConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menampilkan halaman integrasi admin: daftar integrasi beserta status
 * kesiapan koneksi dan callback-nya, dihitung dari konfigurasi tersimpan.
 * Menyimpan kredensial diurus AdminIntegrationCredentialController;
 * mengetes koneksi diurus AdminIntegrationTestController.
 */
class AdminIntegrationController
{
    public function index(IntegrationRegistry $registry, IntegrationRuntimeConfig $runtime): Response
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu lintasan baca-tampil — definisi integrasi dibaca sekali,
        // lalu status tiap integrasi disusun dalam konteks yang sama.
        // Penilaiannya sendiri sudah dipecah ke fungsi presentasi di bawah.
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
            // Show an empty Production form when old credentials are retired.
            // Do not reuse or silently activate the old Test/Sandbox key.
            if ($environment === null && count($environments) === 1 && isset($environments['production'])) {
                $environment = 'production';
            }

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

            if ($active && $currentProfile['required_complete'] && $healthMatchesEnvironment
                && ($storedHealth['reason'] ?? null) === 'SAFE_PROBE_UNAVAILABLE') {
                $message = 'Kredensial tersimpan. Tes koneksi otomatis tidak tersedia; verifikasi dilakukan saat fitur digunakan.';
            }

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
                    ? 'Kredensial lengkap'
                    : 'Belum dikonfigurasi',
                'credential' => [
                    'status' => $currentProfile['required_complete'] ? 'CONFIGURED' : 'MISSING',
                    'label' => $currentProfile['required_complete'] ? 'Sudah diisi' : 'Kredensial belum lengkap',
                ],
                'connection' => $connection,
                'callback' => $callback,
                'e2e' => [
                    'status' => 'NOT_RECORDED',
                    'label' => 'Hasil uji transaksi belum dicatat',
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
                // Only count failures, missing credentials, or callbacks requiring a fix.
                'attention' => $integrations
                    ->filter(fn (array $item): bool => $item['is_active'] && (
                        ! $item['required_complete']
                        || $item['connection']['status'] === 'FAILED'
                        || ($item['callback']['status'] ?? null) === 'NOT_READY'
                    ))->count(),
                // Safe read-only probes cannot verify every provider; these are not errors.
                'awaiting_verification' => $integrations
                    ->filter(fn (array $item): bool => $item['is_active']
                        && $item['required_complete']
                        && ($item['callback']['status'] ?? null) !== 'NOT_READY'
                        && in_array($item['connection']['status'], ['CONFIGURED', 'UNVERIFIED', 'NOT_TESTED'], true)
                    )->count(),
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

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $config
     * @return array{fields:array<int,array<string,mixed>>,required_total:int,configured_required:int,required_complete:bool}
     */
    private function presentProfile(array $definition, array $config): array
    {
        // Alasan belum dipecah: pemetaan satu profil dalam satu lintasan;
        // tidak ada urusan kedua yang bisa dipisahkan.
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
        // Alasan belum dipecah: syarat callback per penyedia dikumpulkan
        // lalu disimpulkan menjadi satu status; membacanya setengah-
        // setengah menghilangkan makna kesimpulannya.
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
            $status === 'DEGRADED' && in_array($reason, ['SAFE_PROBE_UNAVAILABLE', 'PERMISSION_LIMITED'], true) => 'CONFIGURED',
            $status === 'DEGRADED' => 'UNVERIFIED',
            default => 'NOT_TESTED',
        };

        $label = match ($connectionStatus) {
            'VERIFIED' => 'Tes terakhir berhasil',
            'CONFIGURED' => 'Tersimpan — tes otomatis tidak tersedia',
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
            'VERIFIED' => ['status' => 'READY', 'label' => 'Koneksi terverifikasi'],
            'FAILED' => ['status' => 'BLOCKED', 'label' => 'Koneksi bermasalah'],
            'CONFIGURED' => ['status' => 'CONFIGURED', 'label' => 'Tersimpan — tes otomatis tidak tersedia'],
            'UNVERIFIED' => ['status' => 'CONFIGURED', 'label' => 'Tersimpan — belum terverifikasi'],
            default => ['status' => 'CONFIGURED_UNTESTED', 'label' => 'Tersimpan — belum dites'],
        };
    }

    private function canonicalRouteUrl(string $routeName): string
    {
        $path = route($routeName, [], false);

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
