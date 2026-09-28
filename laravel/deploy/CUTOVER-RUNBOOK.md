# LFAMILIA production cutover / rollback runbook

This runbook covers the **final** switch from the existing Cloudflare Worker + D1 production path to the VPS runtime. It intentionally contains no API keys or provider secrets.

## Current hold

Do **not** cut over while provider credentials are deferred. The final VPS must first be rebuilt as **fresh Ubuntu 24.04 + CloudPanel**; the rehearsal Nginx/PHP/MariaDB installation is not the final production base. Queue and scheduler must remain stopped until Midtrans/DOKU/DigiFlazz and the other required integrations have been entered through **Super Admin → Integrasi** and validated on the CloudPanel-managed VPS.

Before any production switch:

```bash
cd /var/www/lfamilia-store
./laravel/deploy/vps-cutover-preflight.sh
```

The non-provider section must pass. Provider readiness must then be validated separately.

## Final cutover order

1. Put the old Worker checkout/order-writing path into a short maintenance/write-freeze.
2. Export a final D1 snapshot.
3. Import/reconcile only if that final snapshot differs from the already imported production snapshot.
4. Run a fresh MariaDB backup and checksum verification.
5. Validate provider credentials on the VPS without exposing them in logs or chat.
6. Run payment/nickname/fulfillment smoke tests using non-destructive or sandbox paths where available.
7. Start and enable `lfamilia-queue` and `lfamilia-scheduler`.
8. Point the proxied apex DNS record at the VPS origin and remove only the store Worker routes for the apex and `www`.
9. Do **not** alter `tools.lfamiliastore.my.id`.
10. Smoke-test the public domain through Cloudflare: homepage, catalog, account auth, order search, Admin Access, Staff login, checkout creation, and callbacks.
11. Keep Worker + D1 intact as rollback during the observation window.

## Rollback

If the VPS fails after the switch:

1. Stop `lfamilia-queue` and `lfamilia-scheduler` to prevent additional fulfillment/reconciliation.
2. Restore the previous apex DNS target and the two original Worker routes.
3. Confirm the public domain is again served by the Worker.
4. Reconcile any writes that occurred on the VPS during the failed cutover window before attempting another switch.
5. Keep the MariaDB backup and logs for investigation.

The pre-cutover Cloudflare resource IDs and old Worker deployment identifiers are recorded **only on the VPS** at:

`/var/backups/lfamilia/cutover-rollback-state.txt`

That file is operational state, not source code, and must not be committed.

## Safety invariants

- Never infer payment success from a frontend redirect.
- Never start fulfillment from an unpaid order.
- Never let customer input override price, SKU authority, Max Price, gateway signatures, or promotion reservations.
- Never expose provider credentials in frontend payloads, logs, Git, or support screenshots.
- Keep MariaDB bound to loopback.
- Keep Node/Vinext and Laravel internal listeners on loopback.
- Keep the public origin behind Cloudflare and preserve the direct-origin deny rule.
