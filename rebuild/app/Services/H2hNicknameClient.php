<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class H2hNicknameClient
{
    public function configured(): bool
    {
        return trim((string) config('lfamilia.h2h.api_key')) !== ''
            && $this->allowedBaseUrl((string) config('lfamilia.h2h.base_url'));
    }

    /**
     * Invalid configuration, transport errors and unrecognized verdicts are advisory.
     *
     * @return array<string, mixed>|null
     */
    public function check(string $game, string $userId, ?string $zone = null): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $baseUrl = trim((string) config('lfamilia.h2h.base_url'));
        if (! $this->allowedBaseUrl($baseUrl)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-API-Key' => (string) config('lfamilia.h2h.api_key')])
                ->withoutRedirecting()
                ->connectTimeout(2)
                ->timeout(max(2, (int) config('lfamilia.h2h.timeout', 4)))
                ->post(rtrim($baseUrl, '/').'/api/v1/check', [
                    'game' => $game,
                    'user_id' => $userId,
                    ...($zone !== null && $zone !== '' ? ['zone' => $zone] : []),
                ]);
            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json) || ! in_array($json['status'] ?? null, [
                'VALID', 'NOT_FOUND', 'REGION_MISMATCH', 'ROUTE_ERROR',
            ], true)) {
                return null;
            }

            // Accept both flat and nested data fields, but never trust the status in data.
            $payload = is_array($json['data'] ?? null) ? $json['data'] : [];

            return [...$payload, ...$json];
        } catch (Throwable) {
            return null;
        }
    }

    private function allowedBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        return strtolower((string) $parts['scheme']) === 'https' || $loopback;
    }
}
