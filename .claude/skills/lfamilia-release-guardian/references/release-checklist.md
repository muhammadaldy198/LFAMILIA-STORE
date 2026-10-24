# Release Guardian — Merge-Readiness Checklist + Report Template

Sources: `AGENTS.md` §2–§6, `CLAUDE.md` §23, §26–§27, §36, §38, §40,
`.github/workflows/validate-rebuild.yml`, owner instruction (Codex
review via `@codex review` PR comment; no Codex CLI).

## Merge-readiness checklist (ALL must be true for READY)

- [ ] Work is on a focused branch (never directly on main).
- [ ] PR is OPEN (not draft-blocked for the purpose of merging, not
      closed, not already merged).
- [ ] PR description states: what changed, why, evidence (tests/checks
      run), and what is NOT changed.
- [ ] Complete diff inspected: only intended files changed; no
      generated secret/config file; no production dump or credential
      material; no unrelated formatting churn; tests/docs match the
      change.
- [ ] Local checks run (from `rebuild/`): `php -l` on changed files;
      `vendor/bin/pint --test`; `vendor/bin/phpunit` (or `composer
      test`); `npm run lint`, `npm run check`, `npm run build` when
      frontend changed. All errors fixed at once.
- [ ] CI: EVERY required check is `success` AT THE PR HEAD.
      Read the actual conclusions (`gh pr checks <PR>` or GitHub web
      PR Checks tab). Never from memory. Never from a different commit.
      - Job `foundation`: all steps pass (shape guard, composer
        validate/install, security guardrails, npm ci, npm audit,
        hooks test, config app, php -l, config cache, M12 assets,
        migrate/rollback/migrate, backup + restore-verify, phpunit,
        browser smoke, pint --test).
      - Job `frontend-static`: all steps pass (node --test ordering +
        UI guards, lint, check, build).
- [ ] Codex Review: an explicitly called review (`@codex review`
      comment) exists AT THE PR HEAD. A review on an older commit does
      NOT count. If the head moved after the review, re-request.
- [ ] All Codex findings addressed (fixed, or explicitly dispositioned
      with owner agreement). No unaddressed findings.
- [ ] No unresolved blocking discussion on the PR.
- [ ] No forbidden "fix": no disabled lint, no broad eslint-disable
      without narrow justification, no casual @ts-ignore, no skipped
      tests, no deleted tests, no fake success, no force-push main,
      no mixed unrelated features.

If ANY item fails → verdict is NOT READY, with the exact failing gate
listed and the next action (fix / re-run / re-review).

## Codex Review — how to request (no Codex CLI)

```sh
# Request Codex Review on a PR (Hermes uses its own gh CLI login)
gh pr comment <PR-NUMBER> --body "@codex review"

# Read check conclusions (read-only)
gh pr checks <PR-NUMBER>
gh pr view <PR-NUMBER> --json state,headRefOid,reviews,comments

# List recent runs for a branch (read-only)
gh run list --branch <branch-name>
gh run view <run-id>
```

Do NOT install Codex CLI. Codex Review is the GitHub integration
that responds to the `@codex review` comment. Request it in parallel
with CI — do not wait for CI to finish first.

## Merge-readiness report template (evidence only)

```
PR: <owner/repo#NUMBER> (<PR URL>)
Branch: <branch> → main | Head: <commit SHA>
CI (at head):
  - foundation: <success / failure / pending> (<run URL>)
  - frontend-static: <success / failure / pending> (<run URL>)
Codex Review:
  - Requested: yes (comment <URL>) / no
  - Review at head: yes (review <URL>, commit <SHA>) / no (stale: reviewed <SHA>, head <SHA>) / none
  - Findings: <N> total, <N> addressed, <N> open
Diff reviewed: yes / no
Blocking discussion: none / <list>
Verdict: READY TO MERGE / NOT READY TO MERGE
Reasons: <exact gates that passed / failed>
Next action: <merge only on owner order / fix X / re-run Y / re-request review>
```

Even when READY, do NOT merge without an owner order.

## Deploy gate checklist (owner order only)

- [ ] PR merged to main.
- [ ] Required CI green on the TARGET commit.
- [ ] Encrypted DB backup created AND verified BEFORE deploy.
- [ ] `deploy/preflight.sh` passes.
- [ ] Official mechanism only (`deploy/deploy.sh`, then
      `deploy/healthcheck.sh`).
- [ ] Queue / scheduler / logs / services verified after deploy.
- [ ] During deploy: NO Tunnel / DNS / firewall / SSH / networking
      changes; NO catalog / DB / order data deletion; NO manual price
      changes.
