# Konfigurasi Integrasi LFAMILIA STORE

Dokumen ini dipakai setelah source berhasil diunggah ke GitHub dan Worker dapat dibangun. Mulai seluruh integrasi dalam mode sandbox/development.

## 1. Database D1

Binding yang dipakai aplikasi adalah `DB`, nama database `lfamilia-store-db`, dan folder migrasi `drizzle`.

Jalankan migrasi production satu kali:

```bash
npx wrangler d1 migrations apply lfamilia-store-db --remote
```

Jangan menghapus migrasi `0000`, `0001`, `0002`, atau `0003`. Setelah migrasi berhasil, buka `/admin`, masuk melalui Cloudflare Access, lalu tekan tombol **Lengkapi katalog utama** pada tab Produk. Tindakan ini menambahkan produk/nominal yang belum ada tanpa menimpa perubahan Anda.

## 2. Secret Cloudflare Worker

Tambahkan sebagai **Secret**, bukan Variable biasa dan bukan file GitHub:

| Nama | Isi |
|---|---|
| `IPAYMU_VA` | Nomor VA merchant iPaymu |
| `IPAYMU_API_KEY` | API Key iPaymu |
| `DIGIFLAZZ_USERNAME` | Username buyer DigiFlazz |
| `DIGIFLAZZ_API_KEY` | Production/development API key DigiFlazz |
| `DIGIFLAZZ_WEBHOOK_SECRET` | Secret webhook DigiFlazz |
| `VIPPAYMENT_API_ID` | API ID VIPayment |
| `VIPPAYMENT_API_KEY` | API Key VIPayment |
| `VOUCHER_ENCRYPTION_KEY` | Secret acak minimal 32 karakter; jangan pernah diganti setelah stok diimpor |
| `RESEND_API_KEY` | API key Resend untuk pengiriman kode lewat email |
| `WHATSAPP_ACCESS_TOKEN` | Token Meta WhatsApp Cloud API |

Tambahkan Variable biasa:

| Nama | Nilai awal |
|---|---|
| `PUBLIC_BASE_URL` | `https://domain-toko-anda` tanpa `/` terakhir |
| `IPAYMU_ENV` | `sandbox` |
| `DIGIFLAZZ_ENV` | `development` |
| `OWNER_EMAIL` | Email Pemilik utama yang sama dengan Cloudflare Access |
| `NICKNAME_API_URL` | URL API validasi nickname yang Anda izinkan |
| `VOUCHER_DELIVERY_CHANNEL` | `email`, `whatsapp`, atau `both` |
| `RESEND_FROM_EMAIL` | Pengirim dari domain email yang sudah diverifikasi |
| `WHATSAPP_PHONE_NUMBER_ID` | Phone Number ID dari Meta |
| `WHATSAPP_VOUCHER_TEMPLATE` | Nama template WhatsApp yang sudah disetujui |
| `WHATSAPP_TEMPLATE_LANGUAGE` | Kode bahasa template, contoh `id` |
| `WHATSAPP_GRAPH_VERSION` | Versi Graph API, contoh `v23.0` |

`IPAYMU_API_BASE_URL`, `DIGIFLAZZ_API_URL`, dan `VIPPAYMENT_API_URL` hanya perlu diisi bila memakai endpoint resmi yang berbeda atau relay ber-IP statis.

## 3. Callback dan webhook

Gunakan domain publik yang sama dengan `PUBLIC_BASE_URL`:

| Layanan | URL |
|---|---|
| iPaymu Notify URL | `https://domain-toko-anda/api/payments/ipaymu/callback` |
| DigiFlazz webhook | `https://domain-toko-anda/api/fulfillment/digiflazz/callback` |
| VIPayment webhook | `https://domain-toko-anda/api/fulfillment/vippayment/callback` |

Aplikasi memeriksa signature callback sebelum mengubah status. Callback pembayaran yang sudah pernah diproses tidak akan mengirim produk untuk kedua kali.

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
2. Untuk email, verifikasi domain di Resend lalu isi `RESEND_API_KEY` dan `RESEND_FROM_EMAIL`.
3. Untuk WhatsApp, buat template bernama `lfamilia_voucher_delivery` dengan lima parameter berurutan: nama pembeli, nama produk, paket, kode, dan invoice. Template harus disetujui Meta.
4. Di Admin → Produk, buat produk voucher seperti REDFINGER. Pilih proses Otomatis, provider **Stok Kode Internal**, lalu isi kunci stok seperti `redfinger-30-hari` pada setiap paket.
5. Di Admin → Voucher, pilih kunci stok yang sama dan tempel kode satu per baris.

Saat iPaymu menyatakan pembayaran lunas, satu baris stok direservasi secara atomik. Kode disimpan terenkripsi, tidak dikirim dua kali oleh callback pembayaran yang sama, dan tidak pernah muncul di pelacakan invoice publik. Bila kedua kanal gagal, kode tetap berstatus reservasi agar admin dapat mencoba pengiriman ulang tanpa mengambil kode baru.

## 7. Syarat IP provider

iPaymu Direct production meminta domain terdaftar dan IP statis. DigiFlazz juga memakai whitelist IP untuk koneksi buyer. Sebelum beralih ke production, konfirmasikan IP keluar Worker kepada kedua provider. Jika mereka meminta satu IP statis khusus, arahkan request outbound melalui relay/VPS kecil ber-IP statis; callback tetap diterima oleh Worker.

## 8. Menambah provider lain

Gunakan API resmi provider, bukan scraping atau menyalin cookie akun.

1. Buat adapter baru di `lib/server/providers/` yang mengikuti `ProviderAdapter`.
2. Daftarkan adapter di `lib/server/providers/index.ts`.
3. Tambahkan pilihannya di `lib/provider-options.ts`.
4. Buat webhook khusus dan validasi signature resmi provider.
5. Simpan credential sebagai Cloudflare Secret.

Arsitektur checkout dan tabel order tidak perlu diubah hanya untuk menambah adapter provider baru.

## 9. Pemeriksaan sebelum production

- Migrasi D1 production berhasil.
- Cloudflare Access aktif.
- Harga, margin, dan SKU sudah diverifikasi.
- iPaymu masih diuji di sandbox.
- DigiFlazz masih memakai `testing: true` sampai tes selesai.
- Callback semua provider telah diuji.
- Tidak ada secret di GitHub.
- Tidak pernah meminta password, PIN, atau OTP pelanggan.
- Kode uji berhasil dikirim lewat kanal yang dipilih dan jumlah stok berkurang tepat satu.
