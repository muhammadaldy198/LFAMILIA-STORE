<?php

namespace App\Services;

use App\Models\IntegrationCredential;
use Illuminate\Contracts\Encryption\DecryptException;

class IntegrationRuntimeConfig
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    /**
     * @return array{environment:?string,config:array<string,mixed>}|null
     */
    public function resolve(string $code, bool $activeOnly = true): ?array
    {
        $query = IntegrationCredential::query()->where('code', $code);
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $record = $query->first();
        if (! $record) {
            return null;
        }

        try {
            $stored = $record->config_ciphertext;
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($stored)) {
            return null;
        }

        $environment = $this->selectedEnvironment($code, $stored);

        return [
            'environment' => $environment,
            'config' => $this->profile($code, $stored, $environment),
        ];
    }

    /**
     * @param  array<string,mixed>  $stored
     */
    public function selectedEnvironment(string $code, array $stored): ?string
    {
        $definition = $this->registry->get($code);
        $environments = $definition['environments'] ?? [];
        if (! is_array($environments) || $environments === []) {
            return null;
        }

        $storedEnvironment = strtolower(trim((string) ($stored['environment'] ?? '')));
        if (array_key_exists($storedEnvironment, $environments)) {
            return $storedEnvironment;
        }

        if ($this->hasLegacyConfiguration($definition, $stored)) {
            $legacy = $this->legacyEnvironment($code, $stored);
            if ($legacy !== null && array_key_exists($legacy, $environments)) {
                return $legacy;
            }
        }

        $default = (string) ($definition['default_environment'] ?? array_key_first($environments));

        return array_key_exists($default, $environments) ? $default : (string) array_key_first($environments);
    }

    /**
     * Resolve only the requested environment. Never fall back to another environment profile.
     *
     * @param  array<string,mixed>  $stored
     * @return array<string,mixed>
     */
    public function profile(string $code, array $stored, ?string $environment = null): array
    {
        $definition = $this->registry->get($code);
        if (! $definition) {
            return [];
        }

        $environments = $definition['environments'] ?? [];
        if (! is_array($environments) || $environments === []) {
            return $stored;
        }

        $environment ??= $this->selectedEnvironment($code, $stored);
        if ($environment === null || ! array_key_exists($environment, $environments)) {
            return [];
        }

        if (($definition['credential_scope'] ?? 'shared') !== 'per_environment') {
            return $stored;
        }

        $profiles = $stored['profiles'] ?? null;
        if (is_array($profiles)) {
            $profile = $profiles[$environment] ?? null;

            return is_array($profile) ? $profile : [];
        }

        $legacyEnvironment = $this->legacyEnvironment($code, $stored);

        return $legacyEnvironment === $environment ? $stored : [];
    }

    /**
     * @param  array<string,mixed>  $stored
     * @return array<string,array<string,mixed>>
     */
    public function profiles(string $code, array $stored): array
    {
        $definition = $this->registry->get($code);
        $environments = $definition['environments'] ?? [];
        if (! is_array($environments) || $environments === []) {
            return [];
        }

        $profiles = [];
        foreach (array_keys($environments) as $environment) {
            $profiles[$environment] = $this->profile($code, $stored, (string) $environment);
        }

        return $profiles;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public function credentialsComplete(string $code, array $config): bool
    {
        foreach ($this->registry->requiredFields($code) as $field) {
            $value = $config[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }

    public function telegramBotBase(string $environment, string $token): string
    {
        $base = 'https://api.telegram.org/bot'.rawurlencode($token);

        return $environment === 'test' ? $base.'/test' : $base;
    }

    public function midtransApiBase(string $environment): string
    {
        return $environment === 'production'
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    public function midtransSnapBase(string $environment): string
    {
        return $environment === 'production'
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com';
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public function dokuApiBase(string $environment, array $config = []): string
    {
        if (app()->environment('testing') && $this->validTestOverride($config['base_url'] ?? null)) {
            return rtrim((string) $config['base_url'], '/');
        }

        return $environment === 'production'
            ? 'https://api.doku.com'
            : 'https://api-sandbox.doku.com';
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public function digiflazzApiBase(array $config = []): string
    {
        if (app()->environment('testing') && $this->validTestOverride($config['base_url'] ?? null)) {
            return rtrim((string) $config['base_url'], '/');
        }

        return 'https://api.digiflazz.com';
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $stored
     */
    private function hasLegacyConfiguration(array $definition, array $stored): bool
    {
        if (isset($stored['profiles']) && is_array($stored['profiles'])) {
            return false;
        }

        foreach (array_keys($definition['fields'] ?? []) as $field) {
            $value = $stored[$field] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return array_key_exists('is_production', $stored)
            || array_key_exists('testing', $stored)
            || array_key_exists('base_url', $stored);
    }

    /**
     * Infer only legacy flat records. This is migration compatibility, not cross-environment fallback.
     *
     * @param  array<string,mixed>  $stored
     */
    private function legacyEnvironment(string $code, array $stored): ?string
    {
        $storedEnvironment = strtolower(trim((string) ($stored['environment'] ?? '')));
        $environments = $this->registry->environments($code);
        if ($storedEnvironment !== '' && array_key_exists($storedEnvironment, $environments)) {
            return $storedEnvironment;
        }

        return match ($code) {
            'midtrans' => (bool) ($stored['is_production'] ?? false) ? 'production' : 'sandbox',
            'doku' => str_starts_with(
                strtolower(rtrim((string) ($stored['base_url'] ?? ''), '/')),
                'https://api.doku.com'
            ) ? 'production' : 'sandbox',
            'digiflazz' => (bool) ($stored['testing'] ?? false) ? 'test' : 'production',
            'turnstile', 'telegram' => 'production',
            default => null,
        };
    }

    private function validTestOverride(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value !== '' && str_starts_with(strtolower($value), 'https://');
    }
}
