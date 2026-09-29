# LFAMILIA STORE

Toko top up digital dalam tahap pra-peluncuran. Domain publik melewati Cloudflare menuju VPS; aplikasi web dan API dijalankan oleh Vinext dan Laravel dengan MariaDB. Cloudflare Worker/D1 tetap ada sebagai arsip migrasi, tanpa route publik dan tanpa cron aktif.

## Fitur utama

- Katalog dinamis, checkout DOKU Checkout atau Midtrans Snap, saldo pelanggan, voucher, stok kode, membership, dan panel Pemilik/Staff.
- Produk otomatis menggunakan Digiflazz setelah pembayaran tervalidasi. Permintaan Digiflazz keluar langsung dari IP VPS ke `https://api.digiflazz.com`; opsi relay lama tidak digunakan.
- Secret pembayaran dan provider dikelola terenkripsi dari Super Admin → Integrasi, bukan disimpan di GitHub.
- Cloudflare Access melindungi panel Admin; Staff memakai jalur panel terpisah.

## Pengembangan dan validasi

Prasyarat: Node.js `>=22.13.0`, PHP 8.3, Composer, dan MariaDB untuk pengujian skema Laravel.

```bash
npm ci
npm run build
npm run lint
npm test
cd laravel
composer install
vendor/bin/phpunit
```

Runtime Laravel berada di [`laravel/`](./laravel/). Petunjuk infrastruktur dan status migrasi ada di [MIGRATION-LARAVEL.md](./MIGRATION-LARAVEL.md); konfigurasi provider di [INTEGRATION-SETUP.md](./INTEGRATION-SETUP.md). Berkas Worker, D1, dan migrasinya dipertahankan untuk histori, bukan jalur transaksi aktif.

## Keamanan

Jangan simpan API key, secret pembayaran, webhook secret, atau kunci enkripsi di GitHub. `APP_KEY`, kredensial database, dan `INTEGRATION_ENCRYPTION_KEY` berada di VPS. Credential provider di panel Integrasi dienkripsi dengan kunci tersebut.
