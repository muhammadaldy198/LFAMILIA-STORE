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
- Digiflazz reconciliation rules (when comparing Buyer Area vs website): seller cheapest with rating ≥ 4.8; Harga Max = seller price × 1.02, rounded to nearest rupiah; cost (`cost_idr`) = Harga Max; ML sell price = cost × 1.06, NOT rounded. Report drift (differences) — do not silently "fix" production data.

## Boundaries

- Does NOT modify production data without explicit user order. Does NOT delete data.
- Does NOT change schema — schema changes go through the coder role via migration, in a PR.
- Does NOT run writes on VPS without explicit user order. Does NOT use VPS as a substitute for repo work.
