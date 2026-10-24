# Running one roadmap batch (LFAMILIA STORE)

The owner sends audit/roadmap work as numbered batches. Each batch is a small,
ordered slice of one long plan, and the batches are not interchangeable: a later
batch must not absorb an earlier batch's fix, and an earlier batch must not
"while I'm here" touch a later batch's scope.

## 1. Verify the base before touching anything (read-only)

The work order describes a starting state; that description is a claim.

```bash
cd /home/hatch/workspace/LFAMILIA-STORE          # the main checkout, not /root/workspace
pwd && git rev-parse --abbrev-ref HEAD && git log -1 --format='%h %ci %s'
git status --porcelain                            # must be empty before you branch
git worktree list                                 # other batches' worktrees exist — leave them alone
git ls-remote origin 'refs/heads/<integration-branch>' 'refs/heads/main'
git rev-list --left-right --count main...HEAD      # how far the integration branch is ahead
git ls-files | grep -E '(^|/)\.env($|\.)'         # expect only .env.example
git config user.name; git config user.email       # hook smoke tests can have rewritten these
```

Then confirm the open integration PR points at the same SHA:

```bash
gh pr list --head <integration-branch> --json number,state,headRefOid,mergeable,mergeStateStatus
gh pr checks <number>
```

Rules:
- Local HEAD == `origin/<integration-branch>` == PR head. Any unpushed local
  commit is unfinished delivery, not progress; resolve it before starting a new
  batch.
- If `git config user.name`/`user.email` does not match the identity the repo
  expects, report it as a decision — do not silently rewrite git config mid-batch.
- Every other worktree must be clean. If one is not, report it and stop — do not
  stash, reset, or delete someone's in-progress work.
- A red CI run on the base is expected and belongs to the batch that fixes it;
  read `gh run view <id> --log-failed` and name the failing file:line before
  planning.

## 2. One branch per batch, based on the CURRENT integration point

```bash
git worktree add -b fix/bNN-<short-slug> /home/hatch/workspace/LFAMILIA-STORE-bNN <base>
```

- **Find the real base from the addendum, not from an old roadmap.** Once the
  integration PR merges, every remaining batch branches from the latest `main`
  and targets `main` — the "branch from the integration branch" pattern dies
  with that merge. Re-read the addendum each session; a superseded base wastes a
  whole PR.
- The integration branch may still be checked out by an old worktree, so
  `git checkout main` in the main checkout fails with "already used by worktree".
  Detach the old worktree (`git -C <old-worktree> checkout --detach`) or create
  the branch directly with `git checkout -b <branch> <sha>` — never delete a
  worktree that holds someone's work.
- Never reuse another batch's worktree or branch; two writers on one branch is
  the failure mode the worktree-per-batch rule exists to prevent.
- Confirm the new worktree is clean and at the expected SHA before editing.

## 2b. Commit and hand over per item

One item = one commit = one review hand-off. After each commit, run the
self-review and stop; do not start the next item until the owner has relayed a
verdict for that head. A chained set of commits gets one verdict for its head
and leaves the middle commits unvetted.

When the guard writes verdicts to `~/workspace/review-guard/verdict-<sha>.md`,
read the file and check the SHA in its title against `git rev-parse --short
HEAD` before treating it as current. Verdicts are per-SHA; a new commit voids
the old one.

## 3. Read the rules in authority order, then the batch scope

Owner instruction > `docs/PRD.md` > `AGENTS.md` > `docs/ADMIN-PANEL.md` >
`rebuild/*.md` and skills. Record any conflict you find between a skill and
`AGENTS.md` and follow `AGENTS.md`; say so in the report. `docs/archive/` is
history, not rules.

**Read a long brief end to end before answering anything about it.** The owner
sends audit/roadmap documents as PDFs and expects the whole file to be read, not
skimmed for the headline — extract with `pdftotext -layout <file> out.txt` and
read every page, including the closing sections, which usually carry the gates,
what must not be decided autonomously, and the required report format. Answering
from the first pages invites "read all of it first".

## 4. Reproduce the failing gate first

If the batch exists because a check is red, reproduce that exact failure before
changing code (run the named test group, or read the CI log to the failing
locator/assertion). A fix you cannot show failing first is a guess.

### Reproducing a browser smoke failure locally

A fresh worktree has no `vendor/`, `node_modules/`, or `.env` — the untracked
`.env` exists only in the main checkout, so copy it rather than rebuilding it.

```bash
cd /home/hatch/workspace/LFAMILIA-STORE-bNN/rebuild
cp /home/hatch/workspace/LFAMILIA-STORE/rebuild/.env .env
/home/hatch/php-cli/php-8.4/php /home/hatch/php-cli/composer.phar install --no-interaction
HUSKY=0 npm ci && npm run build
/home/hatch/php-cli/php-8.4/php tests/Browser/fixture.php        # browser-admin / browser-checkout-game rows
/home/hatch/php-cli/php-8.4/php artisan serve --no-reload --host=127.0.0.1 --port=8123
```

- **Pick a free port; never kill a server you did not start.** Another checkout's
  `artisan serve` often already holds 8000 (an earlier session's pid, not yours).
  `ss -ltn | grep 8000` first, serve on a spare port, and stop only your own pid.
- **Run a temporary probe script from inside `rebuild/`.** Node resolves imports
  relative to the script's own directory, so the same file under `/tmp` fails with
  `ERR_MODULE_NOT_FOUND: Cannot find package 'playwright'`. Copy it into `rebuild/`,
  run it, delete it — it must not appear in the final diff.
- **The browser suite may leave the app.** A script that first navigates to an
  external comparison site cannot run where egress is blocked; extract only the
  block you changed, run that, and label the external half as CI-only.

## 5. Hard stops

Stop and report instead of deciding:
- prices, markup, SKU-vs-source mapping and failover policy;
- UNKNOWN fulfillment outcomes, refunds, manual QRIS, gateway controls;
- anything on production/VPS, any push, PR, merge, or deploy.

Findings outside the batch's scope get written down for their own batch, not
fixed here.

## 6. Report format (one report, at the end)

1. Batch title and base SHA.
2. Branch and worktree path.
3. Status: READY / BLOCKED / NEEDS DECISION.
4. Findings verified this batch, each with `file:line` and evidence.
5. What changed (short).
6. Test/check results, each gate named with its own output — never inferred from
   another gate, never from a step that was skipped.
7. CI and review state, if a PR exists.
8. Open items and which batch owns them.
9. Skills used and skills skipped, with the reason.

Do not claim visual QA you did not perform, and do not report a batch as done
while its gate is still red.
