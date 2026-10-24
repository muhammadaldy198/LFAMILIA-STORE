---
name: lfamilia-release-guardian
description: "Guard the LFAMILIA STORE release workflow: enforce branch → PR → green CI → review → merge (owner order) → deploy (owner order). Verify all GitHub Actions checks, Laravel Pint, PHPUnit, lint, build, and browser tests. Request Codex Review via '@codex review' PR comment (never install Codex CLI), verify review results, request re-review after relevant changes, block merge on failing CI or incomplete review, and report merge-readiness from evidence, never assumption. Never merges or deploys without an owner order. Use when opening, reviewing, merging, or deploying a PR, checking CI, requesting Codex Review, or judging release readiness for LFAMILIA STORE."
license: MIT
metadata:
  author: lfamilia
  source: LFAMILIA-STORE AGENTS.md, CLAUDE.md, .github/workflows/validate-rebuild.yml
---

# LFAMILIA Release Guardian

Guard the release workflow for LFAMILIA STORE. This skill decides
whether a change is ready to merge / deploy — from evidence, never
from assumption. It never merges or deploys on its own.

## Precedence — read this first (hard rules, non-negotiable)

1. Owner instructions win over any skill. Order: explicit owner instruction
   > `CLAUDE.md` (invariants) > `AGENTS.md` (how to work) > any skill.
2. **No merge without an owner order.** No deployment without an owner
   order. This skill reports readiness; the owner decides.
3. **Do NOT touch production** without explicit owner instruction
   (`AGENTS.md` §3).
4. Never report a PR as merged when it is only open. Never report
   production as deployed when only repository changes were merged
   (`AGENTS.md` §2, `CLAUDE.md` §40).
5. Never claim success without tool output / evidence. A failed,
   unavailable, timed-out, or ambiguous tool call is not success.
6. Never commit secrets. Never put real secrets in Markdown, screenshots,
   fixtures, logs, PR comments, issues, or CI output (`AGENTS.md` §3,
   `CLAUDE.md` §5).

## When to activate

- Opening, reviewing, merging, or deploying a PR for LFAMILIA STORE.
- Checking CI / GitHub Actions status for a branch or PR.
- Requesting Codex Review, checking review results, or requesting
  re-review.
- Judging whether a PR is ready to merge or a commit is ready to deploy.
- Any release, deploy, rollback, or hotfix discussion.

## Release workflow (the only allowed path)

Source: `AGENTS.md` §2, §4; `CLAUDE.md` §26, §34, §40.

```
main → focused branch → structured commit(s) → PR → CI → review/fix
→ merge only when required checks are green (OWNER ORDER)
→ deploy only on explicit owner order
```

- Never develop directly on main.
- No force-push main. No merging with failing checks. No "fixing" CI
  by disabling tests/checks. No mixing unrelated features in one PR.
- When a roadmap names an ordered PR sequence, resume the earliest open phase
  before starting a later phase. Check whether a change belongs to an existing
  PR or a separate phase, and do not move commits between PRs until confirming
  their scope and preserving any user/uncommitted work.
- If the owner says "continue" after authorizing a PR workflow, proceed through
  the already-authorized reversible steps (commit, push, PR update/create,
  review request) without asking for redundant confirmation. Stop only for a
  concrete unresolved business choice or an action affecting production; use
  the safest reversible path meanwhile. Keep progress updates concise and
  action-oriented; when the owner says "don't ask so much," avoid narrating
  options and make a reversible default decision where policy permits.
- Before starting a later roadmap item, inspect roadmap sequence and current
  open PRs; finish/update the earliest relevant PR first. Keep commits scoped
  to the PR they fix, and never strand a completed commit locally while
  claiming the PR has been updated.
- A one-word, truncated, or bare-token owner message mid-project is a
  continuation, not a new task: recover context first — browse recent sessions
  and search the literal token in session history — then answer from the
  recovered state. Asking the owner to restate context they already provided
  reads as amnesia and is a first-order frustration source.
- Repository state described in a work order ("still staged", "this PR has the
  failing CI", "commit then push") is a claim, not evidence: verify branch HEAD
  against origin, the staged diff in the named worktree, PR check conclusions,
  and review receipts before executing. When those steps are already done,
  report per-step evidence and ask only the remaining decision — see
  `github-pr-delivery` for the exact commands.
- When PR-local edits already exist, inspect `git status`, staged diff, branch,
  and remote PR head in the actual worktree before changing or pushing. Treat
  an existing local commit as unfinished delivery, not as evidence the remote
  PR contains it.
- When reports identify a concrete CI failure, correct it in its intended
  roadmap phase/PR; don't create a later-phase PR that mixes the fix with a
  separate roadmap task merely because both changes pass locally.
- Do not ask the owner to disposition technical review findings that can be
  resolved through repository evidence and tests. Isolate truly production-data
  questions from code work; read-only VPS access still requires explicit owner
  request, and unavailable production evidence must be reported rather than
  guessed.
- When an allowed local/remote action is clear, execute it before replying;
  distinguish verified progress from blockers and do not ask the user to
  re-authorize steps already covered by their instruction.
- If a commit hook fails because helper binaries are not on PATH, inspect its
  exact subprocesses and add verified absolute binary directories to PATH;
  rerun hooks and never disable them.
- Before claiming a push/PR update, verify the remote branch SHA and PR head
  SHA. Before claiming CI is green, fetch final check conclusions (a run-level
  conclusion may be green while a cached PR check view remains pending).
- Don't treat a successful lint hook as proof the PHPUnit suite passed, or vice
  versa; state each gate separately and only after its own output. A step that
  never ran because an earlier step in the same job failed is not a pass — read
  the run's per-step list (`gh run view <id> --log-failed`), not just the job
  conclusion. A skipped Pint step means Pint is unproven, not green.
- When the owner asks a direct question mid-task ("can this be split?", "why
  does CI take nine minutes?"), answer it in the same reply before starting
  another investigation. A chain of silent tool calls where an answer belongs
  reads as being ignored, and the owner will say so bluntly. Investigate only
  as far as the answer needs, answer with the numbers you already have, then
  offer the follow-up action.
- Commit messages describe the real change.
- PR description: what changed, why, evidence (tests/checks run), and
  what is NOT changed.

## Running one roadmap batch

Long audits arrive as an ordered batch list (workspace/CI → privacy → stock →
payments → fulfillment → pricing → panel → frontend → data → tests → cleanup →
integration). One batch = one worktree = one branch, based on the integration
branch, never `main`, never a second batch's worktree. Procedure, verification
commands and the required report format: `references/batch-execution.md`.

## Verifying a rewritten history before acting on it

The owner sometimes reports that a branch was rewritten (author renamed, commits squashed, SHAs changed) and asks you to trust the report. Verify it from the repository — every claim above is checkable in about four commands, and a rewritten branch interacts badly with a remote that already has it.

```bash
git log --format='%h %T %an <%ae> %s' -8        # short sha + tree + author + subject, one line each
git rev-parse <old-sha>^{tree}                  # tree hash of the pre-rewrite commit
git ls-remote origin 'refs/heads/<branch>'      # what the remote actually points at
```

- **Pair old and new commits by subject, never by list position.** A rebase can reorder; pairing by index reported two commits as "content changed" when only the order differed. Match on the commit subject, then compare.
- **Content equality is a tree-hash comparison, not a message shape.** `git rev-parse <sha>^{tree}` on both sides: equal means the content is byte-identical, because author/committer and dates are not part of the tree. Comparing SHAs is meaningless — a rename changes the SHA by definition.
- **Whether a rewritten commit was ever pushed is answered by the remote, not by the reflog.** The reflog shows only local moves and cannot see a branch that exists on the remote under a different line. Read `git ls-remote` (or the tracking ref) and compare with HEAD.
- **A remote branch that is not an ancestor of HEAD turns the next push into a decision.** If the remote branch exists at a commit that is not in your history, a plain `git push` is rejected (non-fast-forward) and `--force` deletes everything only the remote had. Report that trade-off — push to a new branch name, or wait for an owner order — and never force-push as tidying.
- **The author stamp of the NEXT commit comes from `git config user.name/user.email`**, not from the branch history; read it after any operation that may have touched config, and do not assume the identity you saw earlier still holds.

## CI gate — ALL required checks must be green

Source: `AGENTS.md` §3 ("Every PR must pass CI and review — no
yellow/red, MUST be green"); `.github/workflows/validate-rebuild.yml`.

Merge is blocked unless **every** required check is green. "Green"
means the check's conclusion is `success`. A check that is pending,
cancelled, skipped without justification, or yellow/red is NOT green.

The repo's CI workflow is `.github/workflows/validate-rebuild.yml`
(`Validate LFAMILIA Laravel`). It runs on push to `main` /
`rebuild/**` and on PRs touching `rebuild/**` or the workflow file.
It has two jobs (both must pass):

**Job `foundation`** (Ubuntu 24.04, PHP 8.4, Node 22, MySQL 8, Redis 7,
working directory `rebuild/`):

- Enforce Laravel-only repository shape (legacy non-Laravel paths must
  not exist).
- `composer validate --strict` + `composer install`.
- Security guardrails: `composer audit --no-interaction`; no tracked
  `.env`; no private-key material in tracked source; no hardcoded
  secrets (`api_key` / `server_key` / `client_secret` / `secret_key` /
  `access_token` / `bot_token` with a literal value) in
  `rebuild/app/**`, `rebuild/config/**`, `rebuild/routes/**`,
  `rebuild/resources/**`.
- `npm ci` (with `HUSKY: 0`).
- `npm audit --audit-level=high`.
- Validate local Git hooks (`npm run prepare`, `npm run test:hooks`,
  with `LFAMILIA_ENABLE_HOOKS: 1`).
- Configure test app (`cp .env.example .env`,
  `php artisan key:generate`).
- Check PHP syntax (`find ... -name '*.php' | xargs -n1 php -l`).
- Validate cached configuration (`config:cache`, `config:clear`).
- Validate M12 deployment assets (`bash -n` on `deploy/*.sh`, systemd
  unit files present, required markers in ops/production env examples
  and deploy/rollback scripts).
- Validate schema and assets (`migrate --force`,
  `migrate:rollback --step=10 --force`, `migrate --force`,
  `npm run build`).
- Verify M12 encrypted backup and restore (`deploy/backup.sh`,
  `deploy/restore-verify.sh` with test credentials).
- `vendor/bin/phpunit`.
- M11 browser smoke (`bash tests/Browser/smoke.sh`).
- Upload checkout visual comparison artifact (always).
- `vendor/bin/pint --test`.

**Job `frontend-static`** (Ubuntu 24.04, Node 22):

- `npm ci` (with `HUSKY: 0`, npm cache).
- `node --test tests/Browser/ordering.test.mjs
  tests/Browser/customer-ui-guards.test.mjs
  tests/Browser/admin-ui-guards.test.mjs`.
- `npm run lint`.
- `npm run check`.
- `npm run build`.

How to check CI (read-only): use `gh` CLI (`gh pr checks <PR>`,
`gh run list --branch <branch>`, `gh run view <run-id>`) or the GitHub
web PR Checks tab. Never claim CI is green from memory — read the
actual check conclusions.

- **A `pass` conclusion is only a review if the reviewer actually reviewed.**
  Third-party review apps can post a green check with a body like "Full review
  skipped: trial expired and no credits remaining". That is a skipped check
  wearing a green tick: exclude it from readiness evidence and name it as
  skipped in the report rather than counting it as the review gate.
- **Job durations are diagnosable, not mysterious.** Pull per-step timings with
  `gh api repos/<owner>/<repo>/actions/jobs/<job-id> --jq '.steps[] | "\(.conclusion)\t\(.name)\t\(.started_at)\t\(.completed_at)"'`
  before proposing to speed a workflow up; the slow step is usually one long
  suite, and guessing which one wastes the owner's time. Anatomy, measured
  proportions and a safe parallelization plan: `references/ci-pipeline.md`.

Poll with short bounded foreground calls (`sleep 300 && gh pr checks <PR>`).
A long background poll outlives the turn, and its late completion notice then
describes a run that has already been superseded. Treat any completion notice
that arrives after the conversation moved on as stale: confirm with one
`gh pr checks <PR>` and reply in a line instead of re-running the job.

### Forbidden "fixes" (never do these to get CI green)

Source: `AGENTS.md` §4; `CLAUDE.md` §23.

- Disabling lint rules merely to get CI green.
- Adding broad `eslint-disable` directives without a narrowly
  justified reason.
- Using `@ts-ignore` casually.
- Skipping failing tests.
- Deleting tests to make a PR merge.
- Replacing a meaningful check with a fake success.
- **Fix the root cause.**

## Local checks before push

Source: `CLAUDE.md` §36; `AGENTS.md` §5.

Run from `rebuild/` unless stated otherwise:

- PHP syntax: `find app bootstrap config public routes tests -name
  '*.php' -print0 | xargs -0 -n1 php -l`
- PHP style: `vendor/bin/pint --test` (the SAME check CI runs;
  `php -l` checks syntax only, NOT style — never rely on it alone).
- Tests: `vendor/bin/phpunit` (or `composer test`).
- Frontend: `npm run lint`, `npm run check`, `npm run build` when
  frontend changed.
- If lint/build errors appear, fix ALL of them at once, not one by one.
- **Git hooks are never bypassed.** `AGENTS.md` §7 forbids skipping the pre-push
  hook (`--no-verify` dilarang), and the owner's rule outranks this skill —
  `--no-verify` is not an option on commit or push, whatever the timeout
  pressure.
  - A missing binary (`php: command not found`) is a PATH problem, not a reason
    to bypass. Locate the real binary (`find / -name php -type f -perm -u+x`, or
    the documented `~/php-cli/php-8.4/`) and prepend its directory to `PATH` for
    the hook run. If no PHP exists at all, stop and report the blocker instead
    of committing around the guard.
  - The pre-push hook runs `npm run lint`, `vue-tsc`, a full frontend build,
    `php -l` over the repo, and `vendor/bin/pint --test`; whole-repo Pint alone
    can exceed the 300s foreground cap. Give `git push` a 600s foreground
    timeout, or run it as a tracked background process and wait for its exit
    code — never trade the hook for speed.
  - `npm run test:hooks` is destructive in this repo: its smoke test creates and
    resets a commit and leaves `user.name`/`user.email` pointing at a smoke
    identity. Never run it with uncommitted work, and re-assert the real
    identity (`git config user.name` / `user.email`) afterwards.
- **One commit, then stop for the verdict.** Commit a single logical item, run
  its self-review, and hand that head over for review before starting the next
  item. Do not chain several commits and ask for review afterwards: the verdict
  then only covers the head, every intermediate commit is unvetted, and the
  owner will (rightly) call the work rushed. If commits already stacked up, say
  so plainly and offer either reset-and-redo or per-commit review — never
  present a head-only verdict as if it covered the chain.
- **A verdict is bound to a SHA, not to a branch.** Adding any commit after the
  verdict invalidates it; the final head needs its own BERSIH. Report the head
  the verdict names next to the current head so the gap is visible.
- **Review findings must be verified against the LIVE spec before being fixed.**
  A reviewer can quote `docs/archive/` (a superseded design) and flag correct
  code as a violation. Read the current `docs/ADMIN-PANEL.md` and the owner's
  brief, and when they disagree with the finding, reject the finding with the
  `file:line` that proves it — do not "fix" working code to satisfy a stale
  document.
- **A review finding about behaviour is only closed by a test that exercises
  the behaviour.** Grepping the Vue source (`assertStringContainsString`,
  `assert.match(catalog, /…/)`) or asserting Inertia props proves the file
  contains a string, not that import/save works. POST the real endpoint and
  assert the stored rows. If the spec says "test the import", a source grep is
  not the test.
- **UI gates and server gates must cover the same set of actions.** When
  middleware guards *every* mutation of a route, a UI that prompts only for
  "sensitive" fields sends the rest into a redirect and the operator loses the
  typed form. Widen the prompt to match the middleware, or narrow the
  middleware — never leave the two out of step.
- Run your own checks before pushing anyway; the hook is a floor, not proof of
  quality.
- **Never run two test processes against the same test database.** `phpunit.xml`
  pins one `DB_DATABASE`; a second concurrent run migrates/resets tables while
  the first is querying, and MySQL answers `SQLSTATE[HY000]: 1412 Table
  definition has changed` across dozens of unrelated tests. Those errors are
  your own concurrency, not a regression — check `ps -eo pid,cmd | grep
  "[p]hpunit"` before believing a red run. Run one suite at a time, or give each
  worker its own database (see below).
- **Target the files you changed instead of the full suite.** The full PHPUnit
  suite takes ~6–7 minutes locally and is proven once by CI; during the fix loop
  run the touched classes (`vendor/bin/phpunit tests/Feature/CatalogTest.php`,
  seconds each).
- **Parallel local runs are owner-authorized, as local infra only.** `paratest`
  with one database per worker (`<base>_<TEST_TOKEN>`) is sanctioned for the
  local loop; the owner's rule is that this infrastructure stays OUT of the repo
  and CI unless they say otherwise. Measured on this suite: 2 workers, 540 tests /
  5,445 assertions in ~5:04 vs ~6:34–7:19 sequential. Do not raise the worker
  count without measuring a real drop. Setup and pitfalls: `references/parallel-tests.md`.
- **Never `source .env` before running the suite.** Exporting the app `.env`
  (`set -a; . ./.env; set +a`) overrides the test environment and produces a
  wall of 419 CSRF and auth failures that look like broken code. `phpunit.xml`
  already supplies test env; pass only the extra flag inline
  (`SESSION_ENCRYPT=true vendor/bin/phpunit`).
- **`npx vue-tsc --noEmit` is not this repo's typecheck.** It misresolves and
  prints the plain `tsc` help with exit 1, which reads as a type error but is
  not. Use `npm run check` (it passes `-p jsconfig.json`) and trust its exit code.
- **Stage explicit paths, never `git add -A`, while review sub-agents are running.**
  A reviewer that probes behaviour writes throwaway test files into `rebuild/tests/`;
  `git add -A` sweeps them into your commit. Stage the files you edited, and after
  every commit run `git show --stat HEAD` and check every path belongs to the change.
  If a foreign file got in, `git rm --cached` it and `git commit --amend` before the
  head goes anywhere.
- **A fix is only proven by a test that fails on the pre-fix code.** Revert the
  production change (or run the assertion against `git show <base>:<file>`) and
  confirm the test goes red, then restore. A test that stays green either way
  documents behaviour, it does not guard it — say so in the report instead of
  counting it as proof.
- **Changing a tab, label, or payload shape means sweeping the docs too.** The
  same claim usually lives in more than one place (`docs/ADMIN-PANEL.md`,
  `rebuild/ADMIN.md`, `TESTING.md`); a standards review will flag whichever copy
  you forgot. Grep the docs tree for the old wording (`grep -rn "lima tab\|five tabs"`)
  and fix every hit in the same commit.
- **`LengthAwarePaginator` has no `only()`.** `$paginator->only('current_page', …)`
  silently returns nothing and Inertia props go missing; build the metadata
  explicitly from `currentPage()`, `lastPage()`, `perPage()`, `total()`.
- **Once a suite has passed, stop running it.** The owner reads repeated full-suite
  runs as wasted time ("don't keep running it once it works"). Re-run only the
  specific filter that covers the file you just touched; keep the full run for
  the head that goes to review.
- **Editing a price field means knowing which column actually wins.**
  `CheckoutPricing` gives `package_sell_markup_percent` precedence over
  `margin_percent` and `margin_fixed_idr`, so writing a new number into the
  "correct" column can be silently ignored while a legacy value keeps pricing the
  order. Read `CheckoutPricing::quoteForPackage` before touching pricing payloads,
  and null out the higher-precedence column in the same write.
- Do NOT invent replacement commands. Check `package.json`,
  `composer.json`, `TESTING.md`, `deploy/README.md`, and
  `.github/workflows/validate-rebuild.yml` when command behaviour
  changes.

## Local test data and browser smoke tests

- **Clean QA seed rows before running the suite.** Browser QA needs a product or
  admin row in the local DB; a leftover row silently breaks count assertions on
  tests unrelated to your change (`has('products', 1)` sees 2). Delete the rows
  you seeded, then re-run only the failing test to prove the DB, not the code,
  was the cause.
- **Sweep every renamed UI string across `tests/Browser/` before pushing.** A
  browser smoke run aborts at the first missing locator, so a branch that
  renames several labels costs one 10–20 minute `foundation` round trip per
  label. Before pushing, grep the whole browser test tree for each old string
  the branch changed (`grep -rn "Masuk Admin\|Simpan Perubahan\|fitur tambahan"
  tests/Browser/`) and fix them all in one commit.
- **A locator must match the element's ROLE, not only its label.**
  `getByRole('button', {name: 'X'})` times out for 30s when the component
  renders that control as `role="tab"` or `role="switch"` — the label is right,
  the role is wrong. Before trusting a locator, grep the Vue source for the
  label and read the rendered element; fix the locator, never the UI, and never
  weaken the assertion to make it pass.
- **A smoke suite that also drives an external site cannot be fully proven
  locally.** If the script first navigates somewhere outside the app, prove only
  the assertion block you changed and report the rest as CI-only — never upgrade
  a partially-run suite into "smoke passed".
- **Fixture keys must be stable, not random.** The smoke fixture re-runs against
  the persistent local DB; a random `idempotency_key` defeats the service's
  idempotent lookup and inserts a fresh order every run, which then breaks
  count-based feature tests. Use fixed fixture keys, and prove it: run the
  fixture twice and assert the row count did not change.
- **Run one browser group, not `all`, while iterating.** The `all` matrix
  (8 viewports × every page) takes ~15 minutes and its import-picker step is
  order-dependent on leftover local data. Run the affected group
  (`node tests/Browser/admin-ui.mjs <chrome> product`) in the fix loop and
  leave `all` to CI.
- **Sheets/dialogs are portaled outside `<main>`.** After converting an editor to
  a side sheet, any smoke assertion that queries `document.querySelector('main …')`
  finds nothing. Query `[role=dialog]` (falling back to `main`) and assert the
  dialog opened instead of asserting the page list disappeared.
- **A controlled sheet must close itself on success.** `form.reset()` alone leaves
  the operator behind an empty dialog with the global success message hidden;
  every creation sheet needs `showX.value = false` in `onSuccess`.
- **A bare `<template>` wrapper renders an inert element.** Leftover
  `<template>`/`</template>` pairs from a refactor collapse the wrapped list to
  zero height, so buttons inside are unclickable at 360px while the page still
  looks fine on desktop. After a page-level refactor, grep each touched page for
  `<template>` lines with no `v-if`/`v-else`/`v-for`/`#slot` and remove them.

## Diff review before PR

Source: `CLAUDE.md` §38.

Before opening a PR, inspect the complete diff and confirm:

- Only intended files changed.
- No generated secret/config file was added.
- No production dump or credential material was added.
- No unrelated formatting churn.
- Tests/documentation match the behavioural change.
- For documentation-only work: runtime source files were not changed.

## Codex Review (required)

Source: parent task brief + repo rule "every PR must have an
explicitly called Codex review at the latest head".

- **Every PR MUST have a Codex review explicitly called at the latest
  head.** Request it by posting the comment `@codex review` on the PR
  (via `gh` CLI: `gh pr comment <PR> --body "@codex review"`, or via
  GitHub web). A Codex review is a review posted by the Codex GitHub
  integration — not a local CLI run.
- **Do NOT install Codex CLI.** Hermes has its own coding environment.
  Codex Review is used through the GitHub integration only.
- **A stale review does NOT count.** If the reviewed commit is older
  than the PR head, re-request the review at the latest head.
- **Re-request after relevant changes.** After any change that affects
  reviewed code, request a fresh Codex review (`@codex review`) and
  wait for it before judging readiness.
- **All findings must be addressed.** Read the review output. Every
  finding must be fixed, or explicitly dispositioned with owner
  agreement (never silently ignored). A PR with unaddressed Codex
  findings is NOT ready to merge.
- **Codex review runs in parallel with CI** — do not wait for CI to
  finish before requesting the review, and do not treat a green CI as
  a substitute for the review. Request `@codex review` in the same
  breath as opening/updating the PR; a run that starts only after
  `foundation` goes green wastes a full CI cycle before review even
  begins.
- **A landed review outranks CI.** When a review round arrives, read
  it and start fixing immediately — do not wait for the running CI to
  finish, and do not wait for the owner to relay the comment. Push the
  fix, then re-request review at the new head; CI verdicts are reported
  once at the end.
- **Count findings from both endpoints.** Inline findings live in
  `pulls/<PR>/comments`, but a round's first finding is often only in
  the review body (`pulls/<PR>/reviews`, the one carrying
  `codex-code-review-finding:v1`); receipts from later rounds are
  655-char stubs. Enumerate both before reporting a count, or the
  tally silently under-reports and the owner catches it.
- **Codex quota exhaustion:** If Codex responds with `You have reached your Codex usage limits for code reviews`, do NOT spam re-requests or comments. Check that all findings from earlier completed rounds are addressed, resolve all review threads via GraphQL once verified, verify that all CI checks are green on the latest commit, and report the exact readiness evidence to the owner for manual approval/merge.
- **Reading the result:** inline findings live under `pulls/<PR>/comments`, and a
  round's review body can carry `codex-code-review-finding:v1`. But a **clean**
  round frequently leaves `pulls/<PR>/reviews` completely EMPTY and posts the
  receipt as PR conversation comments instead: one `codex-review-receipt:v1`
  comment ("Codex Review: Didn't find any major issues", plus
  `Reviewed commit: <sha>`), and a `codex-pull-request-review-summary` comment
  whose JSON carries `code_review.commit_sha` and `has_any_findings: false`.
  Enumerate all three surfaces before concluding anything:
  `gh api repos/<owner>/<repo>/pulls/<PR>/reviews`,
  `gh api .../pulls/<PR>/comments`, and
  `gh pr view <PR> --json comments --jq '.comments[] | {a:.author.login, at:.createdAt, b:(.body[0:200])}'`.
  An empty reviews endpoint is NOT "no review ran"; only a receipt whose
  `Reviewed commit` equals the PR head proves the round happened. Compare that
  SHA against `gh pr view <PR> --json headRefOid` every time.
- **Latency is normal, silence is not a verdict.** A review can take well over 15 minutes. Keep polling the reviews endpoint filtered by timestamp on the head commit; do not tell the owner it is clean because a window elapsed, and do not re-post `@codex review` for the same head while the 👀 reaction is still the only signal.
- **Multiple review rounds are expected, not a failure.** Each round of fixes gets its own `@codex review` at the new head. Treat every finding as real until the source disproves it; a P1 about missing props or a broken template binding is worth fixing immediately even if CI is green, because CI does not exercise those runtime paths.

## Merge-readiness verdict

Report readiness ONLY from evidence. Use the format in
`references/release-checklist.md`. The verdict is one of:

- **READY TO MERGE** — ALL of: branch is not main; PR is open; every
  required CI check is green at the PR head; a Codex review exists at
  the PR head; all Codex findings are addressed; the diff was
  reviewed; no unresolved blocking discussion. The report must list
  the evidence (PR link, CI conclusions per job, Codex review commit,
  findings status). Even when READY, do NOT merge without an owner
  order.
- **NOT READY TO MERGE** — any of: a required CI check is not green;
  no Codex review at the latest head; unaddressed Codex findings;
  unresolved blocking discussion; diff not reviewed; PR is not open.
  The report must list exactly which gate failed and what must happen
  next (fix, re-run, re-review).

Never report "ready" from assumption, from a stale review, from CI
on a different commit, or from a partial check list.

## Deploy gate (owner order only)

Source: `AGENTS.md` §6; `CLAUDE.md` §27.

Deploy ONLY on an explicit owner order, and only when:

1. PR merged to main.
2. Required CI green on the target commit.
3. Encrypted DB backup created AND verified BEFORE deploy.
4. `deploy/preflight.sh` passes.
5. Only the official deploy mechanism is used
   (`deploy/deploy.sh`, then `deploy/healthcheck.sh`).
6. Queue/scheduler/logs/services verified after deploy.

During deploy, NEVER: change Cloudflare Tunnel, DNS, firewall, SSH,
or networking; delete catalog / database / order data; change manual
prices. Documentation-only changes do not require a production
deployment solely for formality.

For the concrete execution sequence, host access (direct SSH with the explicit key, relay only as fallback), and pitfalls (backup-freshness window, backup unit vs timer, relay poller down, what the deploy output proves, how to prove a UI fix is live), see `references/deploy-runbook.md`.

## Bugs

Bugs (even minor) must be audited and discussed with the owner
BEFORE being fixed (`AGENTS.md` §2). Bring the full findings list,
not only critical ones.

## What this skill NEVER does

- Never merges a PR without an owner order.
- Never deploys without an owner order.
- Never merges with failing required checks.
- Never "fixes" CI by disabling tests/checks, skipping tests, deleting
  tests, or faking success.
- Never installs Codex CLI.
- Never force-pushes main.
- Never reports a PR as merged when it is only open.
- Never reports production as deployed when only repository changes
  were merged.
- Never claims CI is green without reading the actual check
  conclusions.
- Never treats a stale Codex review (older than PR head) as current.
- Never touches production without an explicit owner instruction.

## Source map

| Rule | Source |
|---|---|
| Release workflow, never claim without evidence, bugs audited before fix | `AGENTS.md` §2 |
| Every PR must pass CI + review (no yellow/red, MUST be green); no production touch; no built-in GitHub connector | `AGENTS.md` §3 |
| Git workflow (branch → PR → CI → review → merge on green), no force-push main, no failing-check merge, no CI "fixes" | `AGENTS.md` §4 |
| Local checks before push | `AGENTS.md` §5 |
| Deploy rules (owner order, CI green, backup verified, preflight, official mechanism, no infra/data changes) | `AGENTS.md` §6 |
| Code quality / test gates (no disabling lint, no skipping/deleting tests, no fake success, fix root cause) | `CLAUDE.md` §23 |
| Git workflow | `CLAUDE.md` §26 |
| Production deployment | `CLAUDE.md` §27 |
| Verified command reference | `CLAUDE.md` §36 |
| Review diff before PR | `CLAUDE.md` §38 |
| Completion standard (evidence for scope, tests, diff, PR, CI, merge, deploy) | `CLAUDE.md` §40 |
| CI checks (foundation + frontend-static jobs) | `.github/workflows/validate-rebuild.yml` |
| Codex review via `@codex review` PR comment at latest head; no Codex CLI | Owner instruction (parent task brief) |
