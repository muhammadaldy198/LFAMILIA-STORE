# LFAMILIA STORE — Laravel VPS migration

Active VPS branch: `migration/laravel-vps-final`

## Goal

Run the prelaunch storefront on Laravel + MariaDB on the VPS, with Cloudflare as DNS, TLS, Access, and edge proxy.

## Rules

1. No live secrets are committed to Git. Provider/payment credentials and provider runtime settings are managed from **Super Admin → Integrasi** and stored encrypted in MariaDB; only bootstrap/infrastructure secrets such as `APP_KEY`, database credentials, and `INTEGRATION_ENCRYPTION_KEY` remain server-side.
2. The former Worker/D1 implementation remains in Git history for migration reference; it is no longer the public runtime.
3. Payment redirects never mark an order paid; only validated server callbacks may transition payment state.
4. Midtrans/DOKU callbacks must remain signature-validated, amount-validated, idempotent, and monotonic.
5. Digiflazz fulfillment happens only after a validated paid state and remains idempotent.
6. Wallet mutations use database transactions and immutable ledger references.
7. Super Admin / Admin / Staff boundaries are preserved.
8. The imported D1 snapshot and MariaDB reconciliation are recorded before provider activation.

## Migration status

- [x] Isolated migration branch.
- [x] Laravel 13 / PHP 8.3 foundation.
- [x] MariaDB runtime configuration.
- [x] Provider/payment credentials moved to encrypted Admin → Integrasi profiles; provider secrets are no longer runtime-configured from `.env`.
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
- [x] Laravel/MariaDB migration runtime was validated on the VPS without DNS cutover.
- [ ] Decide whether CloudPanel installation is still needed before launch; current Nginx/PHP/MariaDB stack serves the prelaunch site.
- [x] Import the current production D1 snapshot and reconcile; the final export checksum matched the imported snapshot.
- [ ] Re-enter/validate provider credentials on the VPS after the credential step is resumed.
- [x] Route `lfamiliastore.my.id` and `www` through Cloudflare to the VPS; public storefront, health, products, and panel login respond.
- [x] Remove the old Worker routes, Worker cron schedules, and two relay DNS records.
- [ ] Enter Digiflazz/payment credentials, verify callbacks and fulfillment, then enable queue/scheduler.

## VPS readiness notes

- Public-origin Nginx is prepared for `lfamiliastore.my.id` and `www.lfamiliastore.my.id` with a Cloudflare Origin certificate.
- The public origin only accepts Cloudflare edge source ranges plus localhost; Node (`127.0.0.1:3000`), Laravel (`127.0.0.1:8080`), and MariaDB (`127.0.0.1:3306`) are not exposed directly.
- Cloudflare Access still protects `/admin*` and `/api/admin*`; Staff remains on the separate password panel path.
- Daily MariaDB backup is installed on the VPS with 7-day local retention, SHA-256 sidecars, gzip validation, and a successful restore verification.
- Queue worker and scheduler are intentionally disabled until provider credentials are resumed, so a reboot cannot trigger provider-facing background work prematurely.
- The apex and `www` now resolve through Cloudflare to the VPS; temporary preview is no longer used.

## Import safety

The production import is intentionally guarded and cannot run accidentally:

`php artisan lfamilia:import-d1 /absolute/path/export.sql --replace --confirm=IMPORT_D1_TO_MARIADB`

Run it only on the prepared VPS after a fresh D1 export has been copied to the server. The command never reads credentials from Git; it uses the active Laravel database connection from the server environment.

## Current prelaunch routing and relay retirement

Cloudflare proxies the public apex and `www` to the VPS. The old `lfamilia-store` Worker has no public route or cron schedule, and the Digiflazz/Midtrans relay DNS records are removed. The historical D1 `relay` profile and migrations remain intact for audit history; neither the Laravel runtime nor Admin → Integrasi uses relay.

Laravel sends Digiflazz transaction, pricelist, and balance requests directly from VPS egress IP `202.155.17.191` to `https://api.digiflazz.com`. Network reachability and source IP were checked, but authenticated transactions, callback delivery, and fulfillment still require credentials and Digiflazz IP allowlisting. Queue and scheduler stay stopped until that validation.

### VPS edge validation (non-provider)

- A temporary proxied Cloudflare hostname was pointed at the VPS with a short-lived Origin CA certificate.
- Cloudflare edge -> Nginx -> Vinext/Laravel returned HTTP 200 for homepage, health, catalog/storefront/content, and panel login pages.
- Direct origin access by public IP returned HTTP 403, confirming the Cloudflare-only origin allowlist.
- The temporary DNS record, certificate, and Nginx smoke vhost were removed after validation.
- Cloudflare SSL is Full (strict), TLS 1.3 is enabled, minimum TLS is 1.2, and Admin Access destinations cover both `/admin/*` and `/api/admin/*`; Staff remains outside Access as intended.
- The existing Cloudflare rate-limit rule already targets the Laravel checkout/auth paths, including `/api/payments/auto/create` and `/api/payments/wallet/create`.
- Daily MariaDB backup is enabled, a generated dump passed checksum verification, and a restore drill matched order/customer/package/media row counts plus paid-order totals.


### Additional production hardening

- UFW is active with only SSH/HTTP/HTTPS exposed; Node :3000, MariaDB :3306, and Laravel :8080 remain loopback-only.
- Fail2Ban protects SSH and automatically bans repeated password attacks. The initial audit observed heavy internet brute-force traffic, so the highest-volume abusive IPs were also banned immediately.
- Systemd service hardening reduced the exposure score of the web/queue/scheduler units while preserving their required network access.
- A read-only health timer checks health/system-status/products/storefront every five minutes.
- MariaDB slow-query logging is enabled at a 1-second threshold and rotated daily.
- MariaDB backups are created daily and the newest backup is restored into a temporary database every week for automated verification.
- Production PHP upload limits now exceed the application 5 MiB image limit without exceeding Nginx's 8 MiB request limit.
- Laravel Composer dependencies are pinned by `composer.lock`; npm/composer security audits reported no known vulnerabilities at this checkpoint.
- API CORS is restricted to the configured storefront origin instead of wildcard browser origins.
- Cloudflare rate limiting now covers customer auth/password-reset, nickname, order lookup, promo quote/reviews, support/top-up/payment creation, and Admin/Staff login mutations.
