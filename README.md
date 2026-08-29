# LFAMILIA STORE

Toko top up digital berbasis Cloudflare Workers dan D1. Aplikasi mencakup katalog, checkout iPaymu, pemenuhan otomatis, antrean manual, flash sale, voucher diskon, stok kode terenkripsi, pelacakan transaksi, serta panel admin dua tingkat.

## Fitur utama

- Katalog dinamis dengan gambar, kategori, urutan, status, nominal, dan harga yang dikelola dari panel admin.
- Pop-up informasi multi-slide per produk, termasuk jam operasional produk manual dan pilihan sembunyikan selama tujuh hari.
- Banner Home, logo, pengumuman, FAQ, dan kategori yang dapat diedit tanpa mengubah source.
- Flash sale terjadwal, kuota promo, kode voucher rupiah/persentase, minimum transaksi, dan batas potongan.
- Pembayaran iPaymu dengan perhitungan harga ulang di server.
- Adapter DigiFlazz, VIPayment, dan Stok Kode Internal.
- Pemilik dan Staff melalui identitas Cloudflare Access. Keuangan, harga/provider, promo, stok kode, tim, integrasi, dan penghapusan dibatasi untuk Pemilik.
- Kode digital disimpan terenkripsi dan dapat dikirim melalui Resend dan WhatsApp Cloud API.

## Menjalankan proyek

Prasyarat: Node.js `>=22.13.0` dan akun Cloudflare dengan Workers serta D1.

```bash
npm ci
npm run build
npm test
```

Konfigurasi Worker berada di `wrangler.jsonc`. Binding database harus bernama `DB`. Terapkan seluruh migrasi di folder `drizzle` secara berurutan; instalasi terbaru wajib menyertakan `0003_final_storefront.sql`.

Panduan secret, callback, Cloudflare Access, provider, dan pengiriman kode tersedia di [INTEGRATION-SETUP.md](./INTEGRATION-SETUP.md).

## Keamanan

- Jangan simpan VA, API key, webhook secret, atau kunci enkripsi di GitHub.
- Jangan meminta password, PIN, atau OTP pelanggan melalui formulir maupun catatan pesanan.
- Lindungi `/admin*` dan `/api/admin*` dengan Cloudflare Access.
- Tambahkan email admin di Cloudflare Access dan di tab **Tim admin** dengan role yang sesuai.
- Rahasia pembayaran/provider hanya dikelola melalui Cloudflare Worker Settings.
