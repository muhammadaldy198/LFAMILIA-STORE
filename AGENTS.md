# AGENTS.md — LFAMILIA STORE

Operating manual for any AI agent (or developer) working in this repo — whoever you are, whenever you pick this up.

Read `CLAUDE.md` FIRST for project invariants (it is canonical). This file tells you HOW to work here. If the two conflict, `CLAUDE.md` wins on invariants; an explicit user instruction wins over both.

## 0. Who works here

- Leader + code writer: ChatGPT. BETOL: QA/support, and also writes PRs (owner decision 2026-10-14).
- Either way, all work goes through: branch → PR → green CI → review → merge on user order.
- Specialist roles live in `.claude/agents/` — one file per role. Pick the role that matches the task. One task = one primary role; switch roles explicitly when the task changes.
- Never campaign for a different role. Never silently do another role's job (e.g. don't deploy while reviewing).

## 1. Mandatory skill lookup (WAJIB)

Skills do NOT auto-run. Before starting a coding/audit task, look up the mapping table below, READ the `SKILL.md` files listed, and follow them. No "asal trabas" (plowing ahead without skills).

Skills live in `.claude/skills/`. Read the `SKILL.md`, not just the skill name.

| Task | Mandatory skills |
|---|---|
| Write/change PHP/Laravel code | laravel-best-practices + karpathy-coder + zero-hallucination-coder + strict-api |
| Write/change Vue/Inertia frontend | inertia-vue-development + tailwindcss-development (+ frontend-design for design direction) |
| Connect frontend to backend routes | wayfinder-development |
| Write/change tests | pest-testing (+ webapp-testing for browser tests) |
| Audit/review code | data-fetch-analysis + karpathy-coder + skill-security-auditor (for security) |
| Payment/Midtrans | integrate-midtrans-payments (hanya Snap/webhook/verifikasi/status; bagian refund TIDAK berlaku — refund manual via CS, DE-2026-10-17-05) + fortify-development (if auth) |
| Build new feature/module | code-scaffolding + stack skills above |
| Deploy/CI | cicd-deployment |
| Walkthrough / big PR review | code-tour |
| Plan before coding | grill-me |
| Slow debug / N+1 | debug-using-debugbar |
| Media/upload | medialibrary-development |
| Auth (login/2FA/passkey) | fortify-development |

**Mandatory report:** at the end of every coding/audit task, state which skills were used + which were skipped and why. If a mandatory skill wasn't used, the reason must be written — no silent skipping.

**Conflict rule:** owner instructions (this file, `CLAUDE.md`, explicit task) always win over skills. Example: the Midtrans skill teaches automatic refund implementation → rejected, the store's refund is manual via CS (DE-2026-10-17-05), never automatic.

**Override:** the user can force a skill by naming it ("pake skill X") — that overrides the table.

**Vendored skills:** skills in `.claude/skills/` are vendored copies from upstream. Do not edit their content to change their meaning. If a skill conflicts with owner rules, the owner rule wins; note the conflict in the task/PR report — don't silently rewrite the skill.

## 2. Working method

```
READ → VERIFY → PLAN → CHANGE → TEST → REVIEW DIFF → PR → CI → MERGE → DEPLOY → VERIFY
```

Not every task reaches DEPLOY. Documentation-only work may correctly stop after merge verification.

Never: ASSUME → EDIT → CLAIM DONE.

Never claim success without tool output / evidence. A failed, unavailable, timed-out, or ambiguous tool call is not success.

Never report a PR as merged when it is only open. Never report production as deployed when only repository changes were merged.

Bugs (even minor) must be audited and discussed with the user BEFORE being fixed. Bring the full findings list, not only critical ones.

## 3. Hard rules from the owner (non-negotiable)

- **NOL teks dummy hardcoded** (`'QA'`, `'dummy'`, `'percobaan'`, `'test'`). Placeholder IMAGE allowed. Desktop + mobile screenshots required before PR.
- **Every PR must pass CI and review** — no yellow/red, MUST be green.
- **Do NOT touch production** without explicit user instruction.
- **Do NOT use the built-in GitHub connector** — use the custom GitHub OAuth connector.
- **Do NOT commit secrets** (`.env`, API keys, tokens, passwords, private keys, backup encryption material). Never put real secrets in Markdown, screenshots, fixtures, logs, PR comments, issues, or CI output.
- **Payment tests ONLY simulation/sandbox.** No real production payment transactions without user approval.
- **REFUND = manual via CS (DE-2026-10-17-05).** Refund DILAYANI untuk kondisi tertentu (Kebijakan Pengembalian Dana: gagal tak diproses ulang, pembayaran ganda, produk tak tersedia, nominal kurang, transaksi tak sah; ajukan ≤7 hari kalender via Hubungi Kami; review 1–7 hari kerja; dana 1–14 hari kerja). **Jangan pernah bangun/trigger refund OTOMATIS di sistem** — sistem nggak mengotomatiskan refund. Failed/expired payments handled via retry, bukan refund. Masalah order berbayar: settlement manual via Super Admin wallet adjustment (member) atau kontak langsung (guest). Jangan pernah initiate gateway refund otomatis.
- **Digiflazz rules.** Cheapest seller with rating ≥ 4.8. Harga Max = seller price × 1.02, rounded to nearest rupiah. Save one-by-one (not bulk). Navigate Buyer Area → Produk. Single browser session. All products ON. No seller ≥ 4.8 → delete the product. The `_A`/`_B` backup-SKU concept is DEAD — 1 SKU per nominal (model `_A`).
- **Pricing.** Cost (`cost_idr`) = Harga Max Digiflazz (fixed, not the fluctuating seller price). ML sell price = cost × 1.06, NOT rounded (owner decision 2026-10-12). Other games' sell prices are NOT decided — do not invent them.
- **Real customer data:** never use as fixtures, screenshots, or public issue content.
- **Text the user must copy on their phone:** plain text, not code blocks. Commands to copy go alone on their own line/message, without other words.
- **No estimates.** Do not say "sabar" (be patient).

## 4. Git workflow

Never develop directly on main.

```
main → focused branch → structured commit(s) → PR → CI → review/fix → merge only when required checks are green
```

- No force-push main. No merging with failing checks. No "fixing" CI by disabling tests/checks. No mixing unrelated features in one PR.
- Commit messages describe the real change.
- PR description: what changed, why, evidence (tests/checks run), and what is NOT changed.

## 5. Local checks before push

- PHP syntax: `php -l` on changed files (or the repo's find/xargs command from `CLAUDE.md` §36).
- PHP style: `vendor/bin/pint --test` on changed PHP files — the SAME check CI runs. `php -l` checks syntax only, NOT style — never rely on it alone.
- Tests: `vendor/bin/phpunit` (or `composer test`) for affected areas.
- Frontend: `npm run lint`, `npm run check`, `npm run build` when frontend changed.
- If lint/build errors appear, fix ALL of them at once, not one by one.
- Full command reference: `CLAUDE.md` §36. Do not invent replacement commands.

## 6. Deploy rules (only on explicit user order)

- Deploy only if the target commit's CI is green.
- Database backup created and verified BEFORE deploy.
- Preflight passes per repo runbook (`rebuild/deploy/README.md`).
- Only use the official deploy mechanism. Never deploy if backup or preflight fails.
- Never change Cloudflare Tunnel, DNS, firewall, SSH, or networking during deploy.
- Never delete catalog/database/order data or change manual prices during deploy.
- Sequence: PR merged → CI green → encrypted DB backup → `deploy/preflight.sh` → `deploy/deploy.sh` → `deploy/healthcheck.sh` → verify queue/scheduler/logs/services.
- Documentation-only changes do not require a production deployment.

## 7. VPS boundaries

- Read-only by default. No cleanup/deletion on VPS without explicit user instruction.
- Never use VPS as a substitute for normal GitHub repo work.
- Never edit files directly on production (no manual file-by-file editing).

## 8. Role files

`.claude/agents/` holds one file per specialist role. Read the role file for the task at hand before starting.

| Role | File | Use for |
|---|---|---|
| Coder | `coder.md` | Writing Laravel code |
| Reviewer | `reviewer.md` | Review + audit + security |
| Payment | `payment.md` | Midtrans + refund manual via CS (no auto-refund) |
| Frontend | `frontend.md` | Vue/Inertia/Tailwind + design |
| Tester | `tester.md` | Pest + webapp-testing |
| Deployer | `deployer.md` | CI/CD + deploy runbook |
| Data | `data.md` | Queries + reconciliation (read-only default) |
| Planner | `planner.md` | grill-me, plan before coding |

## 9. File map

- Active app: `rebuild/` (Laravel 12, PHP 8.4, Vue 3 + Inertia v2 + Tailwind v4).
- Skills: `.claude/skills/`
- Agent roles: `.claude/agents/`
- Invariants: `CLAUDE.md` (read first)
- Domain docs: `rebuild/*.md` (ARCHITECTURE, DATABASE, AUTH, PRODUCT, CHECKOUT, PAYMENT, FULFILLMENT, ADMIN, SECURITY, TESTING, `deploy/README.md`)
- Product intent: `docs/PRD-LFAMILIA-STORE-v1.md`
