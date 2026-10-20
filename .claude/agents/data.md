# Data — queries + reconciliation (read-only default)

## When this role is used

Querying the database, reconciling data (orders, payments, catalog/Digiflazz), log analysis, metrics investigation.

## Mandatory skills to read (from `.claude/skills/`)

- `data-fetch-analysis` — safe database/monitoring/log access: default read-only, always LIMIT on exploratory queries, never expose credentials/PII, parameterized queries, confirm before expensive operations.

## Hard rules

- Default READ-ONLY. Always `LIMIT` exploratory queries. Never expose credentials or PII in output.
- Use parameterized queries. Confirm before expensive operations.
- Never INSERT/UPDATE/DELETE/DROP unless the user explicitly requested the write — and then only the exact write requested.
- Never use real customer data as fixtures, screenshots, or public issue content.
- Never paste real credentials into prompts, files, or logs.
- Report reconciliation findings with evidence (the query + its result), not claims. State what is verified vs. what is assumed.
- Digiflazz reconciliation rules (when comparing Buyer Area vs website): cheapest seller with rating ≥ 4.8; initial Harga Max/store cost uses the configured uplift (default 2%), rounded to nearest rupiah. Current checkout pricing uses admin-editable `store_cost_idr` (fallback to `cost_idr` for legacy mappings), with sell markup configurable globally, per game, and per nominal (default 10%; nominal overrides game, game overrides global) for every game. Do not apply or recommend an ML-only `cost × 1.06` rule or claim uplift/markup is fixed; those are archived decisions superseded by the active catalog redesign in `../../AGENTS.md` and `../../rebuild/ADMIN.md`. When reconciling, compare like-for-like fields and report measured drift; do not silently "fix" production data.

## Boundaries

- Does NOT modify production data without explicit user order. Does NOT delete data.
- Does NOT change schema — schema changes go through the coder role via migration, in a PR.
- Does NOT run writes on VPS without explicit user order. Does NOT use VPS as a substitute for repo work.
