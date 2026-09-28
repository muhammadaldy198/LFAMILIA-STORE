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
- [x] Port customer authentication and Admin/Staff RBAC/session compatibility.
- [x] Port public products, availability/Max Price, nickname checks, checkout pricing, and promotion quoting.
- [x] Port verified customer/guest review submission and Staff moderation.
- [x] Port customer support tickets/refund ownership checks and Staff replies.
- [x] Port storefront settings, banners, popups, news, FAQ, and category CRUD with role boundaries.
- [x] Port Admin product/package/notices CRUD while keeping DigiFlazz pricing authority server-owned.
- [x] Port customer/member balances, tier settings, safe account cleanup, promo CRUD, and DigiFlazz pricing/monitor administration.
- [x] Port wallet checkout debit path with row locking, server-side price, idempotency, and promo capacity checks.
- [x] Port external wallet top-up creation with Admin-selected gateway, customer fee, and idempotency.
- [x] Port wallet top-up/provider reconciliation scheduler with safe-window handling and success notifications.
- [x] Port Midtrans Snap and DOKU callback signature/amount/idempotency state transitions.
- [x] Port Midtrans Snap / DOKU Checkout payment creation with server-side price, customer fee, idempotency, and uncertain-create handling.
- [x] Port order-status Midtrans/DOKU reconciliation polling with amount checks and paid-state recovery.
- [x] Port Digiflazz paid-only fulfillment, Max Price/availability recheck, bounded retries, multi-unit handling, and signed callback processing.
- [x] Port Resend password-reset delivery and Google Identity login/linking with mandatory phone collection.
- [x] Port Resend transaction-success email notifications with idempotent order delivery.
- [ ] Port phone OTP delivery only after the final non-WhatsApp/WhatsApp provider choice is confirmed.
- [x] Port Admin / Staff / Super Admin APIs: session/RBAC, dashboard, team, orders, payment routing/channels, products, reviews, support, customer/member balances, account cleanup, promotions, DigiFlazz pricing/monitor, and UI-adjacent operational endpoints are native Laravel.
- [x] Port customer frontend and panel UI to the VPS runtime; browser `/api/*` traffic is served by Laravel and panel SSR validates the Laravel session internally.
- [x] Add minute-level production reconciliation scheduler with overlap protection.
- [x] Add final VPS systemd process definitions for queue worker and scheduler.
- [x] Deploy to VPS without DNS cutover; Node frontend, Laravel/PHP-FPM, MariaDB, and Nginx are running with loopback-only internal services.
- [x] Import the current production D1 snapshot and reconcile; the final export checksum matched the imported snapshot.
- [ ] Re-enter/validate provider credentials on the VPS after the credential step is resumed.
- [ ] Cut over `lfamiliastore.my.id` only after provider readiness and final validation.

## VPS readiness notes

- Public-origin Nginx is prepared for `lfamiliastore.my.id` and `www.lfamiliastore.my.id` with a Cloudflare Origin certificate.
- The public origin only accepts Cloudflare edge source ranges plus localhost; Node (`127.0.0.1:3000`), Laravel (`127.0.0.1:8080`), and MariaDB (`127.0.0.1:3306`) are not exposed directly.
- Cloudflare Access still protects `/admin*` and `/api/admin*`; Staff remains on the separate password panel path.
- Daily MariaDB backup is installed on the VPS with 7-day local retention, SHA-256 sidecars, gzip validation, and a successful restore verification.
- Queue worker and scheduler are intentionally disabled until provider credentials are resumed, so a reboot cannot trigger provider-facing background work prematurely.
- A temporary `trycloudflare.com` preview is used only for pre-cutover smoke tests and is not part of the final architecture.

## Import safety

The production import is intentionally guarded and cannot run accidentally:

`php artisan lfamilia:import-d1 /absolute/path/export.sql --replace --confirm=IMPORT_D1_TO_MARIADB`

Run it only on the prepared VPS after a fresh D1 export has been copied to the server. The command never reads credentials from Git; it uses the active Laravel database connection from the server environment.

## Cutover hold

DNS remains on the existing Cloudflare Worker until provider credentials are re-entered on the VPS and verified. Queue and scheduler services intentionally remain stopped while provider integrations are deferred. The VPS origin is otherwise prepared for cutover, including TLS for the apex and `www` hostnames, canonical `www` redirect, Cloudflare-only origin access, frontend/API smoke tests, and regression validation.


### VPS edge validation (non-provider)

- A temporary proxied Cloudflare hostname was pointed at the VPS with a short-lived Origin CA certificate.
- Cloudflare edge -> Nginx -> Vinext/Laravel returned HTTP 200 for homepage, health, catalog/storefront/content, and panel login pages.
- Direct origin access by public IP returned HTTP 403, confirming the Cloudflare-only origin allowlist.
- The temporary DNS record, certificate, and Nginx smoke vhost were removed after validation.
- Cloudflare SSL is Full (strict), TLS 1.3 is enabled, minimum TLS is 1.2, and Admin Access destinations cover both `/admin/*` and `/api/admin/*`; Staff remains outside Access as intended.
- The existing Cloudflare rate-limit rule already targets the Laravel checkout/auth paths, including `/api/payments/auto/create` and `/api/payments/wallet/create`.
- Daily MariaDB backup is enabled, a generated dump passed checksum verification, and a restore drill matched order/customer/package/media row counts plus paid-order totals.
