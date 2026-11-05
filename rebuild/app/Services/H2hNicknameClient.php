<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client for the store's own H2H nickname-check service (separate app on
 * the same VPS, loopback only). Any failure — timeout, refusal, non-200,
 * malformed body — returns null so the caller can degrade to a warning;
 * a nickname check must never break checkout.
 */
class H2hNicknameClient
{
    public function configured(): bool
    {
        return trim((string) config('lfamilia.h2h.api_key', '')) !== ''
            && trim((string) config('lfamilia.h2h.base_url', '')) !== '';
    }

    /**
     * @return array<string, mixed>|null Decoded H2H response, or null when
     *                                   the service gave no usable answer.
     */
    public function check(string $game, string $userId, string $zone, ?string $expectedRegion): ?array
    {
        $baseUrl = rtrim((string) config('lfamilia.h2h.base_url', ''), '/');
        $timeout = max(1, (int) config('lfamilia.h2h.timeout', 6));

        $payload = ['game' => $game, 'user_id' => $userId];
        if ($zone !== '') {
            $payload['zone_id'] = $zone;
        }
        if ($expectedRegion !== null && $expectedRegion !== '') {
            $payload['expected_region'] = $expectedRegion;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-API-Key' => (string) config('lfamilia.h2h.api_key')])
                ->connectTimeout(2)
                ->timeout($timeout)
                ->post($baseUrl.'/api/v1/check', $payload);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && isset($data['status']) && is_string($data['status']) ? $data : null;
    }
}
