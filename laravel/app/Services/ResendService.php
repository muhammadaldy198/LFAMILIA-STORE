<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ResendService
{
    public function send(string $to, string $subject, string $html, string $idempotencyKey): void
    {
        $apiKey = trim((string) config('lfamilia.integrations.resend.api_key'));
        $from = trim((string) config('lfamilia.integrations.resend.from'));
        $apiUrl = trim((string) config('lfamilia.integrations.resend.api_url'));

        if ($apiKey === '' || $from === '' || !$this->validHttpsUrl($apiUrl)) {
            throw new RuntimeException('Layanan email belum dikonfigurasi.');
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->timeout(12)
            ->post($apiUrl, [
                'from' => $from,
                'to' => [trim($to)],
                'subject' => $subject,
                'html' => $html,
            ]);

        if (!$response->successful()) {
            $message = trim((string) $response->json('message', ''));
            throw new RuntimeException($message ?: 'Layanan email menolak permintaan.');
        }
    }

    private function validHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
    }
}
