# CI pipeline anatomy and safe parallelization (LFAMILIA STORE)

`.github/workflows/validate-rebuild.yml` runs two jobs (`foundation`,
`frontend-static`). `foundation` is the slow one. Before proposing to split it,
measure where its minutes go — the answer is never evenly spread.

## Measure first

```bash
gh run list --branch <branch> --limit 3
gh pr checks <PR>                                   # per-check durations
gh api repos/<owner>/<repo>/actions/jobs/<job-id> \
  --jq '.steps[] | "\(.conclusion)\t\(.name)\t\(.started_at)\t\(.completed_at)"'
```

Typical shape of a green `foundation` run (~9 min):

| Step | Share |
|---|---|
| M11 browser smoke | ~50% (admin-ui matrix ≈ 2/3 of it, checkout visual ≈ 15%, responsive ≈ 10%) |
| PHPUnit (`vendor/bin/phpunit`) | ~25% |
| Git-hook validation (`npm run test:hooks`) | ~10% |
| Everything else (containers, composer, npm, syntax, config, migrate, backup/restore, Pint) | ~15% combined |

So two steps own ~75% of the wall clock. Pint, syntax and asset validation are
seconds — splitting those out buys nothing.

## Why splitting helps, and what it costs

- Jobs run in parallel, so wall clock = **slowest** job, not the sum.
- Every extra job re-pays setup: checkout, container boot (MySQL/Redis),
  `composer install`, `npm ci` — roughly a minute each, sometimes more.
- A split therefore pays only when the new job's own work is much longer than
  its setup, i.e. when the work was genuinely the bottleneck.

## Shape that works

1. `static` — repo shape, secret guardrails, PHP syntax, config cache, deploy
   assets, `pint --test`. No services, no DB. Seconds.
2. `php-tests` — MySQL + Redis, `migrate`, `phpunit`. ~3–4 min.
3. `browser` — build + `tests/Browser/smoke.sh`. ~5 min.

Then shard the browser job by viewport: `tests/Browser/admin-ui.mjs` already
reads `ADMIN_UI_SIZES` (comma-separated `WxH`) and falls back to 8 viewports for
`all`, 2 for a single group. Splitting the 8-viewport matrix into two jobs of 4
cuts admin-ui roughly in half. `smoke.sh` does not pass that variable yet — it
has to be threaded through, and the checkout-visual + responsive segments kept
in exactly one shard so nothing runs twice or not at all.

Expected: ~9 min → ~3.5–4 min with the same checks. The gain is real only
because nothing is removed.

## Hard constraints

- **Never drop or weaken a check to save time.** Parallelize; do not delete.
  `AGENTS.md` §4 / `CLAUDE.md` §23 apply to the workflow file too.
- **Check what the job names are contractually worth.**
  `gh api repos/<owner>/<repo>/branches/main/protection` returning 404
  "Branch not protected" means no required-check list pins the job names —
  renaming or adding jobs cannot break merge rules. Re-check before renaming;
  a protected branch with required contexts makes a rename a breaking change.
- **Check visibility before arguing about cost.** A public repo has free
  Actions minutes; extra jobs cost elapsed time, not money. On a private repo,
  multiplying jobs is a budget decision for the owner.
- **Docs move with the workflow.** `rebuild/TESTING.md` lists the jobs, and this
  skill's CI gate section names them. Update both in the same PR, or the next
  session reads a stale pipeline.
- **Ship it as its own small PR** against the integration branch, not folded into
  a feature batch: a workflow change touching every later batch deserves its own
  review, and its effect is measurable in the very next run.

## Reporting a speed change

Quote before/after per-step timings from the API, not impressions. A run-level
"success" says nothing about duration, and a cached PR checks view can lag the
real conclusion.
