# Kebijakan Keamanan

Dokumen ini menjelaskan cara proyek LFAMILIA-STORE menangani keamanan dan cara melaporkan kerentanan. Isinya juga menjadi acuan reviewer keamanan (termasuk reviewer otomatis) agar review menyasar hal yang benar. Semua jalur berkas aplikasi di bawah relatif terhadap folder `rebuild/` (aplikasi Laravel); rujukan tingkat repo ditulis dari root repo. Rincian teknis tambahan ada di [`rebuild/SECURITY.md`](rebuild/SECURITY.md).

## 1. Melaporkan Kerentanan

Jalur resmi pelaporan adalah **GitHub Private Vulnerability Reporting** di repo ini (tab **Security** > **Report a vulnerability**; pengaturannya ada di **Settings > Security**).

Jalur Private Vulnerability Reporting diaktifkan oleh pemilik repo di Settings > Security; bila tombol Report a vulnerability belum muncul di tab Security, fitur itu belum aktif. Formulir dukungan di situs toko hanya melayani pelanggan yang memiliki pesanan, jadi bukan jalur untuk laporan keamanan. Tetap jangan membuka issue publik untuk kerentanan.

- Jangan membuka issue publik, pull request publik, atau diskusi publik untuk kerentanan.
- Sertakan langkah reproduksi, dampak, dan jalur berkas yang terkait bila diketahui.
- Jangan menyertakan nilai rahasia sungguhan (kunci, token, kata sandi) di laporan.

Komitmen respons: laporan dibaca oleh pemilik repo. Tidak ada janji waktu respons atau SLA.

## 2. Ruang Lingkup

Aplikasi web LFAMILIA-STORE (Laravel 12 + Vue/Inertia), mencakup:

- pembayaran (Midtrans, DOKU);
- pemenuhan pesanan (Digiflazz);
- panel admin;
- checkout tamu.

## 3. Mekanisme Keamanan yang Berjalan

**a. Webhook Midtrans.** Tanda tangan SHA512 atas `order_id` + `status_code` + `gross_amount` + kunci dihitung ulang dan dibandingkan dengan `hash_equals`. Kunci yang dicoba adalah `signature_secret_key` lalu `server_key`, keduanya dibaca dari kredensial terenkripsi. Sesudah itu nominal dicocokkan dengan transaksi lokal dan status diverifikasi ulang ke Midtrans sebelum state berubah. (`app/Http/Controllers/PaymentWebhookController.php`)

**b. Webhook Digiflazz.** Header `X-Hub-Signature` diverifikasi dengan HMAC (`sha1=` + `hash_hmac('sha1', ...)`) dan `hash_equals`, memakai `webhook_secret` dari kredensial terenkripsi. `User-Agent` wajib `Digiflazz-Hookshot`, header `X-Digiflazz-Event` hanya boleh `create` atau `update`. Pemrosesan idempoten lewat tabel `fulfillment_callbacks` (satu event per hash event+payload) dan hanya untuk `ref_id` yang punya percobaan fulfillment lokal. (`app/Http/Controllers/FulfillmentWebhookController.php`) Lapisan jaringan tambahan: Cloudflare WAF membatasi webhook Digiflazz ke IP yang ditentukan di aturan (lihat `rebuild/deploy/CLOUDFLARE.md`); validasi signature di Laravel tetap wajib.

**c. Kredensial integrasi.** Disimpan di tabel `integration_credentials`, kolom `config_ciphertext`, dengan cast `encrypted:array` (enkripsi Laravel berbasis `APP_KEY`); kolom itu juga `hidden` pada model. Integrasi yang kredensialnya dikelola lewat tabel ini, sesuai daftar di IntegrationRegistry: Midtrans, DOKU, Digiflazz, Resend, Google OAuth, Telegram, Discord, dan Turnstile. Panel admin tidak menampilkan nilai kolom bertanda rahasia (nilai dikirim sebagai `null`). (`app/Models/IntegrationCredential.php`, `app/Http/Controllers/AdminIntegrationController.php`)

**d. Tautan bertanda tangan wajib kedaluwarsa.** Tautan status/pembayaran dibuat dengan `URL::temporarySignedRoute`. Tautan pesanan memakai `expires_at` pesanan, tautan dari template pesan admin berlaku 20 menit, dan tautan status bertanda tangan memakai `expires_at` bila masih di masa depan, selain itu `now()+30 menit`. Tautan bertanda tangan tanpa expiry tidak boleh dibuat. (`app/Http/Controllers/GuestOrderController.php`, `app/Http/Controllers/PaymentPageController.php`, `app/Http/Controllers/CustomerOrderController.php`, `app/Http/Controllers/AdminMessageTemplatesController.php`)

**e. Gerbang admin.** Guard `admin` terpisah dari guard pelanggan (`config/auth.php`). Middleware `EnsureAdminRole` mengizinkan admin aktif dengan peran `SUPER_ADMIN` atau `ADMIN`; `EnsureSuperAdmin` hanya `SUPER_ADMIN` aktif. Penolakan berupa HTTP 403. (`app/Http/Middleware/EnsureAdminRole.php`, `app/Http/Middleware/EnsureSuperAdmin.php`)

**f. Penjaga boot produksi.** Di lingkungan `production`, aplikasi melempar exception (menolak berjalan) bila `SESSION_ENCRYPT` tidak aktif, cookie sesi tidak secure, `APP_TRUSTED_HOSTS` kosong, `TRUSTED_PROXIES` kosong, berisi wildcard `*`, atau berisi entri yang bukan IP/CIDR valid, bila `APP_DEBUG` aktif, atau bila `APP_URL` bukan HTTPS. (`app/Providers/AppServiceProvider.php`)

**g. Redaksi log audit admin.** Daftar kolom yang disensor mencakup `access_token`, `private_key`, `bearer_token`, `webhook_secret`, dan lainnya. (`app/Services/AdminAuditService.php`)

**h. Data tamu.** Checkout tamu mewajibkan `guest_email` dan `guest_phone` (`app/Http/Controllers/CheckoutController.php`). Pesanan tamu dibuka lewat tautan bertanda tangan yang kedaluwarsa (butir d) atau lewat nomor pesanan ditambah kode akses yang dicocokkan di server, lalu disimpan di sesi. Nomor pesanan saja tidak cukup. (`app/Http/Controllers/GuestOrderController.php`)

## 4. Keputusan Keamanan yang Disadari

Hal berikut sudah diputuskan pemilik secara sadar. Jangan diangkat lagi sebagai temuan atau celah di review.

- Formulir dukungan publik memakai rate limiter 5 per menit tanpa Turnstile (`support-ticket` di `app/Providers/AppServiceProvider.php`). Keputusan pemilik, 9 Nov 2026.
- Toko tidak melayani refund. Ini kebijakan bisnis, bukan celah keamanan (lihat `docs/PRD.md`). Teks untuk pelanggan tidak boleh menjanjikan refund.
- Verifikasi Midtrans memakai Server Key dan signature key tersimpan (hasil PR #389). Jangan diusulkan diganti diam-diam.

## 5. Panduan Review Keamanan

Periksa terutama:

- perubahan di controller webhook dan pembayaran: tanda tangan tidak boleh dilemahkan atau dilewati;
- pemakaian signed route: wajib ada expiry;
- query mentah dan input yang tidak divalidasi;
- unggahan berkas;
- perubahan middleware admin;
- kebocoran nilai kredensial ke log atau respons.

Tulis temuan dengan jalur berkas dan nomor baris.

## 6. Di Luar Ruang Lingkup

Keamanan infrastruktur server (VPS) dan akun pihak ketiga (Midtrans, Digiflazz, Cloudflare) dikelola di luar repo ini dan tidak didokumentasikan di sini.
