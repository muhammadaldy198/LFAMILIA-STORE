# Arsitektur LFAMILIA STORE

> Aturan produk ada di [`docs/PRD.md`](../docs/PRD.md). Dokumen ini menjelaskan **cara kode bekerja**, bukan aturan produk. Kalau berbeda, PRD yang menang.

## Alur permintaan

**Pelanggan:** Browser → Cloudflare → Nginx → Laravel/Inertia → MySQL/Redis.

Semua logika bisnis hidup di Laravel. Vue/Inertia hanya tampilan pelanggan. MySQL menyimpan data bisnis; Redis dipakai untuk cache, antrean, dan session.

**Admin:** Browser → Cloudflare Access → `/admin` → Laravel Admin.

Cloudflare Access hanya lapisan tambahan di depan. Hak akses yang sebenarnya selalu diperiksa Laravel di server — menyembunyikan menu bukan pengaman.

**Panel server (CloudPanel):** Browser → Cloudflare Access → panel.lfamilia.com (Tunnel → localhost:8443) → CloudPanel. Domain utama lfamilia.com berjalan lewat Tunnel-nya sendiri; asal 443 dan panel 8443 memakai sertifikat Cloudflare Origin CA dengan verifikasi TLS.

## Alur transaksi

Pelanggan → Checkout → Pesanan → Rute pembayaran → Transaksi pembayaran → callback/rekonsiliasi → Pengiriman → mapping provider.

- Checkout menghitung ulang semua angka di server dan menyimpan snapshot pesanan. Harga katalog yang berubah sesudahnya tidak mengubah pesanan yang sudah dibuat.
- Pelanggan memilih **channel/metode pembayaran**, bukan gateway internal. Rute pembayaran menentukan gateway yang boleh dipakai sebelum permintaan keluar dibuat.
- Sesudah permintaan keluar dibuat, status yang belum pasti **tidak** ditebak dan **tidak** diganti gateway begitu saja. Transaksi masuk status `UNKNOWN` dan diselesaikan lewat rekonsiliasi atau callback tervalidasi dari gateway itu sendiri.

## Rute pembayaran

`PaymentRoutingService` memilih rute yang memenuhi semua syarat ini:

- channel aktif;
- gateway dan rute aktif;
- gateway tidak sedang maintenance;
- mendukung keperluan pesanan atau top-up wallet;
- kredensialnya siap, untuk gateway eksternal yang membutuhkannya.

Rute diurutkan dari angka `priority` terkecil, lalu ID. Priority 10 dipilih sebelum 20 bila keduanya lolos.

Memilih rute **sebelum** permintaan dibuat berbeda dengan memulihkan transaksi **sesudah** permintaan dibuat. `PaymentService` tidak melakukan failover universal sesudah permintaan eksternal yang statusnya belum pasti — transaksi seperti itu direkonsiliasi, bukan diduplikasi.

## Pengaman pesanan dan pembayaran

- Kunci idempotensi + sidik jari (fingerprint) untuk checkout dan untuk pembuatan pembayaran.
- Penguncian baris (row locking) pada transaksi kritis.
- Hanya satu pembayaran aktif yang tidak ditolak per pesanan atau top-up.
- Perubahan status pembayaran terpusat di satu tempat (`PaymentStateService`).
- Callback tervalidasi dan terdeduplikasi; status akhir (terminal) tidak boleh diturunkan oleh callback lama.
- Debit/kredit wallet memakai transaksi database, lock, ledger, dan idempotensi.
- Pesanan menyimpan snapshot biaya, margin, diskon, fee, total, paket, mapping provider, dan rute pembayaran.
- Peristiwa penting pesanan dicatat di order events/audit untuk penelusuran.

## Pengiriman (fulfillment)

Satu nominal hanya boleh punya **satu SKU Digiflazz aktif terpilih**. Tidak ada SKU cadangan yang dipilih otomatis.

Calon sumber dipilih sebelum permintaan dikirim, berurutan dari priority terkecil, lalu biaya, lalu ID. Mapping nonaktif, provider nonaktif, SKU yang tidak layak pakai, stok/cut-off bermasalah, adapter yang tidak didukung, atau biaya di atas `max_price` dilewati pada tahap ini.

Sesudah permintaan terkirim:

- Status `PENDING`, `SENDING`, atau `UNKNOWN` tidak memicu pengiriman ke sumber lain; rekonsiliasi memakai attempt yang sama.
- **Pengalihan otomatis (failover) dimatikan (PRD §47).** Kegagalan definitif (`FAILED_CONFIRMED`) menahan pesanan di status `BLOCKED` untuk ditangani manual oleh admin/CS — bukan dikirim ulang ke sumber cadangan.
- Attempt dan mapping yang sudah dipakai tidak boleh diduplikasi.
- Pelanggan selalu melihat satu nominal/pesanan, bukan daftar provider internal.

Adapter otomatis yang ada hanya Digiflazz dan stok kode internal (voucher lokal). Pesanan bermasalah ditangani manual lewat panel Admin.

## Batas login

Login pelanggan dan login admin terpisah.

- **Pelanggan:** session web Fortify — daftar, login, logout, lupa/reset kata sandi, Google OAuth lewat Socialite bila integrasinya aktif, profil/akun.
- **Admin:** guard dan tabel terpisah, peran `SUPER_ADMIN` atau `ADMIN`. `ADMIN` memakai izin granular yang diperiksa di server; `SUPER_ADMIN` berakses penuh termasuk operasi khusus super.
- Tingkat membership pelanggan (BASIC → SILVER → GOLD → DIAMOND → PLATINUM → MAFIA) bukan peran admin dan tidak memberi hak panel apa pun.
- OTP WhatsApp tidak digunakan.

## Batas secret

- **Di repo:** kode sumber, skema, migrasi, pemetaan protokol, validasi, logika bisnis.
- **Di ENV server:** konfigurasi infrastruktur seperti APP_KEY, koneksi DB/Redis, path deploy, nama service, lokasi berkas backup.
- **Di panel Super Admin → Integrasi:** kredensial provider dan aplikasi yang dikelola aplikasi, tersimpan terenkripsi di `integration_credentials.config_ciphertext` (encrypted array cast).

Secret tidak boleh di-hardcode, masuk fixture produksi, masuk log, masuk Markdown, atau ter-commit sebagai `.env`.

## Integrasi yang didukung kode dan panel

Digiflazz, validasi nickname (mesin H2H lewat `NicknameService`), Midtrans, DOKU, Resend, Google OAuth, Telegram, Discord, dan Turnstile.

Adanya adapter atau halaman panel **bukan bukti** kredensial produksi lengkap atau transaksi live sudah lolos. Penerimaan live oleh provider adalah tahap terpisah.
