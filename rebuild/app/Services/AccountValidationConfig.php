<?php

namespace App\Services;

use App\Models\IntegrationCredential;

class AccountValidationConfig
{
    /**
     * @return array{api_key:string,base_url:string,resolved_ip:string,nickname_path:string,region_path:string,pln_path:string}|null
     */
    public function active(): ?array
    {
        $record = IntegrationCredential::where('code', 'kokinpay')
            ->where('is_active', true)
            ->first();

        $config = $record?->config_ciphertext;
        if (! is_array($config)) {
            return null;
        }

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $nicknamePath = trim((string) ($config['nickname_path'] ?? ''));
        $regionPath = trim((string) ($config['region_path'] ?? ''));
        $plnPath = trim((string) ($config['pln_path'] ?? ''));

        $resolvedIp = $this->resolvePublicIp($baseUrl);

        if (
            $apiKey === ''
            || $resolvedIp === null
            || ! $this->validPath($nicknamePath)
            || ! $this->validPath($regionPath)
            || ! $this->validPath($plnPath)
        ) {
            return null;
        }

        return [
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'resolved_ip' => $resolvedIp,
            'nickname_path' => $nicknamePath,
            'region_path' => $regionPath,
            'pln_path' => $plnPath,
        ];
    }

    /**
     * @return array{is_active:bool,is_configured:bool,base_url:?string,nickname_path:?string,region_path:?string,pln_path:?string}
     */
    public function summary(): array
    {
        $record = IntegrationCredential::where('code', 'kokinpay')->first();
        $config = is_array($record?->config_ciphertext) ? $record->config_ciphertext : [];

        return [
            'is_active' => (bool) $record?->is_active,
            'is_configured' => $this->active() !== null,
            'base_url' => $this->safeUrl($config['base_url'] ?? null),
            'nickname_path' => $this->safePath($config['nickname_path'] ?? null),
            'region_path' => $this->safePath($config['region_path'] ?? null),
            'pln_path' => $this->safePath($config['pln_path'] ?? null),
        ];
    }

    public function endpoint(array $config, string $pathKey): string
    {
        return $config['base_url'].$config[$pathKey];
    }

    private function safeUrl(mixed $value): ?string
    {
        $url = rtrim(trim((string) $value), '/');

        return $this->validBaseUrl($url) ? $url : null;
    }

    private function safePath(mixed $value): ?string
    {
        $path = trim((string) $value);

        return $this->validPath($path) ? $path : null;
    }

    private function validBaseUrl(string $url): bool
    {
        return $this->resolvePublicIp($url) !== null;
    }

    private function resolvePublicIp(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== ''
        ) {
            return null;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->publicIp($host) ? $host : null;
        }

        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/', $host)) {
            return null;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return null;
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip) || ! $this->publicIp($ip)) {
                return null;
            }
            $addresses[] = $ip;
        }

        return $addresses[0] ?? null;
    }

    private function publicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function validPath(string $path): bool
    {
        return $path !== ''
            && mb_strlen($path) <= 255
            && str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '://')
            && ! str_contains($path, '..');
    }
}
