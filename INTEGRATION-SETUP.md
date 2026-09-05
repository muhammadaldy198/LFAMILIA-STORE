# Konfigurasi Integrasi LFAMILIA STORE

Dokumen ini dipakai setelah source berhasil diunggah ke GitHub dan Worker dapat dibangun. Mulai seluruh integrasi dalam mode sandbox/development.

Konfigurasi operasional tidak disimpan di GitHub. `wrangler.jsonc` hanya menyimpan konfigurasi infrastruktur Worker dan memakai `keep_vars: true`, sehingga Variables/Secrets dikelola dari Cloudflare. Jika Variable wajib belum diisi, aplikasi akan menampilkan error konfigurasi yang jelas dan tidak memakai fallback hardcode.

## 1. Database D1

Binding yang dipakai aplikasi adalah `DB`, nama database `lfamilia-store-db`, dan folder migrasi `drizzle`.

Jalankan migrasi production satu kali:

```bash
npx wrangler d1 migrations apply lfamilia-store-db --remote
```

Jangan menghapus migrasi lama yang sudah pernah diterapkan. Setelah migrasi berhasil, masuk ke panel dengan ID admin + password, lalu tekan **Lengkapi katalog utama** pada tab Produk. Tindakan ini menambahkan produk/nominal yang belum ada tanpa menimpa perubahan yang sudah tersimpan.

## 2. Cloudflare Variables/Secrets provider

Gunakan matriks final pada `PROVIDER-CONFIG.md`.

Aturan konfigurasi:

- Sandbox/Development dan Production memiliki slot credential terpisah.
- Production credential boleh kosong selama onboarding belum selesai.
- Environment aktif hanya ditentukan oleh `MIDTRANS_ENV`, `MIDTRANS_MODE`, `IPAYMU_ENV`, dan `DIGIFLAZZ_ENV`.
- Source tidak memilih environment dari prefix key, keberadaan Production key, VA, atau fallback endpoint.
- Setelah Production credential tersedia, isi slot Production dan ubah selector di Cloudflare saja.

Secret integrasi non-provider yang tetap digunakan:

```text
MELOSTORE_API_KEY
MELOSTORE_SECRET_KEY
NICKNAME_API_KEY
VIPPAYMENT_API_ID
VIPPAYMENT_API_KEY
VOUCHER_ENCRYPTION_KEY
RESEND_API_KEY
WHATSAPP_ACCESS_TOKEN
```

Variable umum yang tetap digunakan:

```text
PUBLIC_BASE_URL
OWNER_EMAIL
NICKNAME_API_URL
MELOSTORE_API_URL
VIPPAYMENT_API_URL
VOUCHER_DELIVERY_CHANNEL
RESEND_FROM_EMAIL
RESEND_API_URL
WHATSAPP_PHONE_NUMBER_ID
WHATSAPP_VOUCHER_TEMPLATE
WHATSAPP_TEMPLATE_LANGUAGE
WHATSAPP_GRAPH_VERSION
WHATSAPP_GRAPH_BASE_URL
```

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
- Halaman pemulihan Pemilik di `/admin/setup` tetap harus dilindungi Cloudflare Access.
- `OWNER_EMAIL` digunakan untuk jalur pemulihan Pemilik, bukan sebagai password/login operasional.
- Jangan mengekspos secret provider atau gateway ke browser.

## 6. Stok kode REDFINGER atau lisensi

1. Isi `VOUCHER_ENCRYPTION_KEY` terlebih dahulu. Setelah kode diimpor, nilai ini tidak boleh diganti atau kode lama tidak dapat dibuka.
2. Untuk pengiriman hanya lewat website, gunakan `VOUCHER_DELIVERY_CHANNEL=website`.
3. Untuk email, verifikasi domain di Resend lalu isi `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, dan `RESEND_API_URL`.
4. Untuk WhatsApp, isi seluruh Variable/Secret WhatsApp dan gunakan template dengan lima parameter berurutan: nama pembeli, nama produk, paket, kode, dan invoice. Template harus disetujui Meta.
5. Di panel → Produk, buat produk kategori voucher seperti REDFINGER. Pada setiap nominal pilih provider **Stok kode LFAMILIA** dan isi kunci stok seperti `redfinger-30-hari`.
6. Di panel → Stok kode, pilih kunci stok yang sama dan tempel kode satu per baris.

Saat pembayaran terkonfirmasi lunas, satu baris stok direservasi secara atomik. Kode disimpan terenkripsi, tidak dikirim dua kali oleh callback pembayaran yang sama, dan tidak pernah muncul di pelacakan invoice publik. Bila kanal notifikasi tambahan gagal, kode tetap tersedia di website.

## 7. Syarat IP provider dan VPS relay

DigiFlazz, iPaymu, dan Midtrans BI-SNAP dapat menggunakan satu VPS relay ber-IP keluar statis. Konfigurasi final relay ada pada `relay/README.md`.

VPS disiapkan untuk seluruh environment sejak awal. Worker mengirim environment eksplisit pada setiap request relay.

Saat berpindah ke Production:

1. isi credential Production di Cloudflare;
2. ubah selector environment di Cloudflare;
3. jangan mengubah source repo;
4. jangan mengubah Caddy;
5. jangan SSH ke VPS hanya untuk mengganti environment.

Callback provider tetap masuk langsung ke `PUBLIC_BASE_URL`. Midtrans Snap tetap dapat berjalan langsung dari Worker.

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
- Midtrans diuji di Sandbox sebelum `MIDTRANS_ENV` diubah ke `production`.
- `DIGIFLAZZ_ENV=development` sampai Production memang ingin diaktifkan.
- Callback semua provider telah diuji.
- Tidak ada secret atau konfigurasi environment operasional di GitHub.
- Tidak pernah meminta password, PIN, atau OTP pelanggan.
- Kode uji berhasil tersedia/dikirim sesuai kanal yang dipilih dan jumlah stok berkurang tepat satu.
