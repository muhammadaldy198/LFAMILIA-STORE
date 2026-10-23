# LFAMILIA STORE

Repository ini memiliki **satu runtime aplikasi aktif** di `rebuild/`. Source of truth implementasi adalah kode Laravel/Vue di direktori tersebut beserta dokumentasi operasional yang ditautkan di bawah; dokumen milestone lama bukan status runtime.

## Stack aktif

- Laravel 12 + PHP 8.4
- Vue 3 + Inertia.js v2 + TailwindCSS v4
- MySQL 8
- Redis untuk cache, queue, dan session
- Nginx di VPS yang dikelola melalui CloudPanel
- Cloudflare di edge
- Laravel Fortify + Sanctum
- Spatie Media Library

Runtime Next.js/Vinext, Cloudflare Worker/D1, Drizzle, dan runtime Laravel lama tidak lagi menjadi jalur aplikasi aktif. Riwayatnya tetap tersedia di Git history.

## Dokumentasi utama

**Urutan kekuasaan dokumen** — kalau dua dokumen berbeda, yang lebih atas menang:

1. [docs/PRD.md](docs/PRD.md) — aturan produk (satu-satunya).
2. [AGENTS.md](AGENTS.md) — cara kerja, alur git, larangan.
3. [docs/ADMIN-PANEL.md](docs/ADMIN-PANEL.md) — spesifikasi panel admin.
4. Dokumen teknis di bawah — menjelaskan **cara kode bekerja**, bukan aturan produk.

- Arsitektur aktual: [rebuild/ARCHITECTURE.md](rebuild/ARCHITECTURE.md)
- Aplikasi: [rebuild/README.md](rebuild/README.md)
- Database: [rebuild/DATABASE.md](rebuild/DATABASE.md)
- Auth: [rebuild/AUTH.md](rebuild/AUTH.md)
- Checkout/payment/fulfillment: [rebuild/CHECKOUT.md](rebuild/CHECKOUT.md), [rebuild/PAYMENT.md](rebuild/PAYMENT.md), [rebuild/FULFILLMENT.md](rebuild/FULFILLMENT.md)
- Admin: [rebuild/ADMIN.md](rebuild/ADMIN.md)
- Security: [rebuild/SECURITY.md](rebuild/SECURITY.md)
- Testing/CI: [rebuild/TESTING.md](rebuild/TESTING.md)
- Deployment/backup/rollback: [rebuild/deploy/README.md](rebuild/deploy/README.md)
- Status pengerjaan: [docs/STATUS.md](docs/STATUS.md)

Dokumen lama yang sudah tidak berlaku dipindahkan ke `docs/archive/` dan `rebuild/docs/archive/`. **Jangan dibaca sebagai aturan** — hanya untuk melacak keputusan masa lalu.

## Pengembangan lokal

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

Gunakan nilai lokal/non-production. Jangan commit secret, API key, credential provider/payment, private key, token, `.env`, production dump, atau data pelanggan.
