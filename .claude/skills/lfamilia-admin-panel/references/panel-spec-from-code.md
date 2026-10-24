# Building `docs/ADMIN-PANEL.md` from the code, not from the prototype

The owner sends a single-file HTML prototype and says it is a **referensi** for the panel.
That word decides everything: the prototype supplies the **look**, the code supplies the
**inventory**, the owner supplies the **choices**. Mixing the first two produces a spec that
lists the prototype's 23 sections as if they were the system, and gets
"JANGAN JADIKAN PATOKAN — BEDAKAN REFERENSI SAMA PATOKAN".

## What each source is allowed to contribute

| Source | Contributes | Never contributes |
|---|---|---|
| Prototype HTML | palette + hex, type scale, density, radius, border/shadow rules, table and sheet shapes, sidebar grouping pattern | page list, field names, columns, actions, menu labels |
| `routes/web.php` + controllers | which pages exist, which actions exist, who may call them | look |
| Inertia props | what each page actually displays | look |
| Vue pages | form fields, editor sections, button labels as shipped | look |
| Owner | business choices (who may do what, limits, exceptions) | — |

Extracting the palette from the prototype is legitimate and cheap: pull the `<style>` block
and read `:root` custom properties verbatim. Do not restyle them, do not invent a second
palette, do not carry the dark variant (this store is light).

## Enumerate the real panel — three passes

**1. Every admin route, with its controller and method.**

```python
import re
src = open('rebuild/routes/web.php').read()
for m in re.finditer(r"Route::(get|post|put|patch|delete)\(\s*'([^']+)'\s*,\s*\[?([A-Za-z]+)::class\s*,\s*'([A-Za-z]+)'", src):
    print(m.group(1).upper(), m.group(2), f"{m.group(3)}.{m.group(4)}")
```

Routes declared inside `Route::prefix(...)->group(...)` print without their prefix, so match
them against the controller, not the URL. What matters is the set of **actions per page**.

**2. Every controller: public methods and the page it renders.**

```python
import re, os
base = 'rebuild/app/Http/Controllers/'
for f in sorted(os.listdir(base)):
    if not f.startswith('Admin'): continue
    s = open(base + f).read()
    print(f, re.findall(r'public function (\w+)\(', s), re.findall(r"Inertia::render\('([^']+)'", s))
```

**3. Every Inertia prop set — this is the page's actual content.**

```python
for m in re.finditer(r"Inertia::render\('([^']+)',\s*\[(.*?)\]\)", s, re.S):
    print(m.group(1), re.findall(r"'(\w+)'\s*=>", m.group(2)))
```

Prop keys name the panels: `metrics, chart, recentOrders, activities, integrations` is a
dashboard; `gateways, channels, routes, transactions, summary, paid, pending` is a payment
workspace holding six concerns on one screen. A page whose prop list is three times longer
than its neighbours is the page that needs splitting — that is a finding the prototype can
never give you.

## What the spec document must contain per page

For each page, four lines and nothing more:

1. **Untuk apa** — one sentence in shop language.
2. **Isi** — the panels/sections, derived from the Inertia props and the shipped Vue template,
   with the real column and field names.
3. **Aksi** — the buttons that exist, each mapped to its route.
4. **Catatan** — controller that serves it, and whether it is enough.

Then the cross-cutting sections that the prototype cannot supply and the code must:
roles and permissions (`AdminPermissionService::DEFINITIONS`), actions requiring
confirmation, empty states, and the "what we deliberately did not port" list.

## Verify the written spec against the shipped UI before delivering it

After the spec is drafted from routes/props, run one cross-check pass per direction. It finds
the findings that make the document worth reading — and it is the only way to catch a page
that promises a capability it does not have.

```python
# endpoints with no caller in resources/js  (dead buttons waiting to happen)
import json, os, re
routes = json.load(open('/tmp/admin_routes.json'))          # from artisan route:list --json
js = "".join(open(os.path.join(d, f), errors='ignore').read()
             for d, _, fs in os.walk('rebuild/resources/js') for f in fs if f.endswith(('.vue', '.js')))
for uri, method, name, action in routes:
    if method != 'GET' and '/' + uri not in js:
        print('no caller:', method, uri)
```

Then the reverse: every endpoint the UI calls must exist in the route list. A `fetch('/admin/x')`
with no route is a button that 404s.

Two more checks that produce spec text rather than bugs:

- **Claimed text vs rendered text.** Read each page's real headings and buttons from the
  running app (`document.querySelectorAll('main h2')`, `main [role=tab]`, `main button`) and
  make the spec's "Isi"/"Aksi" lines quote those, not the prototype's. A tab list taken from
  the prototype misses tabs the page actually renders.
- **Confirmations, per route.** `artisan route:list --json` and count the middleware:
  which mutations carry `RequireAdminPasswordConfirm`, which are Super-Admin-only, which are
  `throttle:admin-sensitive`. A password prompt that exists only in Vue (client-side) is a
  spec caveat, not a confirmed protection — say so in the "batas yang diketahui" section.

Deliver the spec with a short "batas yang diketahui" list: endpoints with no button, buttons
with no screen, pages still on the old styling, and any confirmation that is client-only.
That list is the document's most useful part; it is what the next session works from.

## Close the loop on the document set

When the spec is rewritten, the neighbours drift in the same commit:

- `docs/STATUS.md` — replace completion claims with the current state (which branch, merged
  or not, deployed or not) and the real test numbers.
- A pre-build working document (`DAFTAR-PERIKSA-*`, "daftar periksa") is archived to
  `docs/archive/<date>-<phase>/` with an `> **Arsip.**` line at the top, never left beside the
  live spec where it reads as a to-do list.
- An audit document whose section has since been executed gets a dated line at the top saying
  which parts are done and which still stand.
- Check every `](*.md)` link in the files you touched; a missing `STATUS.md` is a broken link
  in `README.md` and `AGENTS.md`.

## Renames are a spec decision, not a copy error

When the prototype mixes languages (`System Health`, `Audit Log`, `Manual`), rename to
Indonesian in the spec **and record each rename in a table with its reason**. Silently
keeping the English label looks like carelessness; silently renaming without a record makes
the next agent think the prototype and the spec disagree.

## Do not answer "what does this controller do" with a plan

A question about a controller is a question. Answer it in plain language (route → controller
→ what it validates, calls, and returns), one screen at most, then stop. Attaching the next
step or a status report to it reads as not listening.

## Controller fate: assume keep, prove delete

A rebuild changes presentation, so the default answer to "which controllers go?" is **none**.
Enumerate first, then say which need *changing* (a missing mandatory reason, a permission
tightened to Super Admin, a dead gateway marked inactive) and which are genuinely unused —
with the route list as evidence. "Maybe some get deleted" from the owner is not a licence to
delete on suspicion.
