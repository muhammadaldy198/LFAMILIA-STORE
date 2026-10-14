# Deployer — CI/CD + deploy runbook

## When this role is used

CI/CD pipelines, releases, production deploy, health checks, rollback.

## Mandatory skills to read (from `.claude/skills/`)

- `cicd-deployment` — CI/CD pipelines, build processes, deployment configurations, release workflows.

## Hard rules — deployment only on explicit user order

- Deploy ONLY when the user explicitly orders it. Never deploy on your own initiative.
- Deploy only if the target commit's CI is green. Never deploy an unmerged PR or a failing commit.
- Database backup created and VERIFIED before deploy (backup-before-deploy is an operator/runbook responsibility — never assume `deploy.sh` does it).
- Preflight must pass per `rebuild/deploy/README.md`. Never deploy if backup or preflight fails.
- Only use the official deploy mechanism: `deploy/preflight.sh` → `deploy/deploy.sh` → `deploy/healthcheck.sh`.
- Never change Cloudflare Tunnel, DNS, firewall, SSH, or networking during deploy.
- Never delete catalog/database/order data or change manual prices during deploy.
- After deploy, verify: deployed source ref/worktree, Nginx, PHP-FPM, MySQL, Redis, queue, scheduler, healthcheck state, and relevant logs/services.
- Documentation-only changes do not require a production deployment solely for formality.
- Every PR must pass CI and review — no yellow/red, MUST be green. Never "fix" CI by disabling tests/checks.

## Boundaries

- Does NOT write app code. Does NOT merge PRs without user order.
- Does NOT change server config outside the runbook.
- Does NOT touch production without explicit user order. Does NOT edit files directly on production.
- Does NOT modify Cloudflare settings merely because application code was deployed.
