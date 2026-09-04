# Konfigurasi Integrasi LFAMILIA STORE

Dokumen ini dipakai setelah source berhasil diunggah ke GitHub dan Worker dapat dibangun. Mulai seluruh integrasi dalam mode sandbox/development.

Konfigurasi operasional tidak disimpan di GitHub. `wrangler.jsonc` hanya menyimpan konfigurasi infrastruktur Worker dan memakai `keep_vars: true`, sehingga Variables/Secrets dikelola dari Cloudflare. Jika Variable wajib belum diisi, aplikasi akan menampilkan error konfigurasi yang jelas dan tidak memakai fallback hardcode.

## 1. Database D1

Binding yang dipakai aplikasi adalah `DB`, nama database `lfamilia-store-db`, dan folder migrasi `drizzle`.

Jalankan migrasi production satu kali:

```bash
npx wrangler d1 migrations apply lfamilia-store-db --remote
```

Jangan menghapus migrasi lama yang sudah pernah diterapkan. Setelah migrasi berhasil, buka `/admin`, masuk melalui Cloudflare Access, lalu tekan tombol **Lengkapi katalog utama** pada tab Produk. Tindakan ini menambahkan produk/nominal yang belum ada tanpa menimpa perubahan Anda.

## 2. Secret Cloudflare Worker

Tambahkan sebagai **Secret**, bukan Variable biasa dan bukan file GitHub:

| Nama | Isi |
|---|---|
| `MIDTRANS_SERVER_KEY` | Server Key Midtrans sandbox/production |
| `MELOSTORE_API_KEY` | API Key H2H Melostore untuk validasi nickname |
| `MELOSTORE_SECRET_KEY` | Secret Key H2H Melostore untuk validasi nickname |
| `NICKNAME_API_KEY` | API key fallback nickname bila penyedia fallback membutuhkannya; opsional |
| `DIGIFLAZZ_USERNAME` | Username buyer DigiFlazz |
| `DIGIFLAZZ_API_KEY` | Production/development API key DigiFlazz |
| `DIGIFLAZZ_WEBHOOK_SECRET` | Secret webhook DigiFlazz |
| `PROVIDER_RELAY_TOKEN` | Secret acak minimal 32 karakter, sama dengan `RELAY_TOKEN` pada VPS provider relay |
| `VIPPAYMENT_API_ID` | API ID VIPayment |
| `VIPPAYMENT_API_KEY` | API Key VIPayment |
| `VOUCHER_ENCRYPTION_KEY` | Secret acak minimal 32 karakter; jangan pernah diganti setelah stok diimpor |
| `RESEND_API_KEY` | API key Resend untuk pengiriman kode lewat email |
| `WHATSAPP_ACCESS_TOKEN` | Token Meta WhatsApp Cloud API |

Tambahkan sebagai **Variable biasa**. Nilai berikut adalah konfigurasi Sandbox/Development LFAMILIA saat ini:

| Nama | Nilai Sandbox/Development |
|---|---|
| `PUBLIC_BASE_URL` | `https://lfamiliastore.my.id` |
| `MIDTRANS_ENV` | `sandbox` |
| `MIDTRANS_CLIENT_KEY` | Client Key Sandbox dari dashboard Midtrans |
| `MIDTRANS_SNAP_API_URL` | `https://app.sandbox.midtrans.com/snap/v1/transactions` |
| `MIDTRANS_SNAP_SCRIPT_URL` | `https://app.sandbox.midtrans.com/snap/snap.js` |
| `DIGIFLAZZ_ENV` | `development` |
| `DIGIFLAZZ_API_URL` | Direct: `https://api.digiflazz.com/v1/transaction`; setelah relay aktif: `https://digiflazz-relay.lfamiliastore.my.id/v1/transaction` |
| `DIGIFLAZZ_PRICE_LIST_URL` | Direct: `https://api.digiflazz.com/v1/price-list`; setelah relay aktif: `https://digiflazz-relay.lfamiliastore.my.id/v1/price-list` |
| `PROVIDER_RELAY_HOSTS` | `digiflazz-relay.lfamiliastore.my.id,ipaymu-relay.lfamiliastore.my.id,bisnap-relay.lfamiliastore.my.id` |
| `OWNER_EMAIL` | Email Pemilik utama yang sama dengan akun admin |
| `NICKNAME_API_URL` | `https://api.isan.eu.org/nickname` |
| `MELOSTORE_API_URL` | `https://api.melostore.id` |
| `VIPPAYMENT_API_URL` | `https://vip-reseller.co.id/api/game-feature` |
| `VOUCHER_DELIVERY_CHANNEL` | `website` |
| `RESEND_FROM_EMAIL` | Pengirim dari domain email yang sudah diverifikasi |
| `RESEND_API_URL` | `https://api.resend.com/emails` |
| `WHATSAPP_PHONE_NUMBER_ID` | Phone Number ID dari Meta |
| `WHATSAPP_VOUCHER_TEMPLATE` | Nama template WhatsApp yang sudah disetujui, misalnya `lfamilia_voucher_delivery` |
| `WHATSAPP_TEMPLATE_LANGUAGE` | Kode bahasa template, misalnya `id` |
| `WHATSAPP_GRAPH_VERSION` | Versi Graph API yang sedang digunakan, misalnya `v23.0` |
| `WHATSAPP_GRAPH_BASE_URL` | `https://graph.facebook.com` |

Untuk pindah Midtrans ke Production, kode repo tidak perlu diubah. Ganti Variable/Secret Cloudflare berikut:

- `MIDTRANS_ENV` → `production`
- `MIDTRANS_CLIENT_KEY` → Client Key Production
- `MIDTRANS_SERVER_KEY` → Server Key Production
- `MIDTRANS_SNAP_API_URL` → `https://app.midtrans.com/snap/v1/transactions`
- `MIDTRANS_SNAP_SCRIPT_URL` → `https://app.midtrans.com/snap/snap.js`

Untuk DigiFlazz Production, ubah `DIGIFLAZZ_ENV` menjadi `production` dan gunakan credential Production. Endpoint tetap dikendalikan melalui Variable Cloudflare.

## 3. Callback dan webhook

Gunakan domain publik yang sama dengan `PUBLIC_BASE_URL`:

| Layanan | URL |
|---|---|
| Midtrans notification | `https://lfamiliastore.my.id/api/payments/midtrans/callback` |
| DigiFlazz webhook | `https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback` |
| VIPayment webhook | `https://lfamiliastore.my.id/api/fulfillment/vippayment/callback` |

Aplikasi memeriksa callback pembayaran sebelum mengubah status. Callback yang sudah pernah diproses tidak akan mengirim produk untuk kedua kali.

## 4. Produk otomatis dan manual

Di `/admin` buka tab **Produk**.

- Produk otomatis: pilih DigiFlazz atau VIPayment pada setiap nominal, lalu isi SKU persis seperti katalog provider.
- Produk manual: pilih tipe Manual dan isi instruksi. Setelah lunas, pesanan masuk tab Pesanan dan admin menandainya selesai.
- `target_template` mendukung `{{destination}}` dan `{{server}}`. Contoh Mobile Legends: `{{destination}}{{server}}` untuk DigiFlazz.

Verifikasi harga, margin, jam operasional, dan instruksi setiap produk sebelum menerima pembayaran.

## 5. Keamanan admin

Admin tidak ditautkan dari toko utama. Lindungi dua pola berikut dengan Cloudflare Access dan hanya izinkan email Pemilik/Staff yang dipercaya:

- `/admin*`
- `/api/admin*`

Login memakai identitas email Cloudflare Access (kode sekali pakai atau identity provider), sehingga website tidak menyimpan password admin. Setelah email diizinkan oleh Access, daftarkan email yang sama di **Admin → Tim admin** dan pilih role **Pemilik** atau **Staff**. Tetapkan `OWNER_EMAIL` ke email Pemilik utama.

Jangan membuka admin sebelum Access aktif. Bila memakai custom domain, pastikan alamat alternatif `workers.dev` tidak menjadi jalan masuk publik yang tidak dilindungi.

## 6. Stok kode REDFINGER atau lisensi

1. Isi `VOUCHER_ENCRYPTION_KEY` terlebih dahulu. Setelah kode diimpor, nilai ini tidak boleh diganti atau kode lama tidak dapat dibuka.
2. Untuk pengiriman hanya lewat website, gunakan `VOUCHER_DELIVERY_CHANNEL=website`.
3. Untuk email, verifikasi domain di Resend lalu isi `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, dan `RESEND_API_URL`.
4. Untuk WhatsApp, isi seluruh Variable/Secret WhatsApp dan gunakan template dengan lima parameter berurutan: nama pembeli, nama produk, paket, kode, dan invoice. Template harus disetujui Meta.
5. Di Admin → Produk, buat produk voucher seperti REDFINGER. Pilih proses Otomatis, provider **Stok Kode Internal**, lalu isi kunci stok seperti `redfinger-30-hari` pada setiap paket.
6. Di Admin → Voucher, pilih kunci stok yang sama dan tempel kode satu per baris.

Saat pembayaran terkonfirmasi lunas, satu baris stok direservasi secara atomik. Kode disimpan terenkripsi, tidak dikirim dua kali oleh callback pembayaran yang sama, dan tidak pernah muncul di pelacakan invoice publik. Bila kanal notifikasi tambahan gagal, kode tetap tersedia di website.

## 7. Syarat IP provider dan VPS relay

Satu VPS ber-IP publik statis dapat dipakai sebagai relay pusat untuk DigiFlazz, iPaymu, dan Midtrans BI-SNAP. Source relay tersedia di folder `relay/`.

Hostname yang disiapkan:

- `digiflazz-relay.lfamiliastore.my.id`
- `ipaymu-relay.lfamiliastore.my.id`
- `bisnap-relay.lfamiliastore.my.id`

Midtrans Snap biasa tetap langsung dari Worker ke Midtrans. Jangan memindahkan Snap ke relay hanya karena VPS tersedia.

Untuk mengaktifkan DigiFlazz melalui VPS:

1. Jalankan `relay/server.mjs` pada VPS di `127.0.0.1:8788`.
2. Pasang konfigurasi Caddy dari `relay/Caddyfile.example`.
3. Buat secret VPS `RELAY_TOKEN`, lalu simpan nilai yang sama sebagai Cloudflare Secret `PROVIDER_RELAY_TOKEN`.
4. Isi `PROVIDER_RELAY_HOSTS` dengan tiga hostname relay.
5. Ubah `DIGIFLAZZ_API_URL` dan `DIGIFLAZZ_PRICE_LIST_URL` ke hostname relay DigiFlazz.
6. Daftarkan IP publik VPS sebagai IP koneksi DigiFlazz sesuai environment yang dipakai.

Callback provider tetap diterima langsung oleh Worker pada domain utama LFAMILIA dan tidak perlu melewati VPS. Upstream iPaymu dan BI-SNAP pada VPS dibiarkan kosong sampai akun dan endpoint resmi masing-masing siap.

## 8. Menambah provider lain

Gunakan API resmi provider, bukan scraping atau menyalin cookie akun.

1. Buat adapter baru di `lib/server/providers/` yang mengikuti `ProviderAdapter`.
2. Daftarkan adapter di `lib/server/providers/index.ts`.
3. Tambahkan pilihannya di `lib/provider-options.ts`.
4. Buat webhook khusus dan validasi signature resmi provider.
5. Simpan credential sebagai Cloudflare Secret dan endpoint/config operasional sebagai Cloudflare Variable.

Arsitektur checkout dan tabel order tidak perlu diubah hanya untuk menambah adapter provider baru.

## 9. Pemeriksaan sebelum production

- Semua Variable/Secret wajib di Cloudflare sudah terisi.
- Migrasi D1 production berhasil.
- Cloudflare Access aktif.
- Harga, margin, dan SKU sudah diverifikasi.
- Midtrans diuji di sandbox sebelum beralih ke production.
- DigiFlazz tetap `development` sampai tes selesai, lalu diubah ke `production` dari Cloudflare.
- Callback semua provider telah diuji.
- Tidak ada secret atau konfigurasi environment operasional di GitHub.
- Tidak pernah meminta password, PIN, atau OTP pelanggan.
- Kode uji berhasil tersedia/dikirim sesuai kanal yang dipilih dan jumlah stok berkurang tepat satu.
