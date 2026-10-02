# Admin, Permissions, Notifications & Integrations (M9)

M9 turns the previously milestone-specific Admin pages into one operational control plane. It does not deploy production credentials, alter Cloudflare/VPS, or perform M10 security enforcement.

## Roles

Administrative roles in v1 are only:

- SUPER_ADMIN: unrestricted Admin panel access, integration credentials, wallet adjustment, membership override, Admin accounts, audit and health.
- ADMIN: access is controlled by an explicit permission list.

Customer membership tiers BASIC through MAFIA are never interpreted as administrative roles. STAFF is not created or accepted by v1 authentication.

Existing ADMIN records are migrated to the operational permission set so M9 does not unexpectedly lock out a pre-production Admin. New/edited ADMIN accounts always retain Dashboard access.

## Admin navigation

The Admin panel exposes the PRD menu:

1. Dashboard
2. Pesanan
3. Produk
4. Manual
5. Banner & Konten
6. Digiflazz
7. Validasi Akun
8. Provider
9. Pembayaran
10. Pelanggan
11. Promo
12. Layanan Pelanggan
13. Laporan
14. Admin & Akses
15. Pengaturan
16. Integrasi
17. System Health
18. Audit Log

Notifications are available from the Admin header when the current Admin has notification permission.

All listed routes are backed by working data/actions; there are no placeholder menu links. Existing Catalog, Payment and Fulfillment workspaces are retained and wrapped by the common Admin shell.

## Permissions

ADMIN permissions are stored as JSON on admin_users and checked server-side with admin.permission middleware. Hiding a link in Vue is not treated as authorization.

SUPER_ADMIN-only operations include:

- Admin & Akses
- Integrasi, secret reveal and connection tests
- System Health
- Audit Log
- payment gateway/channel/routing configuration
- customer wallet adjustment
- manual customer membership override

Operational permissions cover order view, catalog/content, fulfillment, providers, payment operations, customers, vouchers, support, reports, settings and notifications. Settings contains non-secret store/contact/merchant identity and membership configuration only; branding media/content remains in Banner & Konten and credentials remain in Integrasi.

The final active SUPER_ADMIN cannot be disabled or demoted. The account currently being used also cannot deactivate, demote, or delete itself from the same session. Other Admin accounts may be deleted by SUPER_ADMIN; the deletion is audited and password material is never written to Audit Log.

## Integration Settings

Credentials remain in integration_credentials.config_ciphertext using Laravel encrypted casts. Supported M9 records:

- digiflazz
- kokinpay
- midtrans
- doku
- resend
- google_oauth
- telegram
- discord
- turnstile

Turnstile keys may be stored here, but Turnstile enforcement belongs to M10.

Secret fields are never included in normal Inertia props. The UI only receives whether a secret is configured. A controlled reveal endpoint is SUPER_ADMIN-only and writes integration.secret.revealed to Audit Log.

Blank secret fields on update preserve the existing encrypted value. Unknown legacy profile metadata is also preserved so a normal save does not destroy older migration/provider options. Operational URLs must use HTTPS; endpoint paths, Admin email lists, and Turnstile hostnames are validated server-side.

The workspace shows active/verified/attention summaries, required-field completeness, persisted health, and the last connection-test time. Secret values are never part of the normal Inertia payload.

## Connection checks

M9 provides a non-transactional Test Koneksi action.

- Digiflazz: check-deposit endpoint using the configured username/API key.
- Midtrans: transaction-status endpoint with a deliberately nonexistent LFAMILIA reference; authentication rejection is treated as failure.
- Resend: domain list endpoint.
- Telegram: getMe.
- Discord: webhook GET.
- Integrations without a safe universal credential-only probe are reported Degraded until exercised by their real flow.

The latest result is stored as non-secret system health state. Changing an integration resets its health to Degraded (or Not Configured when disabled).

## System Health

System Health is a dedicated Super Admin workspace. It reads application state only and does not execute payment/provider transactions or run provider probes automatically.

It shows Laravel/PHP, MySQL, Redis, storage capacity, queue worker heartbeat, scheduler heartbeat, failed job count, stale fulfillment reconciliation count, all nine integration states, payment gateway maintenance state, and non-secret runtime configuration. Invalid/stale heartbeat values degrade safely instead of breaking the page. Integration messages are normalized before render so saved provider messages and credentials are not exposed.

## Notifications

Business events create an admin_notifications record first. External delivery is queued after commit.

Covered events include:

- new order
- Manual QRIS waiting
- payment verified
- late verified payment
- refund
- manual fulfillment waiting
- fulfillment pending/unknown
- provider confirmed failure
- order success/manual success/manual failure
- new support ticket
- stale fulfillment requiring reconciliation
- integration connection failure

Read state is per Admin through admin_notification_reads; one Admin reading an event does not mark it read for another Admin.

External channels:

- Telegram bot
- Discord webhook
- Resend email to configured Admin recipients

notification_deliveries is idempotent per notification+channel. Successful channels are skipped on retry; failed channels can be retried by the queue job.

## Transactional email

Resend is also used for queued customer email:

- email verification
- password reset
- payment success
- transaction success
- refund
- support ticket acknowledgement

The Resend API key and sender address are read only from the encrypted Integrasi record.

## Audit

Audit Log is a dedicated read-only shadcn workspace available only to Super Admin. It provides server-side search/filtering, actor identity, role/action/target/date filters, pagination, correlation ID, IP address, user agent, and before/after change detail.

Sensitive actions use Audit Log with actor, role, action, target, IP, user agent and correlation ID. Audit payloads redact password/key/secret/token/signature material when written and are sanitized again when read so legacy rows cannot bypass current redaction rules. Legacy camelCase or prefixed key names such as `apiKey` and `midtrans_client_key` are redacted too. Scalar text payloads are not rendered back to the browser.

Credential reveal records which field was revealed, never the secret value itself. Audit rows have no edit/delete action in the Admin panel.

## Customer and commercial operations

M9 adds real operational pages for:

- order listing
- provider activation status
- customer wallet/member management
- voucher CRUD
- support ticket workflow
- reports
- store/support settings
- membership requirement/benefit JSON configuration

Wallet adjustment uses row locking, prevents negative balance, writes wallet_ledger, and requires an idempotency key.

## System Health

System Health reports:

- Laravel
- MySQL
- Redis
- queue configuration
- scheduler heartbeat
- storage capacity
- Digiflazz
- KokinPay
- Midtrans
- DOKU
- Resend

Supported statuses are Healthy, Degraded, Down, Not Configured and Maintenance.

The application writes a scheduler heartbeat every minute. Runtime queue-worker deployment/verification remains M12.

## Milestone boundaries

M10 still owns rate-limit expansion, Turnstile enforcement, security headers/secret hardening and the dedicated security pass.

M11 owns the full cross-system functional/security/browser test campaign.

M12 owns VPS/CloudPanel deployment, production queue workers, scheduler process, SSL, Cloudflare, backup and restore validation.
