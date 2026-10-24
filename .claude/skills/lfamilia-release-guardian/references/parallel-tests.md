# Parallel PHPUnit (paratest) — local only

Goal: a full suite in ~5 minutes instead of ~7, with zero cross-worker
database collisions. **Local infrastructure only** — it does not go into the
repo or CI unless the owner explicitly authorizes it. Keep it on a separate
branch/worktree from the product commits.

## Why one database is not enough

`phpunit.xml` pins a single `DB_DATABASE`. Two workers then share it, one
migrates/resets while the other queries, and MySQL raises
`SQLSTATE[HY000]: 1412 Table definition has changed` on dozens of unrelated
tests. That is self-inflicted concurrency, not a product regression — confirm
with `ps -eo pid,cmd | grep "[p]hpunit"` before debugging code.

## Setup

1. `composer require --dev brianium/paratest` (needs `^7.8` for PHPUnit 11).
2. `tests/bootstrap.php` — before Laravel boots, when `TEST_TOKEN` is set,
   rewrite `DB_DATABASE` to `<base>_<TEST_TOKEN>`. Without `TEST_TOKEN` the
   file does nothing, so sequential runs keep the value from `phpunit.xml`.
3. `tests/phpunit-bootstrap.php` — thin wrapper that requires the above, then
   the normal `vendor/autoload.php`. Point `phpunit.xml`'s `bootstrap=` at it.
4. `scripts/test-parallel.sh` — for each worker token: `CREATE DATABASE IF NOT
   EXISTS <base>_<token>`, run `migrate --force` with that DB, then exec
   `vendor/bin/paratest -p <N>`.

## Pitfalls that cost real time

- **A fresh worktree has no frontend build.** Every Inertia page test fails with
  `ViteManifestNotFoundException` / `Vite manifest not found`. That is 143
  failures that look like a broken branch. Copy or build `public/build` (and
  symlink `node_modules`) before interpreting any result.
- **`composer require` can trip `package:discover`** when the local `.env`
  violates a production guard (`SESSION_SECURE_COOKIE must be true`). Fix the
  env for that command, not by editing the guard.
- **Never `source .env` before the run.** Exporting the app env overrides the
  test env and produces ~300 CSRF `419` failures. Pass only extra flags inline:
  `SESSION_ENCRYPT=true vendor/bin/phpunit`.
- **Run the sequential suite once after the change** to prove the infra did not
  break the default path. Expect identical test/assertion counts; only the time
  differs.

## What to measure and report

Start time, end time, tests, assertions, pass/fail, worker count, and the exact
command. Baseline measured here: sequential 540 tests / 5,445 assertions in
6:34–7:19; 2 workers in 5:04 with 2 skipped. Raise the worker count only after
measuring an actual improvement.
