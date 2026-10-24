# Removing the superseded presentation layer

The panel is rebuilt; the old layer is still in the tree. Deleting it is mechanical but it
fails in four specific ways, and each failure looks like something else.

Run this only after the owner has approved the deletion. Until then the old layer is the
revert path.

## 1. Inventory before deleting — count consumers, not files

```bash
# components: any import left?
grep -rn 'AdminShell\|AdminResponsiveTable\|AdminIcon\|AdminConfirmDialog' resources/js tests app \
  --include='*.vue' --include='*.js' --include='*.mjs' --include='*.php'
# helpers
grep -rn "lib/ordering" resources/js tests .github
# CSS classes, per class, with counts
grep -rno "lf-admin[-a-z]*" resources/js --include='*.vue' --include='*.js' | awk -F: '{print $3}' | sort | uniq -c
```

A component with zero importers goes. A class with a non-zero count stays — and the count
tells you *which* classes stay. In this repo the survivors were exactly `lf-admin`,
`lf-admin-auth` and `lf-admin-logo`, used by the four login screens that were never
migrated to `adm-*`.

## 2. Prune the CSS with a paren-aware splitter

`scripts/prune-css-selectors.py` drops every rule whose selectors only reference the dead
prefix, keeping the named survivors:

```bash
python3 scripts/prune-css-selectors.py resources/css/admin.css \
    --drop lf-admin --keep lf-admin --keep lf-admin-auth --keep lf-admin-logo --write
```

**Why not a regex or `sed`:** a selector list splits on commas, and `:is(.a, .b)`,
`:has(> :nth-child(8):last-child)` and `[data-slot=x]` all contain commas at depth > 0.
Splitting those naively produces half-selectors, and the corrupted file surfaces later as
`[@tailwindcss/vite:generate:build] Missing opening (` pointing at the stylesheet with no
line number — which reads like a Tailwind bug, not like a broken edit.

After any such edit, `npx vite build` is the only gate that catches it: ESLint and vue-tsc
never parse CSS.

Ceiling of the scanner: it tracks braces and brackets, not comments, so a comment containing
`{` or `}` throws the block scan off. The script's balance check then refuses to write and
exits 1 — remove or reword that comment and re-run. It also does not merge duplicate rules
left behind by a partial selector prune; those are harmless, just untidy.

## 3. Delete endpoints only the old UI called — together with their test

For each route, check whether anything in `resources/js` still calls it. A route with no
caller and a feature test asserting it is dead weight, but the three parts must go in one
commit: `Route::get(...)` line, the controller method, the test method (plus any
`assertForbidden` line for it inside another test). Leaving the test behind turns a clean
deletion into a red suite that looks like the deletion broke something.

Watch for props that exist only to feed the deleted screen: after removing a "popular
products" block from Promo, `popularProducts` still shipped from the controller and one
feature test still asserted `has('popularProducts')`. Delete the query, the prop and the
assertion together.

## 4. Verify in this order

1. `npx vite build` — CSS syntax and orphan imports.
2. `npx eslint resources/js/Pages/Admin/*.vue resources/js/Components/Admin/*.vue`.
3. `node --test <the guard files the CI workflow names>`.
4. Browser: the login page first (it is what kept the surviving classes), then every
   route, then 375px with `scrollWidth - clientWidth === 0`.

Report the deletion as files and line counts (`17 files, −848 lines`), plus what survived
and why. "Dihapus semua" without the survivor list invites the owner to find the one page
that broke.
