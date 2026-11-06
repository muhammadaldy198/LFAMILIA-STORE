<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class H2hNicknameClient
{
    /**
     * Check whether an H2H credential and an allowed endpoint are configured.
     */
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
                    ...($zone !== null && $zone !== '' ? ['zone_id' => $zone] : []),
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

            // H2H nests account and eligibility at the top level; older replies
            // may put these values directly in the response or inside data.
            $payload = is_array($json['data'] ?? null) ? $json['data'] : [];
            $merged = [...$payload, ...$json];
            $account = is_array($merged['account'] ?? null) ? $merged['account'] : [];
            $eligibility = is_array($merged['eligibility'] ?? null) ? $merged['eligibility'] : [];

            return [
                ...$merged,
                'status' => $json['status'],
                'nickname' => $account['nickname'] ?? $merged['nickname'] ?? null,
                'region' => $account['region'] ?? $merged['region'] ?? null,
                'first_purchase' => $eligibility['first_purchase'] ?? $merged['first_purchase'] ?? null,
                'wdp_eligible' => $eligibility['wdp_eligible'] ?? $merged['wdp_eligible'] ?? null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Allow remote HTTPS endpoints or loopback HTTP without URL credentials.
     */
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
