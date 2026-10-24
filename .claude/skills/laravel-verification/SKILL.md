---
name: laravel-verification
description: "Verification loop for Laravel projects: env checks, linting, static analysis, tests with coverage, security scans, and deployment readiness. Use when verifying a Laravel project before merge or deploy — lint, static analysis, tests, coverage, security."
metadata:
  origin: ECC
---

# Laravel Verification Loop

Run before PRs, after major changes, and pre-deploy.

## When to Use

- Before opening a pull request for a Laravel project
- After major refactors or dependency upgrades
- Pre-deployment verification for staging or production
- Running full lint -> test -> security -> deploy readiness pipeline

## How It Works

- Run phases sequentially from environment checks through deployment readiness so each layer builds on the last.
- Environment and Composer checks gate everything else; stop immediately if they fail.
- Linting/static analysis should be clean before running full tests and coverage.
- Security and migration reviews happen after tests so you verify behavior before data or release steps.
- Build/deploy readiness and queue/scheduler checks are final gates; any failure blocks release.

## Phase 1: Environment Checks

For a multi-checkout/worktree setup, verify the test runner's bootstrap, Composer autoload, application source path, and test environment independently before trusting a result. A PHPUnit executable may start from one checkout while `vendor/autoload.php` and Composer classmaps resolve application files from another; compare resolved paths or checksums for the files under test. Before database-backed tests, inspect the resolved test configuration and explicitly confirm the intended local database name; for this user's LFAMILIA local suite it is `lfamilia_hermes`, never assume another DB name from stale notes or copied config. Avoid changing `.env` files just to make one command pass when a command-scoped environment override can select the correct test DB. Never symlink a shared `.env.testing` into a worktree without confirming the final `DB_*` values and ensuring the test command explicitly overrides the database to the designated local test database. Do not run database tests if those checks do not establish the target unambiguously.

```bash
php -v
composer --version
php artisan --version
```

- Verify `.env` is present and required keys exist
- Confirm `APP_DEBUG=false` for production environments
- Confirm `APP_ENV` matches the target deployment (`production`, `staging`)

If using Laravel Sail locally:

```bash
./vendor/bin/sail php -v
./vendor/bin/sail artisan --version
```

**Do not `set -a; . ./.env; set +a` before a test run.** Sourcing the dev `.env` exports `APP_ENV=local` and the session/cookie settings, which turns every POST in the suite into a 419 and buries the real result under hundreds of failures. Pass only the variables the run needs (`SESSION_ENCRYPT=true vendor/bin/phpunit`), or scope the override to the single command.

**Repair a drifted test database before reading a red result.** Errors outnumbering failures, `Base table or view already exists`, `Unknown column`, or a few hundred `E` rows on a suite that was green an hour ago mean the test database is stale or half-wiped — not that the branch is broken. `migrate` cannot fix it (the tables exist without their `migrations` rows). Wipe and rebuild, then re-run before diagnosing code:

```bash
DB_DATABASE=<test_db> php artisan db:wipe --force
DB_DATABASE=<test_db> php artisan migrate --force --no-interaction
```

**Parallel runs need one database per worker, created and migrated by the runner.** Two PHPUnit/paratest processes on one database produce `1412 Table definition has changed` and tests that pass and fail at random. Give each worker `<base>_<n>`, create and migrate it in the runner script before starting paratest, and keep the sequential path byte-identical (no `TEST_TOKEN` → use the configured database) so a plain `phpunit` run still works.

## Phase 1.5: Composer and Autoload

```bash
composer validate
composer dump-autoload -o
```

## Phase 2: Linting and Static Analysis

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

If your project uses Psalm instead of PHPStan:

```bash
vendor/bin/psalm
```

## Phase 3: Tests and Coverage

```bash
php artisan test
```

Coverage (CI):

```bash
XDEBUG_MODE=coverage php artisan test --coverage
```

CI example (format -> static analysis -> tests):

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
XDEBUG_MODE=coverage php artisan test --coverage
```

## Phase 4: Security and Dependency Checks

```bash
composer audit
```

## Phase 5: Database and Migrations

```bash
php artisan migrate --pretend
php artisan migrate:status
```

- Review destructive migrations carefully
- Ensure migration filenames follow `Y_m_d_His_*` (e.g., `2025_03_14_154210_create_orders_table.php`) and describe the change clearly
- Ensure rollbacks are possible
- Verify `down()` methods and avoid irreversible data loss without explicit backups

## Phase 6: Build and Deployment Readiness

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

- Ensure cache warmups succeed in production configuration
- Verify queue workers and scheduler are configured
- Confirm `storage/` and `bootstrap/cache/` are writable in the target environment

## Phase 7: Queue and Scheduler Checks

```bash
php artisan schedule:list
php artisan queue:failed
```

If Horizon is used:

```bash
php artisan horizon:status
```

If `queue:monitor` is available, use it to check backlog without processing jobs:

```bash
php artisan queue:monitor default --max=100
```

Active verification (staging only): dispatch a no-op job to a dedicated queue and run a single worker to process it (ensure a non-`sync` queue connection is configured).

```bash
php artisan tinker --execute="dispatch((new App\\Jobs\\QueueHealthcheck())->onQueue('healthcheck'))"
php artisan queue:work --once --queue=healthcheck
```

Verify the job produced the expected side effect (log entry, healthcheck table row, or metric).

Only run this on non-production environments where processing a test job is safe.

## Examples

Minimal flow:

```bash
php -v
composer --version
php artisan --version
composer validate
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
composer audit
php artisan migrate --pretend
php artisan config:cache
php artisan queue:failed
```

CI-style pipeline:

```bash
composer validate
composer dump-autoload -o
vendor/bin/pint --test
vendor/bin/phpstan analyse
XDEBUG_MODE=coverage php artisan test --coverage
composer audit
php artisan migrate --pretend
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan schedule:list
```
