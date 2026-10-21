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
                'default_environment' => 'test',
                'credential_scope' => 'per_environment',
                'environments' => [
                    'test' => [
                        'label' => 'Masa uji',
                        'live' => false,
                        'description' => 'Menggunakan parameter testing resmi Digiflazz pada endpoint API yang sama.',
                    ],
                    'production' => [
                        'label' => 'Sungguhan — transaksi nyata',
                        'live' => true,
                        'description' => 'Request tanpa parameter testing; transaksi dapat diproses nyata oleh provider.',
                    ],
                ],
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
                        'help' => 'Kunci akses untuk mode layanan yang sedang dipilih. Mode uji dan mode sungguhan disimpan terpisah.',
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
                'note' => 'Digiflazz memakai endpoint API yang sama, tetapi Development dan Production menggunakan API key terpisah. Mode Development/Test juga menerapkan parameter testing resmi pada request transaksi.',
            ],
            'kokinpay' => [
                'name' => 'Validasi Akun',
                'group' => 'Pengecekan akun',
                'description' => 'API pengecekan nickname, region MLBB, dan pelanggan PLN.',
                'environment_note' => 'Environment terpisah tidak diaktifkan karena capability sandbox/test resmi untuk API yang digunakan belum terverifikasi.',
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
                'default_environment' => 'sandbox',
                'credential_scope' => 'per_environment',
                'environments' => [
                    'sandbox' => [
                        'label' => 'Masa uji',
                        'live' => false,
                        'description' => 'Menggunakan endpoint dan credential Sandbox Midtrans.',
                    ],
                    'production' => [
                        'label' => 'Sungguhan — transaksi nyata',
                        'live' => true,
                        'description' => 'Menggunakan endpoint dan credential Production Midtrans.',
                    ],
                ],
                'fields' => [
                    'server_key' => [
                        'label' => 'Kunci server',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci server untuk environment yang sedang dipilih. Hanya digunakan backend.',
                    ],
                    'client_key' => [
                        'label' => 'Kunci aplikasi',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Kunci client untuk environment yang sedang dipilih bila dibutuhkan oleh flow Snap.',
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
                'default_environment' => 'sandbox',
                'credential_scope' => 'per_environment',
                'environments' => [
                    'sandbox' => [
                        'label' => 'Masa uji',
                        'live' => false,
                        'description' => 'Menggunakan endpoint dan credential Sandbox DOKU.',
                    ],
                    'production' => [
                        'label' => 'Sungguhan — transaksi nyata',
                        'live' => true,
                        'description' => 'Menggunakan endpoint dan credential Production DOKU.',
                    ],
                ],
                'fields' => [
                    'client_id' => [
                        'label' => 'ID aplikasi',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Identitas merchant untuk environment yang sedang dipilih.',
                    ],
                    'secret_key' => [
                        'label' => 'Kunci rahasia',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci penandatanganan request untuk environment yang sedang dipilih.',
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
                'environment_note' => 'Resend tidak memakai endpoint Sandbox/Production terpisah pada integrasi ini. Pisahkan domain/team atau credential bila diperlukan secara operasional.',
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
                'description' => 'Pengiriman notifikasi operasional Admin melalui bot Telegram.',
                'default_environment' => 'test',
                'credential_scope' => 'per_environment',
                'environments' => [
                    'test' => [
                        'label' => 'Masa uji',
                        'live' => false,
                        'description' => 'Menggunakan bot/token Telegram Test Environment dan path Bot API /test/.',
                    ],
                    'production' => [
                        'label' => 'Sungguhan — data nyata',
                        'live' => true,
                        'description' => 'Menggunakan bot/token Telegram Production untuk notifikasi nyata.',
                    ],
                ],
                'fields' => [
                    'bot_token' => [
                        'label' => 'Token bot Telegram',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Token bot untuk environment yang sedang dipilih.',
                    ],
                    'chat_id' => [
                        'label' => 'ID ruang obrolan',
                        'secret' => true,
                        'required' => true,
                        'help' => 'ID chat tujuan pada environment yang sedang dipilih.',
                    ],
                ],
                'note' => 'Telegram Test Environment terpisah dari Production sehingga bot, token, user, dan chat tidak boleh saling fallback.',
            ],
            'discord' => [
                'name' => 'Notifikasi Discord',
                'group' => 'Pemberitahuan',
                'description' => 'Pengiriman notifikasi operasional Admin melalui webhook Discord.',
                'environment_note' => 'Webhook Discord yang digunakan tidak mempunyai selector Sandbox/Production terpisah.',
                'fields' => [
                    'webhook_url' => [
                        'label' => 'Alamat pemberitahuan utama',
                        'secret' => true,
                        'required' => true,
                        'help' => 'URL webhook Discord. Dipakai untuk semua channel yang webhook per-channel-nya kosong. Nilai disimpan terenkripsi.',
                    ],
                    'webhook_url_order' => [
                        'label' => 'Alamat pemberitahuan ORDER',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Order baru, bayar terlambat, laporan refund. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_qris' => [
                        'label' => 'Alamat pemberitahuan QRIS',
                        'secret' => true,
                        'required' => false,
                        'help' => 'QRIS manual menunggu verifikasi. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_fulfillment' => [
                        'label' => 'Alamat pemberitahuan FULFILMENT',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Update fulfillment rutin. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_tiket' => [
                        'label' => 'Alamat pemberitahuan TIKET',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Tiket support. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_blocked' => [
                        'label' => 'Alamat pemberitahuan BLOCKED',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Pengiriman tertahan / habis / reconcile mentok. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_login' => [
                        'label' => 'Alamat pemberitahuan LOGIN MENCURIGAKAN',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Login admin mencurigakan. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_urgent' => [
                        'label' => 'Alamat pemberitahuan URGENT+MAPPING',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Harga penyedia jebol + mapping aktif ganda. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_vps' => [
                        'label' => 'Alamat pemberitahuan VPS',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Website mati / healthcheck. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_integrasi' => [
                        'label' => 'Alamat pemberitahuan INTEGRASI',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Integrasi bermasalah. Kosong = pakai webhook default.',
                    ],
                    'webhook_url_sync' => [
                        'label' => 'Alamat pemberitahuan SYNC',
                        'secret' => true,
                        'required' => false,
                        'help' => 'Sync Digiflazz gagal. Kosong = pakai webhook default.',
                    ],
                ],
            ],
            'turnstile' => [
                'name' => 'Cloudflare Turnstile',
                'group' => 'Keamanan',
                'description' => 'Proteksi bot untuk form publik dan login yang terdeteksi mencurigakan.',
                'default_environment' => 'test',
                'credential_scope' => 'per_environment',
                'environments' => [
                    'test' => [
                        'label' => 'Masa uji',
                        'live' => false,
                        'description' => 'Gunakan site key dan secret key pengujian Turnstile.',
                    ],
                    'production' => [
                        'label' => 'Sungguhan — data nyata',
                        'live' => true,
                        'description' => 'Gunakan widget dan credential Turnstile milik hostname produksi.',
                    ],
                ],
                'fields' => [
                    'site_key' => [
                        'label' => 'Site Key',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Kunci publik widget Turnstile untuk environment yang sedang dipilih.',
                    ],
                    'secret_key' => [
                        'label' => 'Kunci rahasia',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Kunci verifikasi server Turnstile untuk environment yang sedang dipilih.',
                    ],
                    'allowed_hostnames' => [
                        'label' => 'Hostname yang diizinkan',
                        'secret' => false,
                        'required' => false,
                        'type' => 'csv',
                        'help' => 'Pisahkan beberapa hostname dengan koma. Jangan sertakan http:// atau https://.',
                    ],
                ],
                'note' => 'Test dan Production memakai credential terpisah. Backend hanya membaca profile dari environment yang dipilih.',
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
