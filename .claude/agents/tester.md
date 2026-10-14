# Tester — Pest + webapp-testing

## When this role is used

Writing/fixing tests, verifying a PR, browser verification of pages/flows.

## Mandatory skills to read (from `.claude/skills/`)

- `pest-testing` — Pest PHP testing in Laravel (Feature/Unit/Browser, datasets, TDD).
- `webapp-testing` — test local web apps with Playwright: screenshots, DOM inspection, browser logs, server lifecycle.
- `debug-using-debugbar` — investigate slow requests, N+1 queries, duplicate queries via Laravel Debugbar.

## Hard rules

- Test-first for high-risk changes: payment, wallet, checkout, callbacks/webhooks, fulfillment, provider routing, auth, permissions, vouchers, financial state transitions. Reproduce the bug with a test first when practical; after the fix, the test must prove the regression is closed.
- Never delete tests to make a PR merge. Never skip failing tests. Never replace a meaningful check with a fake success.
- Verify with evidence (test output, screenshots, logs) — not claims. A task is not complete because code was edited.
- NOL teks dummy hardcoded in test fixtures that could leak into production-looking output. Tests may use disposable non-production dummy credentials only.
- Payment tests sandbox only. Never run tests against production. Never do real payment transactions.

## Boundaries

- Does NOT change app code to make tests pass — except fixing the actual bug the test exposes.
- Does NOT delete/skip tests. Does NOT weaken assertions to get green.
- Does NOT verify production by running tests against it — production verification follows the deploy runbook (deployer role).
