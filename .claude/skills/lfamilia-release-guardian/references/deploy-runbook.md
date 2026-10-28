# LFAMILIA STORE — production deployment guard (new VPS)

> Revised for the 2026-10-29 migration. Only the new VPS and `lfamilia.com` are supported. Do not use previous server targets, configuration, or customer-facing domains.

## Verified destination and separation of duties

- Production domain: `https://lfamilia.com`; application path: `/home/lfamilia/htdocs/lfamilia.com/repo/rebuild`.
- VPS hostname: `server.lfamilia.com`, Ubuntu 24.04; server owner/BETOL controls SSH and deployments.
- Public web is Cloudflare Tunnel → local TLS `443`; panel `panel.lfamilia.com` → local TLS `8443`. No inbound 80/443/8443 firewall openings.
- Database, Redis, queue, scheduler, and origin TLS are managed by BETOL.
- Repo working tree is not the production source of truth until BETOL deploys a CI-green merged commit.

## Guardrails

1. Work from latest `origin/main` into a new branch/PR. **Never push directly to main**.
2. Inspect `git status`, current branch, current commit, and `git diff` before any writes.
3. Require green tests, Pint, frontend build, and CI. Ask `@codex review` on the PR and address all actionable comments.
4. Never publish real credentials, `.env`, Cloudflare Tunnel token, CA private key, backup encryption passphrase, or origin SSH address/IP in the repo.
5. Never change payment provider modes (Midtrans sandbox, Digiflazz TEST), fees, seller/SKU, refunds, or database contents as a side effect of domain migration.
6. Production cutover/change requires explicit owner authorization; coordinate rollout with BETOL only after tests/review.
7. Ensure fresh encrypted backup and a working restore procedure before running migrations.

## Read-only VPS probes (BETOL only)

Set `LFAMILIA_SSH_TARGET` to your already-approved SSH target (for example an entry in your private SSH config); do not add the literal target to git.

```bash
: "${LFAMILIA_SSH_TARGET:?Set a verified private SSH target}"
ssh -o BatchMode=yes -o ConnectTimeout=15 "${LFAMILIA_SSH_TARGET}" 'hostname; date -u; whoami'
```

On the actual VPS, inspect (no deploy):

```bash
cd /home/lfamilia/htdocs/lfamilia.com/repo/rebuild
git status -sb
git log --oneline -3
systemctl is-active nginx clp-nginx mysql redis-server lfamilia-queue lfamilia-scheduler
curl -sS -H 'Host: lfamilia.com' http://127.0.0.1:8080/health/ready
```

Run public health separately:

```bash
curl -fsS https://lfamilia.com/health/ready
```

Only BETOL may configure origin/vhost, enable or restart services, change `.env`, or validate backup restore.

## Migration acceptance (pending unless proven)

- `APP_URL=https://lfamilia.com`, `APP_TRUSTED_HOSTS=lfamilia.com,www.lfamilia.com`, `SESSION_DOMAIN=.lfamilia.com` set on VPS. Do not add retired hostnames to the trusted-host allowlist.
- `store.email` and active Resend `from_email` updated in admin, with real test email.
- Provider notification URLs and Google OAuth redirect updated **in vendor dashboards** to `https://lfamilia.com` (mandatory, no fallback).
- **Turnstile**: Cloudflare widget hostname set to `lfamilia.com`, then update **active** Admin Integrations → Turnstile `allowed_hostnames` to include `lfamilia.com`; remove retired hostname entries and test login, register, forgot-password. Do not blindly enable production profile.
- Sandbox payment and TEST fulfillment completed end-to-end without spending real funds.
- Encrypted off-site backup to R2 plus readback/restore-verify **successful**.

Do not mark production transaction readiness from an HTTP 200 healthcheck alone.
