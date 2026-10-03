# Testing & CI

Dokumen ini mencatat gate yang benar-benar tersedia di repository. Jangan menyebut Playwright/Cypress karena project tidak menggunakannya.

## Frontend

Dari `rebuild/`:

```bash
npm ci
npm run lint
npm run check
npm run build
```

`lint` menjalankan ESLint pada Vue/JavaScript. `check` menjalankan `vue-tsc --noEmit` dengan `jsconfig.json`. Project tidak dimigrasikan penuh ke TypeScript hanya demi static checking.

## PHP/Laravel

```bash
find app bootstrap config public routes tests -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
vendor/bin/pint --test
composer audit --no-interaction
```

Tests menggunakan MySQL 8 + Redis di CI, bukan SQLite sebagai pengganti behavior database production.

## Schema/migration

GitHub Actions menjalankan:

```bash
php artisan migrate --force
php artisan migrate:rollback --step=10 --force
php artisan migrate --force
```

CI juga memvalidasi Laravel config cache/clear.

## Browser smoke

```bash
bash tests/Browser/smoke.sh
```

Smoke menggunakan headless Chromium tooling di repository untuk customer dan Admin flow yang didefinisikan test. Ini bukan Playwright/Cypress.

## Security/secret guardrails

Workflow memeriksa:

- Composer audit;
- tracked `.env`;
- tracked private-key material;
- pola hardcoded secret pada source/config/routes/resources;
- Laravel config cache;
- syntax deployment shell scripts;
- required deployment assets.

Fixture browser/test hanya boleh berisi credential disposable untuk environment test; tidak boleh berisi credential production.

## Deployment validation

CI memeriksa file deploy, menjalankan encrypted backup + restore verification pada DB CI, serta build/test/lint/style gate.

`deploy/healthcheck.sh` digunakan pada server untuk memverifikasi public edge readiness, local origin readiness, queue worker, dan scheduler.

## Local Git hooks

Local developer guardrails menggunakan Husky 9.1.7. `npm ci`/install lokal menjalankan script `prepare` yang memasang hook ke Git worktree. CI dan deployment production memakai `HUSKY=0`, sehingga hook tidak menjadi dependency runtime atau mengubah Git config pada server.

Pre-commit menjalankan hanya pemeriksaan staged/cepat:

- `git diff --cached --check` untuk whitespace error/conflict residue;
- blok file `.env`, credential container/key tertentu, database dump/backup, credential JSON, debug output, dan generated/runtime artifact yang dipaksa masuk Git;
- batas 10 MiB untuk file non-media; image/font source yang sah tidak diblok hanya karena ukuran;
- scan added lines untuk private key header, AWS/GitHub-style key/token, bearer token, dan assignment secret/password yang jelas tanpa mencetak nilainya;
- scan unresolved merge marker dan debug-only code pada runtime source;
- `php -l` dan `vendor/bin/pint --test` hanya untuk PHP staged;
- ESLint hanya untuk `resources/js/**/*.js|vue` yang staged.

Vue static check membutuhkan konteks project penuh dan tidak dijalankan saat pre-commit. Ia dijalankan saat pre-push/CI.

Pre-push menjalankan:

```bash
npm run lint
npm run check
npm run build
# PHP syntax pada app/bootstrap/config/public/routes/tests
vendor/bin/pint --test
```

Pre-push sengaja tidak menjalankan full PHPUnit, MySQL/Redis regression, atau browser smoke agar push tetap praktis dan tidak memerlukan credential/service production. Gate tersebut tetap dijalankan oleh GitHub CI.

Menjalankan guard secara manual:

```bash
npm run guard:staged
npm run guard:push
npm run test:hooks
```

`npm run test:hooks` adalah smoke test terkontrol untuk PHP syntax failure, ESLint failure, conflict marker, forbidden `.env`, secret pattern, valid commit, dan entrypoint pre-push. CI memasang hook secara eksplisit hanya untuk smoke ini.

Local hook dapat dilewati dengan `--no-verify` atau `HUSKY=0`; karena itu hook bukan security boundary. GitHub CI tetap authority/gate sebelum merge.

## GitHub Actions

Workflow aktif: `.github/workflows/validate-rebuild.yml`.

Job utama mencakup:

- Laravel-only repository shape;
- PHP 8.4 setup;
- MySQL 8 + Redis;
- Composer validate/install/audit;
- PHP syntax;
- config cache;
- deployment assets;
- migration rollback/reapply;
- frontend build;
- encrypted backup/restore verification;
- PHPUnit;
- browser smoke;
- Pint.

Job `frontend-static` menjalankan npm install, ESLint, Vue SFC static check, dan production build.

Pull request tidak boleh di-merge bila required checks gagal.
