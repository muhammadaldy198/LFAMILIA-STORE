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
                'group' => 'Pemasok produk',
                'description' => 'Kredensial transaksi dan sinkronisasi produk Digiflazz.',
                'default_environment' => 'production',
                'credential_scope' => 'per_environment',
                'environments' => $this->availableEnvironments('test', [
                    'label' => 'Sungguhan — transaksi nyata',
                    'live' => true,
                    'description' => 'Request tanpa parameter testing; transaksi dapat diproses nyata oleh provider.',
                ]),
                'fields' => [
                    'username' => [
                        'label' => 'Nama pengguna Digiflazz',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Nama pengguna akun pembeli Digiflazz yang dipakai sistem.',
                    ],
                    'api_key' => [
                        'label' => 'Kunci akses',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci akses Digiflazz Production.',
                    ],
                    'webhook_secret' => [
                        'label' => 'Rahasia webhook',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Dipakai agar pemberitahuan dari Digiflazz dapat dipastikan asli.',
                    ],
                ],
                'callback' => [
                    'label' => 'Pemberitahuan Digiflazz',
                    'route' => 'api.fulfillment.digiflazz.webhook',
                    'required_fields' => ['webhook_secret'],
                ],
                'note' => 'Khusus transaksi Digiflazz Production menggunakan API key yang aktif.',
            ],
            'kokinpay' => [
                'name' => 'Validasi Akun',
                'group' => 'Pengecekan akun',
                'description' => 'API pengecekan nickname, region MLBB, dan pelanggan PLN.',
                'environment_note' => 'Integrasi hanya memakai endpoint layanan yang aktif.',
                'fields' => [
                    'api_key' => [
                        'label' => 'Kunci akses',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci akses layanan validasi akun.',
                    ],
                    'base_url' => [
                        'label' => 'Alamat layanan',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Alamat dasar API validasi. Wajib HTTPS.',
                    ],
                    'nickname_path' => [
                        'label' => 'Alamat cek nama panggilan',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Path endpoint cek nickname, misalnya /api/check-nickname.',
                    ],
                    'region_path' => [
                        'label' => 'Alamat cek server MLBB',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Path endpoint cek region MLBB.',
                    ],
                    'pln_path' => [
                        'label' => 'Alamat cek pelanggan PLN',
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
                'default_environment' => 'production',
                'credential_scope' => 'per_environment',
                'environments' => $this->availableEnvironments('sandbox', [
                    'label' => 'Sungguhan — transaksi nyata',
                    'live' => true,
                    'description' => 'Menggunakan endpoint dan credential Production Midtrans.',
                ]),
                'fields' => [
                    'server_key' => [
                        'label' => 'Kunci server',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci server Midtrans Production, hanya digunakan backend.',
                    ],
                    'client_key' => [
                        'label' => 'Kunci aplikasi',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Kunci aplikasi Midtrans Production untuk flow Snap.',
                    ],
                ],
                'callback' => [
                    'label' => 'Pemberitahuan Midtrans',
                    'route' => 'api.payments.midtrans.notification',
                    'required_fields' => ['server_key'],
                ],
            ],
            'doku' => [
                'name' => 'DOKU Checkout',
                'group' => 'Pembayaran',
                'description' => 'Kredensial DOKU Checkout untuk halaman pembayaran yang dihosting DOKU.',
                'default_environment' => 'production',
                'credential_scope' => 'per_environment',
                'environments' => $this->availableEnvironments('sandbox', [
                    'label' => 'Sungguhan — transaksi nyata',
                    'live' => true,
                    'description' => 'Menggunakan endpoint dan credential Production DOKU.',
                ]),
                'fields' => [
                    'client_id' => [
                        'label' => 'ID aplikasi',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Identitas merchant DOKU Production.',
                    ],
                    'secret_key' => [
                        'label' => 'Kunci rahasia',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci penandatanganan request DOKU Production.',
                    ],
                ],
                'callback' => [
                    'label' => 'Pemberitahuan DOKU',
                    'route' => 'api.payments.doku.notification',
                    'required_fields' => ['client_id', 'secret_key'],
                ],
            ],
            'resend' => [
                'name' => 'Resend Email',
                'group' => 'Email',
                'description' => 'Pengiriman email transaksi dan notifikasi operasional.',
                'environment_note' => 'Resend memakai kredensial layanan aktif.',
                'fields' => [
                    'api_key' => [
                        'label' => 'Kunci akses',
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
                'environment_note' => 'Status Testing/Production OAuth dikelola di Google Cloud, bukan dengan endpoint backend terpisah. Karena itu panel tidak membuat selector environment palsu.',
                'fields' => [
                    'client_id' => [
                        'label' => 'ID aplikasi',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Client ID aplikasi OAuth Google.',
                    ],
                    'client_secret' => [
                        'label' => 'Sandi aplikasi',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Client Secret OAuth Google. Hanya digunakan backend.',
                    ],
                ],
                'callback' => [
                    'label' => 'Alamat kembali Google',
                    'route' => 'google.callback',
                    'required_fields' => ['client_id', 'client_secret'],
                ],
            ],
            'telegram' => [
                'name' => 'Notifikasi Telegram',
                'group' => 'Pemberitahuan',
                'description' => 'Empat bot berbeda di satu grup admin: Order, Tugas, Sistem, dan Arsip.',
                'default_environment' => 'production',
                'credential_scope' => 'per_environment',
                'environments' => $this->availableEnvironments('test', [
                    'label' => 'Sungguhan — data nyata',
                    'live' => true,
                    'description' => 'Menggunakan bot/token Telegram Production untuk notifikasi nyata.',
                ]),
                'fields' => [
                    'bot_token' => [
                        'label' => 'Token bot default (cadangan)',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Opsional. Dipakai hanya bila token bot untuk tujuan tertentu belum diisi.',
                    ],
                    'bot_token_order' => [
                        'label' => 'Token bot Order',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Bot pengirim khusus informasi transaksi dan pesanan.',
                    ],
                    'bot_token_tugas' => [
                        'label' => 'Token bot Tugas',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Bot pengirim khusus pekerjaan yang memerlukan tindakan admin.',
                    ],
                    'bot_token_sistem' => [
                        'label' => 'Token bot Sistem',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Bot pengirim khusus gangguan mesin, integrasi, dan kesehatan layanan.',
                    ],
                    'bot_token_arsip' => [
                        'label' => 'Token bot Arsip',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Bot pengirim khusus tiket, keamanan, dan catatan lainnya.',
                    ],
                    'chat_id' => [
                        'label' => 'ID grup admin Telegram',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Satu chat ID grup yang dipakai bersama oleh keempat bot.',
                    ],
                    'thread_order' => [
                        'label' => 'ID topik Order',
                        'secret' => false,
                        'required' => false,
                        'help' => 'Opsional. ID topik untuk informasi transaksi.',
                    ],
                    'thread_tugas' => [
                        'label' => 'ID topik Tugas',
                        'secret' => false,
                        'required' => false,
                        'help' => 'Opsional. ID topik untuk pesanan yang harus ditangani admin.',
                    ],
                    'thread_sistem' => [
                        'label' => 'ID topik Sistem',
                        'secret' => false,
                        'required' => false,
                        'help' => 'Opsional. ID topik untuk kesehatan situs, integrasi, dan sinkronisasi.',
                    ],
                    'thread_arsip' => [
                        'label' => 'ID topik Arsip',
                        'secret' => false,
                        'required' => false,
                        'help' => 'Opsional. ID topik untuk tiket dan catatan lainnya.',
                    ],
                ],
                'note' => 'Setiap tujuan memakai bot dan topiknya sendiri dalam satu grup. Token tujuan kosong memakai token default; tanpa keduanya tujuan dilewati. Thread kosong mengirim ke grup tanpa ID topik.',
            ],
            'discord' => [
                'name' => 'Notifikasi Discord',
                'group' => 'Pemberitahuan',
                'description' => 'Discord embed admin untuk tujuan Order, Tugas, Sistem, dan Arsip.',
                'environment_note' => 'Webhook Discord memakai alamat webhook aktif.',
                'fields' => [
                    'webhook_url' => [
                        'label' => 'Webhook default',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Opsional. Tujuan cadangan jika webhook suatu kelompok tidak tersedia.',
                    ],
                    'webhook_url_order' => [
                        'label' => 'Webhook Order',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Info transaksi dan pesanan. Kosong = gunakan default.',
                    ],
                    'webhook_url_tugas' => [
                        'label' => 'Webhook Tugas',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Pesanan yang butuh tindakan admin. Kosong = gunakan default.',
                    ],
                    'webhook_url_sistem' => [
                        'label' => 'Webhook Sistem',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Gangguan layanan dan mesin. Kosong = gunakan default.',
                    ],
                    'webhook_url_arsip' => [
                        'label' => 'Webhook Arsip',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Tiket, keamanan, dan catatan lain. Kosong = gunakan default.',
                    ],
                ],
                'note' => 'Webhook tujuan yang kosong memakai webhook default. Webhook tujuan lama tidak digunakan lagi.',
            ],
            'turnstile' => [
                'name' => 'Cloudflare Turnstile',
                'group' => 'Keamanan',
                'description' => 'Proteksi bot untuk form publik dan login yang terdeteksi mencurigakan.',
                'default_environment' => 'production',
                'credential_scope' => 'per_environment',
                'environments' => $this->availableEnvironments('test', [
                    'label' => 'Sungguhan — data nyata',
                    'live' => true,
                    'description' => 'Gunakan widget dan credential Turnstile milik hostname produksi.',
                ]),
                'fields' => [
                    'site_key' => [
                        'label' => 'Site Key',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Kunci publik widget Turnstile Production.',
                    ],
                    'secret_key' => [
                        'label' => 'Kunci rahasia',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci verifikasi server Turnstile Production.',
                    ],
                    'allowed_hostnames' => [
                        'label' => 'Hostname yang diizinkan',
                        'secret' => false,
                        'required' => false,
                        'type' => 'csv',
                        'help' => 'Untuk website LFAMILIA, gunakan lfamilia.com. Pisahkan hostname dengan koma, tanpa http:// atau https://.',
                    ],
                ],
                'note' => 'Turnstile hanya memakai kredensial Production.',
            ],
        ];
    }

    /**
     * Production is the only selectable profile in the real application.
     * Legacy fixture modes remain accessible to the isolated CI test process.
     *
     * @param  array<string, mixed>  $production
     * @return array<string, array<string, mixed>>
     */
    private function availableEnvironments(string $fixtureCode, array $production): array
    {
        if (app()->environment('testing')) {
            return [
                $fixtureCode => [
                    'label' => 'Masa uji',
                    'live' => false,
                    'description' => 'Only used by isolated automated tests.',
                ],
                'production' => $production,
            ];
        }

        return ['production' => $production];
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

    /**
     * @return array<string,array<string,mixed>>
     */
    public function environments(string $code): array
    {
        $environments = $this->get($code)['environments'] ?? [];

        return is_array($environments) ? $environments : [];
    }

    public function credentialsArePerEnvironment(string $code): bool
    {
        return ($this->get($code)['credential_scope'] ?? 'shared') === 'per_environment';
    }
}
