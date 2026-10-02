<?php

namespace App\Services;

use App\Models\IntegrationCredential;

class AccountValidationConfig
{
    /**
     * @return array{api_key:string,base_url:string,nickname_path:string,region_path:string,pln_path:string}|null
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

        if (
            $apiKey === ''
            || ! str_starts_with(strtolower($baseUrl), 'https://')
            || ! $this->validPath($nicknamePath)
            || ! $this->validPath($regionPath)
            || ! $this->validPath($plnPath)
        ) {
            return null;
        }

        return [
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'nickname_path' => $nicknamePath,
            'region_path' => $regionPath,
            'pln_path' => $plnPath,
        ];
    }

    public function endpoint(array $config, string $pathKey): string
    {
        return $config['base_url'].$config[$pathKey];
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
