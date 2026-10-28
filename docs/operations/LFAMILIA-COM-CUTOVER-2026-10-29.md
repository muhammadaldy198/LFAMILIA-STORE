# LFAMILIA.COM — application/domain migration handover (2026-10-29)

## Purpose / verified state

The DNS/Cloudflare cutover is operational: `lfamilia.com`, `www.lfamilia.com`, and `panel.lfamilia.com` all point to the new proxied Cloudflare Tunnel. Public homepage and `/health/ready` were reachable; Cloudflare Access protects `/admin` and the panel. Full (strict) TLS is enabled; origin is managed by BETOL.

This PR only updates **code defaults, templates, deployment examples, active documentation, and tests**. It does not change actual VPS `.env`, database-stored settings, provider dashboards, Cloudflare DNS, or production transactions.

## Single-domain deployment — before enabling real payments (BETOL + owner)

- On the VPS, verify `APP_URL=https://lfamilia.com`, `APP_TRUSTED_HOSTS=lfamilia.com,www.lfamilia.com`, `SESSION_DOMAIN=.lfamilia.com` and `APP_DEBUG=false`. Preserve existing `APP_KEY`, users, and order data.
- In admin Settings, change `store.email` to `support@lfamilia.com` if appropriate; check all customer-facing support and footer URLs. These persisted settings were restored from the old server and are not rewritten by this PR.
- In Admin Integrations → Resend, update `from_email` to an authorized sender on the now-verified `lfamilia.com`. Test transactional email delivery and password-reset links. Resend credentials remain encrypted in the DB.
- At vendor dashboards set:
  - Digiflazz webhook: `https://lfamilia.com/api/fulfillment/digiflazz/webhook`
  - Midtrans notifications: `https://lfamilia.com/api/payments/midtrans/notification`
  - DOKU notifications: `https://lfamilia.com/api/payments/doku/notification`
  - Google OAuth redirect allowlist: `https://lfamilia.com/auth/google/callback`
  - Cloudflare Turnstile widget: allow `lfamilia.com`. **Also update the selected active Turnstile environment profile in Admin Integrations → Turnstile: `allowed_hostnames` must include `lfamilia.com`, and must not retain obsolete hostnames. Updating Cloudflare alone is insufficient.** Check both test and production profiles if their use is planned, without switching environment automatically.
- Validate webhook identity/signatures and price/order state with **Midtrans sandbox, Digiflazz TEST**, and DOKU's configured non-live test mode. Do not enable real transactions just because DNS works.
- Resolve R2 off-site backup authentication. Validate an encrypted upload, retrieval, SHA-256 and restore verification; bucket `lfamilia-production-backups` was empty at migration check.
- Rotate any credential leaked into a public chat and ensure cloudflared on the VPS uses the rotated token (the token itself must not enter Git).

## Operational constraints

- **Owner decision: one domain only.** All website/customer links, login, reset-password URLs, callbacks, and provider dashboards MUST target `lfamilia.com`. No transitional host allowlist, redirects, fallback URL, or callback compatibility for prior addresses; do not enable live money flows before vendor dashboard URLs are updated and callbacks verified.
- Cloudflare Tunnel ingress: origin site local `443`, CloudPanel local `8443`, both verified using Origin CA. No public 443/8443.
- Health check for local ingress can use `http://127.0.0.1:8080/health/ready` with Host header `lfamilia.com`; public check uses `https://lfamilia.com/health/ready`.
- The payment gateways remain independent. This migration does **not** change refund behavior, price, fees, provider mapping, or SKU fallback.

## Repo release quality gate

Work in one PR, run PHP tests/Pint, frontend build and required GitHub CI; request **`@codex review`**, resolve findings against the owner's final single-domain decision, and merge only after the checks are green. Deploy only through BETOL after the owner approves release of the reviewed code.
