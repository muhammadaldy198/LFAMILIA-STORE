# LFAMILIA STORE

Toko top up digital berbasis Cloudflare Workers dan D1. Aplikasi mencakup katalog, checkout melalui DOKU Checkout atau Midtrans Snap, saldo pelanggan, pemenuhan otomatis melalui provider, antrean manual, voucher diskon, stok kode terenkripsi, pelacakan transaksi, membership, serta panel Pemilik/Staff.

## Fitur utama

- Katalog dinamis dengan gambar, banner produk, kategori, urutan, status, tab pemisah nominal, dan harga yang dikelola dari panel.
- Nominal produk memakai provider per nominal, SKU provider, margin Rupiah/Persen, harga modal supplier, dan sinkron harga DigiFlazz per nominal maupun otomatis.
- Produk otomatis eksternal memakai DigiFlazz. Stok Kode LFAMILIA diproses internal; produk manual masuk antrean admin setelah pembayaran terverifikasi.
- Checkout hanya memakai gateway yang didukung: **DOKU Checkout** atau **Midtrans Snap**. Setiap gateway mempunyai toggle aktif/nonaktif terpisah dari status kesiapan credential.
- Saldo pelanggan dapat di-top-up otomatis. Super Admin memilih gateway top up secara terpisah antara **DOKU Checkout** dan **Midtrans Snap**, serta dapat mematikan top up otomatis dengan master toggle.
- Account pelanggan dengan login email/password atau Google Identity Services (Client ID only), membership BASIC/GOLD/DIAMOND/PLATINUM, leaderboard, ulasan, bantuan/refund, berita, FAQ, banner Home, dan pop-up informasi produk.
- Voucher diskon, promo terjadwal, minimum transaksi, kuota, dan batas potongan.
- Stok kode digital disimpan terenkripsi dan dapat ditampilkan di website serta dikirim melalui kanal notifikasi yang dikonfigurasi.
- Panel memakai sesi Pemilik/Staff berbasis ID admin + password. Akses pemulihan Pemilik tetap dapat dilindungi dengan Cloudflare Access.
- Secret DOKU/Midtrans/DigiFlazz tidak disimpan di repository. Google Login hanya memakai Client ID yang dikelola dari Integration Manager; tidak ada Google Client Secret di aplikasi.

## Menjalankan proyek

Prasyarat: Node.js `>=22.13.0` dan akun Cloudflare dengan Workers serta D1.

```bash
npm ci
npm run build
npm run lint
npm test
```

Konfigurasi Worker berada di `wrangler.jsonc`. Binding database harus bernama `DB`. Untuk deployment aktif, persiapan schema pembayaran dilakukan dari Admin Panel. Migration SQL tetap tersedia sebagai baseline untuk instalasi baru, sedangkan metadata Drizzle lama yang sudah tidak sinkron telah dihapus.

Panduan secret, callback, provider, relay, dan pengiriman kode tersedia di [INTEGRATION-SETUP.md](./INTEGRATION-SETUP.md).

## Keamanan

- Jangan simpan API key, secret pembayaran, webhook secret, atau kunci enkripsi di GitHub.
- Jangan meminta PIN atau OTP pelanggan melalui formulir maupun catatan pesanan.
- Lindungi halaman pemulihan Pemilik dan endpoint admin sensitif sesuai konfigurasi deployment.
- Gunakan password admin yang kuat; password disimpan sebagai hash dan tidak dapat dibaca kembali dari panel.
- `INTEGRATION_ENCRYPTION_KEY` tetap disimpan sebagai Cloudflare Secret root. Credential provider/service, relay, dan environment provider wajib dikelola terenkripsi melalui Integration Manager dan tidak memakai fallback Cloudflare Variables/Secrets.
