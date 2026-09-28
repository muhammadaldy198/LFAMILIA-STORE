# LFAMILIA STORE — Laravel VPS migration

Branch: `migration/laravel-vps-production`

## Goal

Move LFAMILIA STORE production from Cloudflare Worker + D1 to Laravel + MariaDB on the VPS without changing customer-visible behavior or weakening payment/security controls.

## Rules

1. No API keys, passwords, database credentials, encryption keys, provider secrets, provider URLs, or production-only values are committed as live configuration.
2. Existing production remains the rollback source until Laravel passes full regression testing.
3. Payment redirects never mark an order paid; only validated server callbacks may transition payment state.
4. Midtrans/DOKU callbacks must remain signature-validated, amount-validated, idempotent, and monotonic.
5. Digiflazz fulfillment happens only after a validated paid state and remains idempotent.
6. Wallet mutations use database transactions and immutable ledger references.
7. Super Admin / Admin / Staff boundaries are preserved.
8. D1 -> MariaDB migration is reconciled before DNS cutover.

## Migration status

- [x] Isolated migration branch.
- [x] Laravel 13 / PHP 8.3 foundation.
- [x] MariaDB runtime configuration.
- [x] Secrets and provider endpoints moved to environment-only configuration.
- [x] Current LFAMILIA D1 schema translated to Laravel migrations.
- [x] MariaDB Digiflazz maintenance guard preserved.
- [x] CI validates the schema on SQLite and a real MariaDB 11.4 service.
- [x] D1 snapshot importer accepts a D1 SQL export or SQLite snapshot.
- [x] Import requires an explicit destructive confirmation and reconciles table row counts.
- [x] Import reconciles paid-order totals, wallet credit/debit totals, and aggregate customer balances.
- [ ] Port authentication and RBAC.
- [ ] Port products, pricing, nickname checks, checkout, promotions, reviews.
- [ ] Port wallet and top-up ledger.
- [x] Port Midtrans Snap and DOKU callback signature/amount/idempotency state transitions.
- [ ] Port Midtrans/DOKU payment creation and reconciliation polling.
- [ ] Port Digiflazz fulfillment and callback processing.
- [ ] Port Resend email and Google OAuth.
- [ ] Port Admin / Staff / Super Admin APIs.
- [ ] Port customer frontend and panel UI.
- [ ] Add production queue worker and scheduler jobs.
- [ ] Deploy to VPS without DNS cutover.
- [ ] Import the current production D1 snapshot and reconcile.
- [ ] Cut over `lfamiliastore.my.id` only after validation.

## Import safety

The production import is intentionally guarded and cannot run accidentally:

`php artisan lfamilia:import-d1 /absolute/path/export.sql --replace --confirm=IMPORT_D1_TO_MARIADB`

Run it only on the prepared VPS after a fresh D1 export has been copied to the server. The command never reads credentials from Git; it uses the active Laravel database connection from the server environment.
