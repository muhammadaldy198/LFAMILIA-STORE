# HANDOFF — LFAMILIA STORE admin panel cleanup

Written: 2026-10-22 (session `20261021_094858_7238bfc5`)
Repo: `/home/hatch/workspace/LFAMILIA-STORE/rebuild` (branch `main`)

---

## 1. What we are doing and how far we got

**Goal:** tidy the LFAMILIA admin panel page by page, following the newest design
documents in `docs/superpowers/plans/` (the newest document always wins).

**Working rule from the owner:** commit locally per stage, **no push, no PR**.
All commits are collected and opened as **one single PR at the end** for a full audit.
Production deploy is forbidden.

**Progress:** 2 of 26 admin pages are done.

- Dashboard — done, committed.
- Admin & Akses (`/admin/access`) — done, committed, verified on real browser at
  mobile + desktop widths.
- 24 pages still untouched.

Local HEAD: `6d81eaca`. Production is still at `d573998` (untouched, deliberately).

---

## 2. What is finished — with proof

Four local commits sit on top of `941cd835` (which is the last merged PR, #294).
**None of these four are pushed.** `git log origin/main..HEAD` lists exactly these:

### `66688dc6` — Dashboard package
`feat(admin): dashboard strip, order chart for non-finance, human labels`

Files changed (7 files, +234 / −218):

- `app/Support/AuditLabels.php` — **new**. Human label dictionary moved out of
  `AdminAuditController` so both Audit and Dashboard use one source.
- `app/Http/Controllers/AdminAuditController.php` — duplicate label methods removed,
  now injects `AuditLabels`.
- `app/Http/Controllers/AdminDashboardController.php` — chart also computed for
  non-finance admins (order count), joins `payment_channels` for the real channel
  name, `limit(8)` → `limit(5)`, removed dead props `topProducts`, `webhookReady`,
  `canCatalog`.
- `resources/js/Pages/Admin/Dashboard.vue` — three separate Cards replaced by one
  `<section class="lf-admin-strip">` row, human labels for severity / payment
  status / payment channel / audit activity, dead computeds removed.
- `resources/css/admin.css` — new `.lf-admin-strip*` classes.
- `tests/Feature/AdminDashboardRestorationTest.php` — `topProducts` assertions
  flipped to `->missing()`.
- `tests/Browser/admin-ui-guards.test.mjs` — `topProducts` removed from key list.

### `e03e2a27` — self-inflicted regression fix
`fix(admin): scope four-column summary grid to dashboard only`

Files: `resources/css/admin.css`, `resources/js/Pages/Admin/Dashboard.vue`.

Reason: `.lf-admin-summary` is used by **11 pages** (Digiflazz, Reports, Payments,
Customers, Integrations, …). Commit `66688dc6` had silently changed the grid for all
of them. Fixed with a `.lf-admin-summary-4` modifier used only by Dashboard.

### `2416ae8d` — Admin & Akses page
`feat(admin): tighten access page, themed delete confirm, human activity labels`

Files changed (4 files, +71 / −30):

- `resources/js/Components/AdminConfirmDialog.vue` — **new** (34 lines), reka-ui
  Dialog, dark-theme confirm, reusable.
- `resources/js/Pages/Admin/Access.vue` — `window.confirm` replaced by the themed
  dialog; permission checkboxes grouped per menu with count badges; editor sheet
  `sm:max-w-3xl` → `sm:max-w-xl`; form grid 4-column → 2-column; static
  "Aturan Akses" card deleted; page layout collapsed to one column.
- `app/Http/Controllers/AdminAccessController.php` — summary counts now scoped to
  supported admin roles; each activity row now carries `action_label` from
  `AuditLabels`.
- `tests/Feature/AdminAccessRestorationTest.php` — the test now empties
  `admin_users` inside its transaction first (see "traps" below).

### `6d81eaca` — mobile/desktop fixes found by real browser check
`fix(admin): stack access email and badge, pin editor actions on mobile`

File: `resources/js/Pages/Admin/Access.vue` (1 file, +5 / −3).

- "Sedang digunakan" badge moved to its own line so it no longer overlapped the
  email in the accounts table.
- Editor footer made `sticky bottom-0` so **Simpan akun / Batal** is always
  reachable on a phone without scrolling 1.5 screens.

### Verification status (all green, run after `6d81eaca`)

| Gate | Result |
|---|---|
| `phpunit --filter "AdminAccessRestorationTest\|AdminPanelRestorationTest"` | **OK — 59 tests, 573 assertions** |
| `node --test tests/Browser/admin-ui-guards.test.mjs` | **30 pass / 0 fail** |
| `npm run check` (vue-tsc) | **0 errors** |
| `npm run build` | **built successfully** |
| `php-pint --test` on changed PHP files | **passed** (also enforced by pre-commit hook) |
| Real browser, 390px & 1440px, `/admin/access` | no horizontal overflow, no control outside viewport, summary strip 1 row desktop / 2 columns mobile, delete dialog fits (358px of 390px), editor save button reachable |
| DB after tests | `admin_users` still 1 row (`qa.popup@lfamilia.local`) — QA account intact |

Commits were made with the correct git identity `BETOL <betol@lfamilia.local>`.
`npm run test:hooks` was **not** run this session (it is destructive — see traps).

---

## 3. Not finished / in flight

- **24 of 26 admin pages untouched.** Pages still without the pop-up editor
  treatment: Settings, Digiflazz, Providers, Integrations, Support, Reports, Audit,
  Health, Notifications, CustomerDetail (plus all pages not yet reviewed at all).
- **`AdminConfirmDialog.vue` is only used by Access.vue.** Catalog, Customers,
  Fulfillment etc. still call the browser `confirm()`. Deliberate — add when those
  pages are worked on.
- **"Produk terlaris" has no home yet.** It was removed from Dashboard (correct per
  design: it belongs on the Produk page), but the Produk page does not show it yet.
  It currently only exists in Laporan/Reports.
- **`AUDIT-joko-20261022.md`** sits untracked in `/home/hatch/workspace/LFAMILIA-STORE`
  (outside the repo, 223 lines, dead-code reachability audit). Intentionally not
  committed.
- **Last owner question, answered but not acted on:** owner asked "Jadi gimana?
  Aman? Buat mobile dan desktop aman?" — answered yes with browser evidence, and the
  two defects found were fixed in `6d81eaca`. Nothing pending from that question.
  (There is no unanswered owner question at handoff time.)
- **PHP 8.4.27 security patch (PHP2Shell CVE-2026-109618 / CVE-2026-109619) is still
  the owner's task on the VPS.** Local PHP is 8.4.15; production runs 8.4.26. Do not
  touch production for this.

---

## 4. Owner decisions that are FINAL — do not re-ask

1. **Newest design document wins, absolutely.** Reference set is the 7 files in
   `docs/superpowers/plans/`; `2026-10-19-admin-panel-total-redesign.md` and
   `2026-10-19-admin-panel-implementation-plan.md` are the two that win.
   `CHECKOUT-LAB-MIGRATION.md` (13 Oct, says "admin stays white") is **stale and
   loses** — admin theme is dark (`--background:#09090b`). `ADMIN.md` is a photo of
   the current state, never an argument against the design.
2. **Commit locally per stage. No push, no PR until the end.** Then exactly one PR.
3. **No production deploy.** Production stays at `d573998`.
4. **Work in stages, page by page.** Do not sweep all 26 pages in one go.
5. **DB is `lfamilia_hermes`** (127.0.0.1:3307, root, empty password).
   `lfamilia_ci` is a shared main DB — never use it. Local data is **test data**,
   never production truth.
6. **No refund flow, ever.** Owner rule: NO-REFUND ABSOLUTE.
7. **Do not delete** `DigiflazzAutoSources` service or the `max-prices` endpoint.
   Do not rename the 188 route names.
8. **The pricing rule (nominal → game → global) is alive and must stay.** An earlier
   audit item calling it dead was withdrawn by the owner.
9. **Do not stop/kill/restart processes on the VPS** without explicit order.
   Relay/VPS access only when the owner explicitly asks.
10. **No passwords in chat.** QA accounts use the vault.

---

## 5. Exact next steps

1. `cd /home/hatch/workspace/LFAMILIA-STORE/rebuild`, confirm
   `git log --oneline -1` = `6d81eaca` and `git status` clean apart from
   `AUDIT-joko-20261022.md`.
2. Pick the next page in the agreed order. Recommended next: **Produk (`Catalog.vue`)**,
   because it is the biggest gap (pop-up editor missing, "produk terlaris" homeless,
   pricing rule duplicated in 4 places).
   - Read `docs/superpowers/plans/2026-10-19-admin-panel-total-redesign.md` §4.3 and
     the implementation plan's matching task before editing.
   - Diff the current page against the spec, report the gaps to the owner, get a go,
     then implement.
3. Keep the per-page loop identical to this session:
   - write/patch code,
   - run the parallel local gate batch (relevant phpunit filter + `php-pint --test` on
     changed PHP + `npm run check` + `npm run build` + `node --test tests/Browser/admin-ui-guards.test.mjs`),
   - verify visually in a real browser at 390px and 1440px,
   - commit with identity `BETOL <betol@lfamilia.local>`,
   - report once.
4. When all 26 pages are done: `git push`, open **one** PR, immediately request
   `@codex review`, fix findings, wait for CI, report the final CI result only.
   Deploy still requires an explicit separate approval.

### Environment traps (save yourself the pain)

- `php` is not on PATH: use `/home/hatch/php-cli/php-8.4/php`.
  Pint: `/home/hatch/php-cli/php-pint`. `php artisan tinker` does not exist.
- `npm run test:hooks` is **destructive**: it creates commits, resets HEAD, and
  leaves git identity set to `LFAMILIA Hook Smoke <hook-smoke@invalid.local>`.
  Never run it with uncommitted work; always re-check `git config user.name` after.
- Local PHPUnit runs against the real local DB, so leftover fixture rows break
  count assertions. This session two tests failed for exactly this reason:
  - `AdminAccessRestorationTest` — extra QA admin row. Fixed by clearing
    `admin_users` inside the test transaction.
  - `AdminPanelRestorationTest` — three fixture products
    (`browser-checkout-game`, `admin-manual-internal`, `checkout-lab-reference`)
    left behind by `tests/Browser/fixture.php`. Deleted manually; consider making
    that test's setup self-cleaning too.
- Headless Chromium at `/opt/meta-chromium/chrome` cannot reach `127.0.0.1` /
  `localhost` (blocked by `ERR_BLOCKED_BY_LOCAL_NETWORK_ACCESS_CHECKS`; flags tried
  and failed: `--no-proxy-server`, `--disable-features=LocalNetworkAccessChecks`).
  Use the native `browser_exec` tool for local visual checks instead.
- `.lf-admin-summary` is shared by 11 pages — always add a modifier class, never
  change the base rule.
