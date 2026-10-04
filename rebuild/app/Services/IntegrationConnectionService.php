<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class IntegrationConnectionService
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    public function test(string $code, array $config, ?string $environment = null): array
    {
        try {
            return match ($code) {
                'digiflazz' => $this->digiflazz($config),
                'kokinpay' => $this->unverified(
                    'Credential Validasi Akun dikonfigurasi, tetapi adapter saat ini tidak mempunyai probe non-finansial universal yang aman.'
                ),
                'midtrans' => $this->midtrans($config, $environment),
                'doku' => $this->unverified(
                    'Credential DOKU dikonfigurasi, tetapi adapter pembayaran tidak mempunyai probe non-finansial yang dapat membuktikan credential tanpa membuat payment.'
                ),
                'resend' => $this->resend($config),
                'google_oauth' => $this->unverified(
                    'Credential Google OAuth dikonfigurasi. Validasi client dan redirect memerlukan alur OAuth pengguna dan ditunda ke Tahap 9.'
                ),
                'turnstile' => $this->turnstile($config, $environment),
                'telegram' => $this->telegram($config, $environment),
                'discord' => $this->discord($config),
                default => $this->unverified(
                    'Credential dikonfigurasi, tetapi integrasi ini tidak mempunyai probe non-transaksional universal.'
                ),
            };
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    private function digiflazz(array $config): array
    {
        $username = trim((string) ($config['username'] ?? ''));
        $key = trim((string) ($config['api_key'] ?? ''));
        if ($username === '' || $key === '') {
            return $this->notConfigured('Username/API key Digiflazz belum lengkap.');
        }

        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->post($this->runtime->digiflazzApiBase($config).'/v1/cek-saldo', [
                'cmd' => 'deposit',
                'username' => $username,
                'sign' => md5($username.$key.'depo'),
            ]);

        if ($this->isAuthenticationFailure($response)) {
            return $this->invalidCredential();
        }
        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        if ($response->successful() && is_numeric($response->json('data.deposit'))) {
            return $this->verified('Credential Digiflazz terverifikasi melalui pengecekan saldo non-transaksional.');
        }

        return $this->invalidCredential('Credential Digiflazz tidak dapat diverifikasi oleh endpoint akun.');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    private function resend(array $config): array
    {
        if (empty($config['api_key'])) {
            return $this->notConfigured('API key Resend belum diisi.');
        }

        $response = Http::withToken((string) $config['api_key'])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->get('https://api.resend.com/domains', ['limit' => 1]);

        if ($this->isAuthenticationFailure($response)) {
            return $this->invalidCredential();
        }
        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        return $response->successful()
            ? $this->verified('Credential Resend terverifikasi melalui endpoint domain.')
            : $this->invalidCredential('Credential Resend tidak dapat diverifikasi.');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    private function midtrans(array $config, ?string $environment): array
    {
        if (empty($config['server_key']) || ! in_array($environment, ['sandbox', 'production'], true)) {
            return $this->notConfigured('Server Key/environment Midtrans belum lengkap.');
        }

        $response = Http::withBasicAuth((string) $config['server_key'], '')
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->get($this->runtime->midtransApiBase((string) $environment).'/v2/LFAMILIA-CONNECTION-CHECK/status');

        if ($this->isAuthenticationFailure($response)) {
            return $this->invalidCredential();
        }
        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        $payload = $response->json();
        $statusCode = is_array($payload) ? ($payload['status_code'] ?? null) : null;

        if (in_array($response->status(), [200, 404], true) && is_scalar($statusCode)) {
            return $this->verified(
                'Credential Midtrans diterima oleh endpoint status '.strtoupper((string) $environment).'.'
            );
        }

        return $this->unverified(
            'Endpoint Midtrans merespons, tetapi penerimaan credential tidak dapat dibuktikan dengan aman.'
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    private function turnstile(array $config, ?string $environment): array
    {
        $siteKey = trim((string) ($config['site_key'] ?? ''));
        $secret = trim((string) ($config['secret_key'] ?? ''));
        if ($siteKey === '' || $secret === '' || ! in_array($environment, ['test', 'production'], true)) {
            return $this->notConfigured('Site Key/Secret Key/environment Turnstile belum lengkap.');
        }

        if ($environment !== 'test') {
            return $this->unverified(
                'Credential Turnstile Production dikonfigurasi. Production secret tidak dapat diuji dengan dummy token; challenge browser ditunda ke Tahap 9.'
            );
        }

        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => 'XXXX.DUMMY.TOKEN.XXXX',
            ]);

        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        $payload = $response->json();
        if ($response->successful() && is_array($payload) && ($payload['success'] ?? false) === true) {
            return $this->verified('Credential Turnstile Test terverifikasi melalui Siteverify dengan dummy token resmi.');
        }

        return $this->invalidCredential('Credential Turnstile Test tidak valid atau tidak cocok dengan mode Test.');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    private function telegram(array $config, ?string $environment): array
    {
        $token = trim((string) ($config['bot_token'] ?? ''));
        if ($token === '') {
            return $this->notConfigured('Bot token Telegram belum diisi.');
        }
        if (! in_array($environment, ['test', 'production'], true)) {
            return $this->notConfigured('Environment Telegram belum valid.');
        }

        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->get($this->runtime->telegramBotBase((string) $environment, $token).'/getMe');

        if (in_array($response->status(), [401, 403, 404], true)) {
            return $this->invalidCredential();
        }
        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        return $response->successful() && $response->json('ok') === true
            ? $this->verified('Bot Telegram terverifikasi melalui getMe tanpa mengirim notifikasi.')
            : $this->invalidCredential('Bot Telegram tidak dapat diverifikasi.');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    private function discord(array $config): array
    {
        $url = trim((string) ($config['webhook_url'] ?? ''));
        if (! str_starts_with(strtolower($url), 'https://')) {
            return $this->notConfigured('Webhook URL Discord belum valid.');
        }

        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->get($url);

        if (in_array($response->status(), [401, 403, 404], true)) {
            return $this->invalidCredential();
        }
        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        return $response->successful()
            ? $this->verified('Webhook Discord terverifikasi tanpa mengirim notifikasi.')
            : $this->unverified('Webhook Discord merespons, tetapi tidak dapat diverifikasi dengan pasti.');
    }

    private function isAuthenticationFailure(Response $response): bool
    {
        return in_array($response->status(), [401, 403], true);
    }

    private function isProviderUnavailable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /** @return array{status:string,message:string,reason:string,verified:bool} */
    private function verified(string $message): array
    {
        return [
            'status' => 'HEALTHY',
            'message' => $message,
            'reason' => 'VERIFIED_SAFE_PROBE',
            'verified' => true,
        ];
    }

    /** @return array{status:string,message:string,reason:string,verified:bool} */
    private function unverified(string $message): array
    {
        return [
            'status' => 'DEGRADED',
            'message' => $message,
            'reason' => 'SAFE_PROBE_UNAVAILABLE',
            'verified' => false,
        ];
    }

    /** @return array{status:string,message:string,reason:string,verified:bool} */
    private function invalidCredential(string $message = 'Credential tidak valid.'): array
    {
        return [
            'status' => 'DOWN',
            'message' => $message,
            'reason' => 'INVALID_CREDENTIAL',
            'verified' => false,
        ];
    }

    /** @return array{status:string,message:string,reason:string,verified:bool} */
    private function unavailable(): array
    {
        return [
            'status' => 'DOWN',
            'message' => 'Provider tidak dapat dijangkau. Coba lagi setelah koneksi provider pulih.',
            'reason' => 'PROVIDER_UNAVAILABLE',
            'verified' => false,
        ];
    }

    /** @return array{status:string,message:string,reason:string,verified:bool} */
    private function notConfigured(string $message): array
    {
        return [
            'status' => 'NOT_CONFIGURED',
            'message' => $message,
            'reason' => 'CREDENTIAL_MISSING',
            'verified' => false,
        ];
    }
}
