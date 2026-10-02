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
                'group' => 'Provider',
                'description' => 'Kredensial transaksi dan sinkronisasi produk Digiflazz.',
                'fields' => [
                    'username' => [
                        'label' => 'Username Digiflazz',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Username akun buyer Digiflazz yang dipakai backend.',
                    ],
                    'api_key' => [
                        'label' => 'API Key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci API buyer. Nilai tidak pernah dikirim ke halaman pelanggan.',
                    ],
                    'webhook_secret' => [
                        'label' => 'Rahasia webhook',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Dipakai bila callback Digiflazz dikunci dengan rahasia tambahan.',
                    ],
                    'base_url' => [
                        'label' => 'Alamat API',
                        'secret' => false,
                        'required' => false,
                        'help' => 'Alamat dasar API Digiflazz. Wajib HTTPS bila diisi.',
                    ],
                    'callback_url' => [
                        'label' => 'Alamat callback',
                        'secret' => false,
                        'required' => false,
                        'help' => 'Alamat callback yang didaftarkan ke provider. Wajib HTTPS bila diisi.',
                    ],
                    'testing' => [
                        'label' => 'Mode pengujian',
                        'secret' => false,
                        'required' => false,
                        'type' => 'boolean',
                        'help' => 'Aktifkan hanya saat menggunakan alur pengujian provider.',
                    ],
                ],
            ],
            'kokinpay' => [
                'name' => 'Validasi Akun',
                'group' => 'Validasi',
                'description' => 'API pengecekan nickname, region MLBB, dan pelanggan PLN.',
                'fields' => [
                    'api_key' => [
                        'label' => 'API Key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci akses layanan validasi akun.',
                    ],
                    'base_url' => [
                        'label' => 'Alamat API',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Alamat dasar API validasi. Wajib HTTPS.',
                    ],
                    'nickname_path' => [
                        'label' => 'Path cek nickname',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Path endpoint cek nickname, misalnya /api/check-nickname.',
                    ],
                    'region_path' => [
                        'label' => 'Path cek region MLBB',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Path endpoint cek region MLBB.',
                    ],
                    'pln_path' => [
                        'label' => 'Path cek pelanggan PLN',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Path endpoint cek nama pelanggan PLN.',
                    ],
                ],
                'note' => 'Hanya game yang ditandai mendukung cek nickname yang memakai integrasi ini saat checkout.',
            ],
            'midtrans' => [
                'name' => 'Midtrans Snap',
                'group' => 'Pembayaran',
                'description' => 'Kredensial Midtrans untuk pembuatan pembayaran dan verifikasi notifikasi.',
                'fields' => [
                    'server_key' => [
                        'label' => 'Server Key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci server Midtrans. Hanya digunakan backend.',
                    ],
                    'client_key' => [
                        'label' => 'Client Key',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Kunci client bila dibutuhkan oleh flow Snap.',
                    ],
                    'is_production' => [
                        'label' => 'Gunakan mode produksi',
                        'secret' => false,
                        'required' => false,
                        'type' => 'boolean',
                        'help' => 'Nonaktif berarti lingkungan sandbox.',
                    ],
                ],
            ],
            'doku' => [
                'name' => 'DOKU Direct API',
                'group' => 'Pembayaran',
                'description' => 'Kredensial DOKU Direct API untuk channel yang dirutekan melalui DOKU.',
                'fields' => [
                    'client_id' => [
                        'label' => 'Client ID',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Identitas merchant DOKU.',
                    ],
                    'secret_key' => [
                        'label' => 'Secret Key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci penandatanganan request DOKU. Hanya digunakan backend.',
                    ],
                    'base_url' => [
                        'label' => 'Alamat API',
                        'secret' => false,
                        'required' => false,
                        'help' => 'Alamat dasar DOKU Direct API. Wajib HTTPS bila diisi.',
                    ],
                ],
            ],
            'resend' => [
                'name' => 'Resend Email',
                'group' => 'Email',
                'description' => 'Pengiriman email transaksi dan notifikasi operasional.',
                'fields' => [
                    'api_key' => [
                        'label' => 'API Key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci API Resend.',
                    ],
                    'from_email' => [
                        'label' => 'Email pengirim',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Alamat email terverifikasi yang digunakan sebagai pengirim.',
                    ],
                    'admin_recipients' => [
                        'label' => 'Email penerima Admin',
                        'secret' => false,
                        'required' => false,
                        'type' => 'csv',
                        'help' => 'Pisahkan beberapa alamat email dengan koma.',
                    ],
                ],
            ],
            'google_oauth' => [
                'name' => 'Google OAuth',
                'group' => 'Akun pelanggan',
                'description' => 'Login dan pendaftaran pelanggan menggunakan akun Google.',
                'fields' => [
                    'client_id' => [
                        'label' => 'Client ID',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Client ID aplikasi OAuth Google.',
                    ],
                    'client_secret' => [
                        'label' => 'Client Secret',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Client Secret OAuth Google. Hanya digunakan backend.',
                    ],
                ],
            ],
            'telegram' => [
                'name' => 'Notifikasi Telegram',
                'group' => 'Notifikasi',
                'description' => 'Pengiriman notifikasi operasional Admin melalui bot Telegram.',
                'fields' => [
                    'bot_token' => [
                        'label' => 'Token bot',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Token bot Telegram.',
                    ],
                    'chat_id' => [
                        'label' => 'ID chat',
                        'secret' => true,
                        'required' => true,
                        'help' => 'ID chat tujuan notifikasi.',
                    ],
                ],
            ],
            'discord' => [
                'name' => 'Notifikasi Discord',
                'group' => 'Notifikasi',
                'description' => 'Pengiriman notifikasi operasional Admin melalui webhook Discord.',
                'fields' => [
                    'webhook_url' => [
                        'label' => 'Alamat webhook',
                        'secret' => true,
                        'required' => true,
                        'help' => 'URL webhook Discord. Nilai disimpan terenkripsi.',
                    ],
                ],
            ],
            'turnstile' => [
                'name' => 'Cloudflare Turnstile',
                'group' => 'Keamanan',
                'description' => 'Proteksi bot untuk form publik dan login yang terdeteksi mencurigakan.',
                'fields' => [
                    'site_key' => [
                        'label' => 'Site Key',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Kunci publik widget Turnstile.',
                    ],
                    'secret_key' => [
                        'label' => 'Secret Key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci verifikasi server Turnstile.',
                    ],
                    'allowed_hostnames' => [
                        'label' => 'Hostname yang diizinkan',
                        'secret' => false,
                        'required' => false,
                        'type' => 'csv',
                        'help' => 'Pisahkan beberapa hostname dengan koma. Jangan sertakan http:// atau https://.',
                    ],
                ],
                'note' => 'Turnstile digunakan pada alur publik yang dilindungi sesuai konfigurasi keamanan aplikasi.',
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

    /**
     * @return array<int, string>
     */
    public function requiredFields(string $code): array
    {
        $definition = $this->get($code);
        if (! $definition) {
            return [];
        }

        return collect($definition['fields'])
            ->filter(fn (array $field): bool => (bool) ($field['required'] ?? false))
            ->keys()->values()->all();
    }
}
