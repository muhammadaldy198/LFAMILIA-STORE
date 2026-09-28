<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleIdentityService
{
    public function __construct(private readonly IntegrationConfigService $integrations)
    {
    }

    /** @return array{subject:string,email:string,name:string,picture:string} */
    public function verify(string $credential): array
    {
        $clientId = (string) ($this->integrations->googleClientId() ?? '');
        $tokenInfoUrl = trim((string) config('lfamilia.integrations.google.tokeninfo_url'));

        if ($clientId === '' || !$this->validHttpsUrl($tokenInfoUrl)) {
            throw new RuntimeException('Login Google belum dikonfigurasi.');
        }

        $response = Http::acceptJson()
            ->timeout(10)
            ->get($tokenInfoUrl, ['id_token' => $credential]);

        $payload = $response->json();
        if (!$response->successful() || !is_array($payload)) {
            throw new RuntimeException('Credential Google tidak valid.');
        }

        $issuer = trim((string) ($payload['iss'] ?? ''));
        $audience = trim((string) ($payload['aud'] ?? ''));
        $subject = trim((string) ($payload['sub'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $verified = $payload['email_verified'] ?? false;
        $verified = $verified === true || strtolower((string) $verified) === 'true';
        $expires = is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : 0;

        if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
            || !hash_equals($clientId, $audience)
            || $subject === ''
            || $email === ''
            || !$verified
            || $expires <= time()) {
            throw new RuntimeException('Akun Google tidak menyediakan identitas terverifikasi yang valid.');
        }

        return [
            'subject' => $subject,
            'email' => $email,
            'name' => trim((string) ($payload['name'] ?? '')) ?: (explode('@', $email)[0] ?: 'Pelanggan'),
            'picture' => trim((string) ($payload['picture'] ?? '')),
        ];
    }

    /** @return array{enabled:bool,clientId:?string} */
    public function publicStatus(): array
    {
        $clientId = (string) ($this->integrations->googleClientId() ?? '');

        return [
            'enabled' => $clientId !== '',
            'clientId' => $clientId !== '' ? $clientId : null,
        ];
    }

    private function validHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
    }
}
