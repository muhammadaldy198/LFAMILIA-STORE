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
- [x] CI installs Laravel, checks PHP syntax, migrates the full schema on SQLite, and runs tests.
- [x] Current customer/auth, catalog, order, wallet, content, payment-config, integration, security, and Digiflazz tables translated to Laravel migrations.
- [x] MariaDB Digiflazz maintenance guard preserved as a database trigger.
- [ ] Build D1 SQL export -> MariaDB importer with row-count and financial reconciliation.
- [ ] Port authentication and RBAC.
- [ ] Port products, pricing, nickname checks, checkout, promotions, reviews.
- [ ] Port wallet and top-up ledger.
- [ ] Port Midtrans Snap and DOKU callbacks.
- [ ] Port Digiflazz fulfillment and callback processing.
- [ ] Port Resend email and Google OAuth.
- [ ] Port Admin / Staff / Super Admin APIs.
- [ ] Port customer frontend and panel UI.
- [ ] Add production queue worker and scheduler jobs.
- [ ] Deploy to VPS without DNS cutover.
- [ ] Import production data and reconcile.
- [ ] Cut over `lfamiliastore.my.id` only after validation.
