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
                    'Credential Google OAuth dikonfigurasi. Validasi client dan redirect memerlukan alur OAuth pengguna melalui tombol login Google.'
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

        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        if ($response->successful() && is_numeric($response->json('data.deposit'))) {
            return $this->verified('Credential Digiflazz terverifikasi melalui pengecekan saldo non-transaksional.');
        }

        $responseCode = (string) ($response->json('data.rc') ?? $response->json('rc') ?? '');
        if ($responseCode === '45') {
            return $this->unverified(
                'Credential Digiflazz belum dapat diverifikasi karena IP VPS belum dikenali provider.',
                'IP_WHITELIST_REQUIRED'
            );
        }
        if (in_array($responseCode, ['41', '42'], true) || $this->isAuthenticationFailure($response)) {
            return $this->invalidCredential('Credential Digiflazz tidak valid.');
        }

        return $this->unverified(
            'Endpoint Digiflazz merespons, tetapi credential belum dapat dibuktikan dengan aman.'
        );
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

        if ($response->status() === 401) {
            return $this->invalidCredential();
        }
        if ($response->status() === 403) {
            // Sending-access keys are intentionally unable to list domains.
            // A read-only probe cannot prove send permission; do not mislabel
            // a scope restriction as an invalid credential or a healthy send.
            return $this->unverified(
                'API key Resend memiliki akses terbatas. Tes baca-saja tidak dapat memverifikasi izin kirim. Lakukan tes pengiriman email terpisah untuk memastikan.',
                'PERMISSION_LIMITED'
            );
        }
        if ($this->isProviderUnavailable($response)) {
            return $this->unavailable();
        }

        return $response->successful()
            ? $this->verified('Credential Resend terverifikasi melalui endpoint domain.')
            : $this->unverified('Credential Resend belum dapat diverifikasi dengan probe non-pengiriman.');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string,reason:string,verified:bool}
     */
    private function midtrans(array $config, ?string $environment): array
    {
        if (empty($config['server_key']) || ($environment !== 'production' && ! (app()->environment('testing') && $environment === 'sandbox'))) {
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
        if ($siteKey === '' || $secret === '' || ($environment !== 'production' && ! (app()->environment('testing') && $environment === 'test'))) {
            return $this->notConfigured('Site Key/Secret Key/environment Turnstile belum lengkap.');
        }

        if ($environment === 'test' && app()->environment('testing')) {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(8)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => 'XXXX.DUMMY.TOKEN.XXXX',
                ]);
            if ($this->isProviderUnavailable($response)) {
                return $this->unavailable();
            }
            $payload = $response->json();

            return $response->successful() && is_array($payload) && ($payload['success'] ?? false) === true
                ? $this->verified('Credential Turnstile Test terverifikasi melalui Siteverify dengan dummy token resmi.')
                : $this->invalidCredential('Credential Turnstile Test tidak valid atau tidak cocok dengan mode Test.');
        }

        return $this->unverified(
            'Credential Turnstile Production dikonfigurasi. Verifikasi dilakukan saat challenge asli dipakai di browser.'
        );
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
        if ($environment !== 'production' && ! (app()->environment('testing') && $environment === 'test')) {
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
     * @return array{status:string,message:string,reason:string,verified:bool,channels?:array<int,array{channel:string,verified:bool,status:string,message:string}>}
     */
    private function discord(array $config): array
    {
        // Probe SETIAP webhook yang dideklarasikan (default + per-channel),
        // bukan cuma default: URL per-channel yang mati (mis. webhook
        // dihapus di Discord → 404) lolos validasi format saat save.
        // Delivery mencoba webhook default bila kirim ke per-channel
        // gagal (fallback di ExternalNotificationService::discord) —
        // tes koneksi harus menunjukkan channel mana yang bermasalah
        // agar admin memperbaikinya, bukan mengandalkan fallback diam-diam.
        // URL yang sama (mis. beberapa channel pakai webhook yang sama)
        // di-probe sekali saja.
        $urls = [];
        $default = trim((string) ($config['webhook_url'] ?? ''));
        if ($default !== '') {
            $urls['default'] = $default;
        }
        foreach (DiscordNotificationRouting::CHANNELS as $channel) {
            $url = trim((string) ($config['webhook_url_'.$channel] ?? ''));
            if ($url !== '') {
                $urls[$channel] = $url;
            }
        }
        if ($urls === []) {
            return $this->notConfigured('Webhook URL Discord belum valid.');
        }
        foreach ($urls as $channel => $url) {
            if (! DiscordWebhookUrl::valid($url)) {
                return $this->notConfigured("Webhook Discord channel {$channel} belum valid.");
            }
        }

        // Batas waktu KESELURUHAN yang bounded: probe jalan sekuensial,
        // tiap probe dibatasi timeout 8 detik — tanpa deadline, 11 URL
        // distinct yang semuanya stall makan ~88 detik dan tes
        // umumnya timeout sebelum mengembalikan diagnosis
        // per-channel. Deadline 7 detik + satu probe terakhir yang
        // boleh berjalan sampai timeout-nya (8 detik) = total
        // MAKSIMAL ~15 detik, berapa pun jumlah webhook yang stall.
        // Probe yang tidak sempat jalan tetap dilaporkan per-channel
        // (status DEGRADED, reason PROBE_DEADLINE_EXCEEDED), bukan
        // dihilangkan — diagnosis per-channel tidak boleh hilang.
        // (Alternatif probe konkuren ditolak: konkurensi tidak bisa
        // dibuktikan dengan Http::fake — callback fake jalan sinkron
        // saat promise dibangun — sementara deadline bisa dibuktikan
        // test dengan fake lambat.)
        $startedAt = microtime(true);
        $probed = [];
        foreach (array_unique(array_values($urls)) as $url) {
            if (microtime(true) - $startedAt >= 7.0) {
                $probed[$url] = $this->unverified(
                    'Webhook Discord tidak sempat di-probe — batas waktu tes koneksi tercapai.',
                    'PROBE_DEADLINE_EXCEEDED'
                );

                continue;
            }
            $probed[$url] = $this->probeDiscordWebhook($url);
        }

        $channels = [];
        $failed = [];
        foreach ($urls as $channel => $url) {
            $result = $probed[$url];
            $channels[] = [
                'channel' => $channel,
                'verified' => $result['verified'],
                'status' => $result['status'],
                'message' => $result['message'],
            ];
            if (! $result['verified']) {
                $failed[] = $channel;
            }
        }

        if ($failed !== []) {
            $defaultVerified = isset($urls['default'])
                && ($probed[$urls['default']]['verified'] ?? false);
            $message = 'Webhook Discord bermasalah di channel: '.implode(', ', $failed).'. ';
            $message .= $defaultVerified
                ? 'Webhook channel yang gagal dapat mencoba fallback ke webhook default yang terverifikasi.'
                : 'Webhook default tidak terverifikasi atau tidak tersedia; notifikasi untuk channel yang bergantung padanya tidak dapat dikirim sampai webhook diperbaiki.';

            return [
                ...$this->unverified($message),
                'channels' => $channels,
            ];
        }

        return [
            ...$this->verified('Webhook Discord terverifikasi tanpa mengirim notifikasi.'),
            'channels' => $channels,
        ];
    }

    /** @return array{status:string,message:string,reason:string,verified:bool} */
    private function probeDiscordWebhook(string $url): array
    {
        try {
            $response = Http::acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout(8)
                ->get($url);
        } catch (Throwable) {
            // Satu channel yang transport-nya gagal tidak boleh
            // menggugurkan probe channel lain (test() menangkap
            // Throwable jadi unavailable untuk SEMUA).
            return $this->unavailable();
        }

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
    private function unverified(string $message, string $reason = 'SAFE_PROBE_UNAVAILABLE'): array
    {
        return [
            'status' => 'DEGRADED',
            'message' => $message,
            'reason' => $reason,
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
