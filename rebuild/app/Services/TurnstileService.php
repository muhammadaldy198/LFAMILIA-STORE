<?php

namespace App\Services;

use App\Models\IntegrationCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TurnstileService
{
    public function publicConfig(): array
    {
        $config = $this->config();

        return [
            'enabled' => $config !== null,
            'site_key' => $config['site_key'] ?? null,
        ];
    }

    public function verify(Request $request, ?string $token): bool
    {
        $config = $this->config();
        if ($config === null) {
            return true;
        }

        if (! is_string($token) || trim($token) === '') {
            return false;
        }

        $response = Http::asForm()->timeout(8)->post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            [
                'secret' => $config['secret_key'],
                'response' => $token,
                'remoteip' => $request->ip(),
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('TURNSTILE_UNAVAILABLE');
        }

        return $response->json('success') === true;
    }

    private function config(): ?array
    {
        $credential = IntegrationCredential::where('code', 'turnstile')->where('is_active', true)->first();
        $config = $credential?->config_ciphertext;
        if (! is_array($config) || empty($config['site_key']) || empty($config['secret_key'])) {
            return null;
        }

        return $config;
    }
}
