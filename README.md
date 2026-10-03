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

- Arsitektur aktual: [rebuild/ARCHITECTURE.md](rebuild/ARCHITECTURE.md)
- Aplikasi: [rebuild/README.md](rebuild/README.md)
- Database: [rebuild/DATABASE.md](rebuild/DATABASE.md)
- Auth: [rebuild/AUTH.md](rebuild/AUTH.md)
- Checkout/payment/fulfillment: [rebuild/CHECKOUT.md](rebuild/CHECKOUT.md), [rebuild/PAYMENT.md](rebuild/PAYMENT.md), [rebuild/FULFILLMENT.md](rebuild/FULFILLMENT.md)
- Admin: [rebuild/ADMIN.md](rebuild/ADMIN.md)
- Security: [rebuild/SECURITY.md](rebuild/SECURITY.md)
- Testing/CI: [rebuild/TESTING.md](rebuild/TESTING.md)
- Deployment/backup/rollback: [rebuild/deploy/README.md](rebuild/deploy/README.md)
- PRD: [docs/PRD-LFAMILIA-STORE-v1.md](docs/PRD-LFAMILIA-STORE-v1.md)

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
