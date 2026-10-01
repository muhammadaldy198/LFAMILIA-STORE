# LFAMILIA STORE

Repository ini memakai **satu runtime aplikasi** di `rebuild/`, sesuai PRD LFAMILIA STORE.

Stack aktif:
- Laravel 12 + PHP 8.4
- Vue 3 + Inertia.js v2 + TailwindCSS v4
- MySQL 8
- Redis untuk cache, queue, dan session
- Nginx + CloudPanel
- Cloudflare di edge/security
- Laravel Fortify + Sanctum
- Spatie Media Library

Kode Next.js/Vinext, Cloudflare Worker/D1, Drizzle, dan Laravel migrasi lama sudah dikeluarkan dari working tree agar tidak menjadi runtime atau sumber kebenaran kedua. Riwayatnya tetap tersedia di Git history.

## Pengembangan

```bash
cd rebuild
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
vendor/bin/phpunit
```

Dokumentasi aplikasi berada di `rebuild/*.md`, deployment di `rebuild/deploy/`, dan PRD utama di `docs/PRD-LFAMILIA-STORE-v1.md`.

Jangan commit secret, API key, credential provider/payment, atau file `.env`.
