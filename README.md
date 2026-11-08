# LFAMILIA STORE

Repo ini berisi satu aplikasi yang aktif: toko online LFAMILIA di folder `rebuild/`. Kode di folder itulah yang berjalan. Dokumen milestone lama bukan status aplikasi sekarang.

## Isi toko ini dibangun dengan

- Laravel 12 + PHP 8.4
- Vue 3 + Inertia.js v2 + TailwindCSS v4
- MySQL 8
- Redis untuk cache, antrean, dan session
- Nginx di VPS, dikelola lewat CloudPanel
- Cloudflare di depan sebagai pelindung dan penyalur trafik
- Laravel Fortify + Sanctum untuk login
- Spatie Media Library untuk berkas gambar

Jalur lama (Next.js/Vinext, Cloudflare Worker/D1, Drizzle, runtime Laravel lama) sudah tidak dipakai. Riwayatnya masih ada di Git history.

## Dokumen: mana yang menang kalau berbeda

1. [docs/PRD.md](docs/PRD.md) — aturan produk, satu-satunya sumber aturan.
2. [AGENTS.md](AGENTS.md) — cara kerja, alur Git, dan larangan.
3. [docs/ADMIN-PANEL.md](docs/ADMIN-PANEL.md) — spesifikasi panel admin.
4. Dokumen teknis di bawah — menjelaskan cara kode bekerja, bukan aturan produk.

## Peta dokumen teknis

- Arsitektur: [rebuild/ARCHITECTURE.md](rebuild/ARCHITECTURE.md)
- Aplikasi: [rebuild/README.md](rebuild/README.md)
- Database: [rebuild/DATABASE.md](rebuild/DATABASE.md)
- Login dan akun: [rebuild/AUTH.md](rebuild/AUTH.md)
- Checkout, pembayaran, pengiriman: [rebuild/CHECKOUT.md](rebuild/CHECKOUT.md), [rebuild/PAYMENT.md](rebuild/PAYMENT.md), [rebuild/FULFILLMENT.md](rebuild/FULFILLMENT.md)
- Panel admin: [rebuild/ADMIN.md](rebuild/ADMIN.md)
- Keamanan: [rebuild/SECURITY.md](rebuild/SECURITY.md)
- Tes dan CI: [rebuild/TESTING.md](rebuild/TESTING.md)
- Deploy, backup, rollback: [rebuild/deploy/README.md](rebuild/deploy/README.md)
- Status pengerjaan: [docs/STATUS.md](docs/STATUS.md)

Dokumen yang sudah tidak berlaku dipindah ke `docs/archive/` dan `rebuild/docs/archive/`. Arsip hanya untuk melacak keputusan lama — jangan dibaca sebagai aturan.

## Menjalankan di komputer sendiri

```bash
cd rebuild
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run lint
npm run check
npm run build
vendor/bin/phpunit
```

Pakai selalu nilai lokal, bukan nilai produksi. Jangan pernah commit secret, API key, kredensial provider atau pembayaran, private key, token, berkas `.env`, dump produksi, atau data pelanggan.
