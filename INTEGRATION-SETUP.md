# Konfigurasi Integrasi LFAMILIA STORE

Dokumen ini dipakai setelah source berhasil diunggah ke GitHub dan Worker dapat dibangun. Mulai seluruh integrasi dalam mode sandbox/development.

Konfigurasi operasional provider tidak disimpan di GitHub dan dikelola dari Admin Panel melalui Integration Manager terenkripsi di D1. `INTEGRATION_ENCRYPTION_KEY` tetap menjadi root secret di Cloudflare. Konfigurasi infrastruktur Worker yang bukan credential provider tetap mengikuti `wrangler.jsonc`/Cloudflare sesuai kebutuhan.

## 1. Database D1

Binding yang dipakai aplikasi adalah `DB`, nama database `lfamilia-store-db`, dan folder migrasi `drizzle`.

Jalankan migrasi production satu kali:

```bash
npx wrangler d1 migrations apply lfamilia-store-db --remote
```

Jangan menghapus migrasi lama yang sudah pernah diterapkan. Setelah migrasi berhasil, masuk ke panel dengan ID admin + password, lalu tekan **Lengkapi katalog utama** pada tab Produk. Tindakan ini menambahkan produk/nominal yang belum ada tanpa menimpa perubahan yang sudah tersimpan.

## 2. Credential dan environment provider

Gunakan **Admin Panel → Integrasi & harga → Kredensial API & callback** untuk menyimpan credential Sandbox/Development dan Production secara terpisah. Gunakan selector di bagian **Mode yang dipakai toko** untuk menentukan environment aktif.

Jangan membuat credential provider atau `PROVIDER_RELAY_*` secara manual di Cloudflare. Integration Manager mengenkripsi konfigurasi ke D1 lalu Worker menghidrasinya ke nama runtime internal saat request berjalan.

Cloudflare hanya wajib menyimpan root secret integrasi:

```text
INTEGRATION_ENCRYPTION_KEY
```

Jangan mengganti root secret tersebut setelah credential terenkripsi tersimpan.

## 3. Callback dan webhook

Gunakan domain publik yang sama dengan `PUBLIC_BASE_URL`:

| Layanan | URL |
|---|---|
| Midtrans notification | `https://lfamiliastore.my.id/api/payments/midtrans/callback` |
| DigiFlazz webhook | `https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback` |
| VIPayment webhook | `https://lfamiliastore.my.id/api/fulfillment/vippayment/callback` |

Aplikasi memeriksa callback pembayaran sebelum mengubah status. Callback yang sudah pernah diproses tidak akan mengirim produk untuk kedua kali.

## 4. Produk, nominal, dan provider

Di panel buka tab **Produk**.

- Daftar nominal tetap berbentuk tabel ringkas.
- Setiap nominal memiliki **Provider**, **SKU**, **Margin**, **Harga jual**, **Status**, dan tombol **Sync**.
- Untuk DigiFlazz, pilih provider DigiFlazz, isi SKU persis seperti price list, pilih margin Rupiah/Persen, simpan nominal, lalu gunakan **Sync** pada nominal tersebut.
- Produk otomatis diproses melalui provider setelah pembayaran terverifikasi.
- Produk manual dipilih melalui **Jenis proses → Manual oleh admin**, kemudian isi instruksi dan jam operasional. Setelah lunas, pesanan masuk antrean admin.
- Produk kategori voucher tidak meminta data akun pada checkout dan kode hanya tersedia setelah pembayaran lunas.
- `target_template` mendukung `{{destination}}` dan `{{server}}`.

Verifikasi provider, SKU, margin, harga, jam operasional, dan instruksi sebelum menerima pembayaran.

## 5. Keamanan admin

Panel operasional menggunakan ID admin + password dengan sesi terpisah dari akun pelanggan.

- Pemilik dapat membuat/menonaktifkan akun Staff dan mengganti password dari tab **Tim admin**.
- Password disimpan sebagai hash dan tidak pernah ditampilkan kembali.
- Endpoint perubahan admin memeriksa sesi dan origin request.
- Area Admin `/admin/panel*` dan halaman pemulihan Pemilik `/admin/setup*` harus dilindungi Cloudflare Access.
- Tambahkan Cloudflare Worker Variable `TEAM_DOMAIN=https://<nama-team>.cloudflareaccess.com`.
- Tambahkan Cloudflare Worker Variable `POLICY_AUD=<Application Audience AUD>` dari aplikasi Access yang melindungi Admin.
- Worker memvalidasi signature RS256, issuer, audience, masa berlaku, dan email pada JWT Access. Request Admin ditolak jika salah satu variable tersebut kosong atau token tidak valid.
- `OWNER_EMAIL` digunakan untuk jalur pemulihan Pemilik, bukan sebagai password/login operasional.
- Jangan mengekspos secret provider atau gateway ke browser.

## 6. Stok kode REDFINGER atau lisensi

1. Simpan **Voucher Encryption Key** dari **Integrasi & harga → Kredensial API & callback → Security** terlebih dahulu. Runtime internal membentuk `VOUCHER_ENCRYPTION_KEY` dari konfigurasi terenkripsi tersebut. Setelah kode diimpor, nilainya tidak boleh diganti atau kode lama tidak dapat dibuka.
2. Untuk pengiriman hanya lewat website, gunakan `VOUCHER_DELIVERY_CHANNEL=website`.
3. Untuk email, verifikasi domain di Resend lalu simpan `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, dan `RESEND_API_URL` dari **Integrasi & harga → Resend Email**.
4. Pengiriman otomatis WhatsApp tidak digunakan. Pilih `website` atau `email` untuk kanal voucher.
5. Di panel → Produk, buat produk kategori voucher seperti REDFINGER. Pada setiap nominal pilih provider **Stok kode LFAMILIA** dan isi kunci stok seperti `redfinger-30-hari`.
6. Di panel → Stok kode, pilih kunci stok yang sama dan tempel kode satu per baris.

Saat pembayaran terkonfirmasi lunas, satu baris stok direservasi secara atomik. Kode disimpan terenkripsi, tidak dikirim dua kali oleh callback pembayaran yang sama, dan tidak pernah muncul di pelacakan invoice publik. Bila kanal notifikasi tambahan gagal, kode tetap tersedia di website.

## 7. Syarat IP provider dan VPS relay

DigiFlazz dan iPaymu dapat menggunakan satu VPS relay ber-IP keluar statis. Midtrans Snap berjalan langsung dari Worker. URL relay per provider dan token Worker → VPS disimpan terenkripsi dari **Integrasi & harga → VPS Relay**. Tidak perlu membuat `PROVIDER_RELAY_*` manual di Cloudflare. Konfigurasi service VPS/Caddy tetap mengikuti `relay/README.md`.

VPS disiapkan untuk seluruh environment sejak awal. Worker mengirim environment eksplisit pada setiap request relay.

Saat berpindah ke Production:

1. isi credential Production dari **Integrasi & harga → Kredensial API & callback**;
2. ubah selector environment menjadi **Production** di panel;
3. jangan mengubah source repo atau Caddy;
4. jangan SSH ke VPS hanya untuk mengganti environment.

Callback provider tetap masuk langsung ke `PUBLIC_BASE_URL`. Midtrans Snap tetap dapat berjalan langsung dari Worker.

## 8. Menambah provider lain

Gunakan API resmi provider, bukan scraping atau menyalin cookie akun.

1. Buat adapter baru di `lib/server/providers/` yang mengikuti `ProviderAdapter`.
2. Daftarkan adapter di `lib/server/providers/index.ts`.
3. Tambahkan pilihannya di `lib/provider-options.ts`.
4. Buat webhook khusus dan validasi signature resmi provider.
5. Tambahkan field credential/endpoint ke Integration Manager agar tersimpan terenkripsi di D1; jangan membuat secret provider baru secara manual di Cloudflare.

Arsitektur checkout dan tabel order tidak perlu diubah hanya untuk menambah adapter provider baru.

## 9. Pemeriksaan sebelum production

- `INTEGRATION_ENCRYPTION_KEY` wajib tetap tersedia sebagai Cloudflare Secret root; kredensial provider/service lain dapat dikelola terenkripsi dari Integration Manager.
- Migrasi D1 production berhasil.
- Cloudflare Access aktif.
- Harga, margin, dan SKU sudah diverifikasi.
- Midtrans diuji di Sandbox sebelum selector Environment Midtrans di panel diubah ke Production.
- DigiFlazz tetap memakai Development sampai selector Environment DigiFlazz di panel memang ingin diubah ke Production.
- Callback semua provider telah diuji.
- Tidak ada secret atau konfigurasi environment operasional di GitHub.
- Tidak pernah meminta password, PIN, atau OTP pelanggan.
- Kode uji berhasil tersedia/dikirim sesuai kanal yang dipilih dan jumlah stok berkurang tepat satu.


## 10. Catatan keamanan panel

- Semua request langsung ke `/api/admin/*` diblokir di Worker bila identitas Cloudflare Access tidak tersedia.
- UI Admin/Staff memakai endpoint bersama `/api/panel/*` dan tetap diverifikasi oleh session + role pada server.
- Jika kredensial Integration Manager tidak dapat didekripsi, panel menampilkan **Kunci enkripsi tidak cocok**. Jangan mengganti `INTEGRATION_ENCRYPTION_KEY` setelah credential tersimpan.
- GitHub Actions menjalankan build, lint, test, dan test guard Cloudflare Access setiap push ke `main`.


## 11. Migration owner bootstrap

Migration `0020_remove_legacy_bootstrap_owner.sql` menghapus hanya akun Owner bootstrap lama yang belum pernah dibuatkan credential panel. Owner yang sudah dikonfigurasi tetap dipertahankan. Untuk instalasi baru atau recovery, pastikan Cloudflare Variable `OWNER_EMAIL` berisi email Pemilik yang diizinkan oleh Cloudflare Access, lalu buka `/admin/setup` untuk membuat ID Admin dan password.
