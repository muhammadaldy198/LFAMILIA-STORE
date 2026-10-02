<?php

namespace App\Services;

class IntegrationRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'digiflazz' => [
                'name' => 'Digiflazz',
                'fields' => [
                    'username' => ['label' => 'Username', 'secret' => false],
                    'api_key' => ['label' => 'API Key', 'secret' => true],
                    'webhook_secret' => ['label' => 'Webhook Secret', 'secret' => true],
                    'base_url' => ['label' => 'Base URL', 'secret' => false],
                    'callback_url' => ['label' => 'Callback URL', 'secret' => false],
                    'testing' => ['label' => 'Testing mode', 'secret' => false, 'type' => 'boolean'],
                ],
            ],
            'kokinpay' => [
                'name' => 'Validasi Akun',
                'fields' => [
                    'api_key' => ['label' => 'API Key', 'secret' => true],
                    'base_url' => ['label' => 'Base URL', 'secret' => false],
                    'nickname_path' => ['label' => 'Path cek nickname', 'secret' => false],
                    'region_path' => ['label' => 'Path cek region', 'secret' => false],
                    'pln_path' => ['label' => 'Path cek PLN', 'secret' => false],
                ],
                'note' => 'Base URL dan path endpoint dipakai oleh Cek Game, Region MLBB, PLN, serta validasi saat checkout. Semua nilai dapat diubah tanpa mengubah kode aplikasi.',
            ],
            'midtrans' => [
                'name' => 'Midtrans Snap',
                'fields' => [
                    'server_key' => ['label' => 'Server Key', 'secret' => true],
                    'client_key' => ['label' => 'Client Key', 'secret' => true],
                    'is_production' => ['label' => 'Production mode', 'secret' => false, 'type' => 'boolean'],
                ],
            ],
            'doku' => [
                'name' => 'DOKU Direct API',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'secret' => true],
                    'secret_key' => ['label' => 'Secret Key', 'secret' => true],
                    'base_url' => ['label' => 'Base URL', 'secret' => false],
                ],
            ],
            'resend' => [
                'name' => 'Resend Email',
                'fields' => [
                    'api_key' => ['label' => 'API Key', 'secret' => true],
                    'from_email' => ['label' => 'From Email', 'secret' => false],
                    'admin_recipients' => ['label' => 'Admin recipients', 'secret' => false, 'type' => 'csv'],
                ],
            ],
            'google_oauth' => [
                'name' => 'Google OAuth',
                'fields' => [
                    'client_id' => ['label' => 'Client ID', 'secret' => true],
                    'client_secret' => ['label' => 'Client Secret', 'secret' => true],
                ],
            ],
            'telegram' => [
                'name' => 'Telegram Notifications',
                'fields' => [
                    'bot_token' => ['label' => 'Bot Token', 'secret' => true],
                    'chat_id' => ['label' => 'Chat ID', 'secret' => true],
                ],
            ],
            'discord' => [
                'name' => 'Discord Notifications',
                'fields' => [
                    'webhook_url' => ['label' => 'Webhook URL', 'secret' => true],
                ],
            ],
            'turnstile' => [
                'name' => 'Cloudflare Turnstile',
                'fields' => [
                    'site_key' => ['label' => 'Site Key', 'secret' => false],
                    'secret_key' => ['label' => 'Secret Key', 'secret' => true],
                    'allowed_hostnames' => ['label' => 'Allowed hostnames', 'secret' => false, 'type' => 'csv'],
                ],
                'note' => 'Turnstile aktif pada register, forgot password, order lookup, guest checkout, dan login yang terdeteksi mencurigakan.',
            ],
        ];
    }

    public function get(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function secretFields(string $code): array
    {
        $definition = $this->get($code);
        if (! $definition) {
            return [];
        }

        return collect($definition['fields'])
            ->filter(fn (array $field): bool => (bool) ($field['secret'] ?? false))
            ->keys()->values()->all();
    }
}
