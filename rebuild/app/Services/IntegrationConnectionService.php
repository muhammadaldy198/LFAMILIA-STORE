<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class IntegrationConnectionService
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{status:string,message:string}
     */
    public function test(string $code, array $config): array
    {
        try {
            return match ($code) {
                'digiflazz' => $this->digiflazz($config),
                'resend' => $this->resend($config),
                'midtrans' => $this->midtrans($config),
                'telegram' => $this->telegram($config),
                'discord' => $this->discord($config),
                default => ['status' => 'DEGRADED', 'message' => 'Konfigurasi tersimpan, tetapi integrasi ini tidak memiliki probe non-transaksional universal.'],
            };
        } catch (\Throwable) {
            return ['status' => 'DOWN', 'message' => 'Koneksi tidak berhasil diverifikasi.'];
        }
    }

    private function digiflazz(array $config): array
    {
        $username = trim((string) ($config['username'] ?? ''));
        $key = trim((string) ($config['api_key'] ?? ''));
        if ($username === '' || $key === '') {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'Username/API key belum lengkap.'];
        }

        $base = rtrim((string) ($config['base_url'] ?? 'https://api.digiflazz.com'), '/');
        $response = Http::acceptJson()->timeout(8)->post($base.'/v1/cek-saldo', [
            'cmd' => 'deposit',
            'username' => $username,
            'sign' => md5($username.$key.'depo'),
        ]);

        return $response->successful() && is_numeric($response->json('data.deposit'))
            ? ['status' => 'HEALTHY', 'message' => 'Koneksi terverifikasi.']
            : ['status' => 'DOWN', 'message' => 'Credential atau koneksi ditolak.'];
    }

    private function resend(array $config): array
    {
        if (empty($config['api_key'])) {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'API key belum diisi.'];
        }
        $response = Http::withToken((string) $config['api_key'])
            ->acceptJson()->timeout(8)->get('https://api.resend.com/domains', ['limit' => 1]);

        return $response->successful()
            ? ['status' => 'HEALTHY', 'message' => 'Resend API terverifikasi.']
            : ['status' => 'DOWN', 'message' => 'Resend menolak credential.'];
    }

    private function midtrans(array $config): array
    {
        if (empty($config['server_key'])) {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'Server key belum diisi.'];
        }
        $base = ! empty($config['is_production'])
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
        $response = Http::withBasicAuth((string) $config['server_key'], '')
            ->acceptJson()->timeout(8)->get($base.'/v2/LFAMILIA-CONNECTION-CHECK/status');

        return $response->status() === 401 || $response->serverError()
            ? ['status' => 'DOWN', 'message' => 'Midtrans menolak credential atau tidak dapat dijangkau.']
            : ['status' => 'HEALTHY', 'message' => 'Credential Midtrans diterima endpoint status.'];
    }

    private function telegram(array $config): array
    {
        $token = trim((string) ($config['bot_token'] ?? ''));
        if ($token === '') {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'Bot token belum diisi.'];
        }
        $response = Http::acceptJson()->timeout(8)
            ->get('https://api.telegram.org/bot'.rawurlencode($token).'/getMe');

        return $response->successful() && $response->json('ok') === true
            ? ['status' => 'HEALTHY', 'message' => 'Bot Telegram terverifikasi.']
            : ['status' => 'DOWN', 'message' => 'Bot Telegram tidak terverifikasi.'];
    }

    private function discord(array $config): array
    {
        $url = trim((string) ($config['webhook_url'] ?? ''));
        if (! str_starts_with(strtolower($url), 'https://')) {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'Webhook URL belum valid.'];
        }
        $response = Http::acceptJson()->timeout(8)->get($url);

        return $response->successful()
            ? ['status' => 'HEALTHY', 'message' => 'Webhook Discord terverifikasi.']
            : ['status' => 'DOWN', 'message' => 'Webhook Discord tidak terverifikasi.'];
    }
}
