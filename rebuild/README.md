# LFAMILIA STORE — rebuild v1

Isolated M1 runtime following [PRD v1](../docs/PRD-LFAMILIA-STORE-v1.md). This is a foundation, not a live store. The existing production runtime in the repository is not replaced by this directory.

Requirements: PHP 8.4 with pdo_mysql and phpredis, Composer 2, Node 22+, MySQL 8, Redis.

```bash
cd rebuild
composer install
npm ci
cp .env.example .env
# Set DB_* and REDIS_* locally. Never commit .env.
php artisan key:generate
php artisan migrate
npm run build
php artisan test
php artisan serve
```

The homepage is an Inertia/Vue foundation screen. `/up` checks Laravel boot; `/health/ready` checks MySQL and Redis. M2 adds the business schema. Auth, catalog, payments, fulfillment, and admin are not implemented in M1. No provider credentials belong in this directory; integration secrets will be encrypted in the database and managed through Super Admin → Integrasi in a later milestone.
