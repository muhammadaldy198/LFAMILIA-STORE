# LFAMILIA STORE — Laravel runtime

Direktori `rebuild/` adalah runtime aplikasi LFAMILIA STORE yang aktif. Narasi lama bahwa direktori ini hanya “M1 foundation” sudah tidak berlaku.

## Stack

- Laravel 12 / PHP 8.4
- Vue 3 / Inertia.js v2 / TailwindCSS v4
- MySQL 8
- Redis untuk cache, queue, dan session
- Nginx pada VPS/CloudPanel
- Fortify + Sanctum
- Spatie Media Library

Versi dependency yang lebih detail harus dibaca dari `composer.json`, `composer.lock`, `package.json`, dan `package-lock.json`.

## Struktur runtime

- Customer frontend: Vue/Inertia di `resources/js`, dilayani Laravel.
- Admin panel: route `/admin`, Vue/Inertia, authorization server-side.
- Backend: Laravel controllers, services, jobs, middleware, dan scheduler.
- Database: satu database bisnis MySQL 8 melalui Laravel migrations.
- Cache/queue/session: Redis.
- Media: Spatie Media Library, storage sesuai konfigurasi server.
- Edge: Cloudflare di depan Nginx.
- Deployment: `deploy/`.

Flow dan boundary ada di [ARCHITECTURE.md](ARCHITECTURE.md).

## Status implementasi

Kode repository sudah mencakup auth customer/Admin, katalog, checkout, voucher/membership, payment routing, wallet/top-up, Manual QRIS, callback/reconciliation, fulfillment Digiflazz/manual/stok kode, customer support, admin panel, audit log, system health, security middleware, backup/restore tooling, dan CI.

**Kode integrasi tersedia tidak sama dengan credential production sudah dikonfigurasi atau live E2E provider sudah lolos.** Credential provider dikelola melalui Super Admin → Integrasi.

## Pengembangan

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run lint
npm run check
npm run build
vendor/bin/phpunit
vendor/bin/pint --test
```

Smoke browser: `bash tests/Browser/smoke.sh` pada environment test yang siap.

## Local development guardrails

Setelah `npm ci`, Husky memasang pre-commit dan pre-push hook pada working copy developer. Pre-commit hanya memeriksa staged files; pre-push menjalankan lint, Vue static check, production build, PHP syntax, dan Pint. Detail rule, manual command, serta smoke test ada di [TESTING.md](TESTING.md).

CI tetap wajib sebelum merge. Hook lokal dapat dilewati dan tidak digunakan sebagai security boundary production.

## Dokumentasi

Lihat `ARCHITECTURE.md`, `DATABASE.md`, `AUTH.md`, `PRODUCT.md`, `CHECKOUT.md`, `PAYMENT.md`, `FULFILLMENT.md`, `ADMIN.md`, `SECURITY.md`, `TESTING.md`, dan `deploy/README.md`.

Jangan menyimpan credential, `.env`, backup passphrase, private key, payment token, atau production dump di repository.
