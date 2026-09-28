<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TurnstileService
{
    public function __construct(private readonly IntegrationConfigService $integrations)
    {
    }

    /** @return array{enabled:bool,siteKey:?string} */
    public function publicConfig(): array
    {
        [$enabled, $siteKey] = $this->config();

        return [
            'enabled' => $enabled,
            'siteKey' => $enabled ? $siteKey : null,
        ];
    }

    public function verify(Request $request, ?string $token): bool
    {
        [$enabled, , $secretKey, $verifyUrl] = $this->config();
        if (!$enabled) {
            return true;
        }

        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        if ($verifyUrl === '') {
            throw new RuntimeException('URL verifikasi Turnstile belum dikonfigurasi.');
        }

        $payload = [
            'secret' => $secretKey,
            'response' => $token,
        ];

        $ip = trim((string) $request->header('CF-Connecting-IP', ''));
        if ($ip !== '') {
            $payload['remoteip'] = $ip;
        }

        $response = Http::asForm()
            ->timeout(8)
            ->post($verifyUrl, $payload);

        if (!$response->successful()) {
            throw new RuntimeException('Verifikasi Turnstile sedang tidak tersedia.');
        }

        return $response->json('success') === true;
    }

    /** @return array{0:bool,1:string,2:string,3:string} */
    private function config(): array
    {
        $runtime = $this->integrations->turnstileRuntime();
        $siteKey = $runtime['siteKey'];
        $secretKey = $runtime['secretKey'];
        $verifyUrl = $runtime['verifyUrl'];

        if (($siteKey === '') !== ($secretKey === '')) {
            throw new RuntimeException('Konfigurasi Turnstile belum lengkap. Isi Site Key dan Secret Key bersamaan.');
        }

        return [$siteKey !== '' && $secretKey !== '', $siteKey, $secretKey, $verifyUrl];
    }
}
