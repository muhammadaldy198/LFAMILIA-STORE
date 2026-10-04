<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TurnstileService
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    /**
     * @return array{enabled:bool,site_key:?string}
     */
    public function publicConfig(): array
    {
        $config = $this->config();
        $siteKey = is_array($config) ? trim((string) ($config['site_key'] ?? '')) : '';

        return [
            'enabled' => is_array($config) && $siteKey !== '' && trim((string) ($config['secret_key'] ?? '')) !== '',
            'site_key' => $siteKey !== '' ? $siteKey : null,
        ];
    }

    public function verify(Request $request, string $action): void
    {
        $config = $this->config();
        if (! is_array($config)) {
            return;
        }

        $secret = trim((string) ($config['secret_key'] ?? ''));
        $siteKey = trim((string) ($config['site_key'] ?? ''));
        if ($secret === '' || $siteKey === '') {
            return;
        }

        $token = trim((string) $request->input('turnstile_token', ''));
        if ($token === '' || mb_strlen($token) > 2048) {
            throw ValidationException::withMessages([
                'turnstile_token' => 'Verifikasi keamanan wajib diselesaikan.',
            ]);
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                    'idempotency_key' => (string) Str::uuid(),
                ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'turnstile_token' => 'Verifikasi keamanan sementara tidak tersedia. Coba lagi.',
            ]);
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['success'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'turnstile_token' => 'Verifikasi keamanan gagal atau sudah kedaluwarsa.',
            ]);
        }

        $returnedAction = (string) ($data['action'] ?? '');
        if ($returnedAction !== '' && ! hash_equals($action, $returnedAction)) {
            throw ValidationException::withMessages([
                'turnstile_token' => 'Verifikasi keamanan tidak cocok dengan aksi ini.',
            ]);
        }

        $allowed = $config['allowed_hostnames'] ?? [];
        if (is_string($allowed)) {
            $allowed = array_values(array_filter(array_map('trim', explode(',', $allowed))));
        }
        if (is_array($allowed) && $allowed !== []) {
            $hostname = strtolower(trim((string) ($data['hostname'] ?? '')));
            $normalized = array_map(fn ($host): string => strtolower(trim((string) $host)), $allowed);
            if ($hostname === '' || ! in_array($hostname, $normalized, true)) {
                throw ValidationException::withMessages([
                    'turnstile_token' => 'Host verifikasi keamanan tidak diizinkan.',
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(): ?array
    {
        if (! Schema::hasTable('integration_credentials')) {
            return null;
        }

        $resolved = $this->runtime->resolve('turnstile');

        return isset($resolved['config']) && is_array($resolved['config']) ? $resolved['config'] : null;
    }
}
