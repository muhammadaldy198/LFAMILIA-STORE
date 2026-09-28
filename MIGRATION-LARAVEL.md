# LFAMILIA STORE — Laravel VPS migration

Branch: `migration/laravel-vps-production`

## Goal

Move LFAMILIA STORE production from Cloudflare Worker + D1 to Laravel + MariaDB on the new VPS without changing customer-visible behavior or weakening payment/security controls.

## Non-negotiable rules

1. No API keys, passwords, database credentials, encryption keys, provider secrets, provider URLs, or environment-specific values are committed as production values.
2. Existing production remains the rollback source until the Laravel runtime passes full regression testing.
3. Payment redirect pages never mark an order paid. Only validated server callbacks may transition payment state.
4. Midtrans/DOKU callbacks must remain signature-validated, amount-validated, idempotent, and monotonic.
5. Digiflazz fulfillment happens only after a validated paid state and remains idempotent.
6. Wallet balances are changed only inside audited database transactions.
7. Super Admin / Admin / Staff permissions are preserved.
8. Database migration is reconciled before DNS cutover.

## Migration stages

- [x] Create isolated migration branch.
- [x] Add Laravel 13 production scaffold.
- [x] Remove environment/provider secrets and URLs from committed runtime configuration.
- [x] Add queue foundation and Laravel CI smoke test.
- [ ] Translate D1 schema into MariaDB migrations.
- [ ] Build D1 export -> MariaDB importer with row-count and financial reconciliation.
- [ ] Port authentication and RBAC.
- [ ] Port products, pricing, nickname checks, checkout, promotions, reviews.
- [ ] Port wallet and top-up ledger.
- [ ] Port Midtrans Snap and DOKU callbacks.
- [ ] Port Digiflazz fulfillment and callback processing.
- [ ] Port Resend email and Google OAuth.
- [ ] Port Admin / Staff / Super Admin APIs.
- [ ] Port customer frontend and panel UI.
- [ ] Add queue worker and scheduler jobs.
- [ ] Add integration and regression tests.
- [ ] Deploy to VPS without DNS cutover.
- [ ] Import production data and run reconciliation.
- [ ] Cut over `lfamiliastore.my.id` only after validation.
