<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Services\AdminAuditService;
use App\Services\DiscordWebhookUrl;
use App\Services\IntegrationRegistry;
use App\Services\IntegrationRuntimeConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan kredensial dan pengaturan satu integrasi dari panel admin,
 * termasuk aturan khusus per penyedia (webhook Discord, thread Telegram,
 * jalur endpoint, daftar CSV) dan pembuangan rahasia yang diminta dihapus.
 * Hanya super admin. Mengetes koneksi bukan urusan class ini.
 */
class AdminIntegrationCredentialController
{
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
                if ($environment === null && count($environments) === 1 && isset($environments['production'])) {
                    $environment = 'production';
                }
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
            if ($code === 'telegram') {
                // Discard retired per-destination settings on the next credential save.
                foreach (array_keys($target) as $key) {
                    if ((str_starts_with($key, 'thread_') || str_starts_with($key, 'bot_token_'))
                        && ! array_key_exists($key, $definition['fields'])) {
                        unset($target[$key]);
                    }
                }
            }
            if ($code === 'digiflazz') {
                unset(
                    $target['testing'],
                    $target['is_production'],
                    $target['environment'],
                    $target['profiles'],
                    $target['base_url'],
                    $target['callback_url']
                );
            }
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

        // Retire old Discord webhook keys when the four-purpose configuration is saved.
        // Hidden legacy fields must not survive in credentials.
        if ($code === 'discord') {
            foreach (array_keys($config) as $key) {
                if (str_starts_with($key, 'webhook_url_')
                    && ! in_array($key, ['webhook_url_order', 'webhook_url_tugas', 'webhook_url_sistem', 'webhook_url_arsip'], true)) {
                    unset($config[$key]);
                }
            }
        }

        $selectedConfig = $environments !== [] && $registry->credentialsArePerEnvironment($code)
            ? (is_array($config['profiles'][$environment] ?? null) ? $config['profiles'][$environment] : [])
            : $config;

        // Discord: setiap webhook_url_* per-channel harus URL webhook
        // Discord yang valid — bukan cuma webhook_url default. Tanpa
        // ini, URL per-channel malformed / bukan-HTTPS / bukan-Discord
        // tersimpan diam-diam, lalu delivery (webhookUrl) menganggap
        // nilainya tidak ada dan notif jatuh ke webhook default — alert
        // mendarat di channel yang salah padahal integrasi tampak
        // terkonfigurasi benar.
        if ($code === 'discord') {
            $this->validateDiscordWebhooks($selectedConfig);
        }
        if ($code === 'telegram') {
            $this->validateTelegramThreads($selectedConfig);
        }

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
            'changed_fields' => array_keys($config),
        ]);

        return back()->with('status', 'Pengaturan '.$definition['name'].' berhasil disimpan.');
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
     * @param  array<string,mixed>  $config
     */
    private function validateDiscordWebhooks(array $config): void
    {
        foreach ($config as $key => $value) {
            if (! str_starts_with((string) $key, 'webhook_url')) {
                continue;
            }
            $url = trim((string) $value);
            if ($url === '' || DiscordWebhookUrl::valid($url)) {
                continue;
            }

            throw ValidationException::withMessages([
                'config.'.$key => 'Webhook Discord tidak valid. Gunakan URL webhook Discord (https://discord.com/api/webhooks/...).',
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function validateTelegramThreads(array $config): void
    {
        foreach (['order', 'tugas', 'sistem', 'arsip'] as $bucket) {
            $key = 'thread_'.$bucket;
            $value = trim((string) ($config[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 2147483647],
            ]) === false) {
                throw ValidationException::withMessages([
                    'config.'.$key => 'ID topik Telegram harus berupa bilangan bulat positif.',
                ]);
            }
        }
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

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless($request->user('admin')?->role === 'SUPER_ADMIN', 403);
    }
}
