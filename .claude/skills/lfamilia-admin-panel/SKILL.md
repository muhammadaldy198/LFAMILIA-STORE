---
name: lfamilia-admin-panel
description: "Use when redesigning LFAMILIA admin panel pages."
license: MIT
version: 1.0.0
author: lfamilia
metadata:
  source: docs/superpowers/plans/, rebuild/ADMIN.md, rebuild/docs/*parity*.md
  hermes:
    tags: [laravel, vue, inertia, admin-panel, design-system, lfamilia]
    related_skills: [lfamilia-release-guardian, inertia-vue-development, vue-best-practices]
---

# LFAMILIA Admin Panel — page-by-page redesign

The admin panel (Vue 3 + Inertia + `resources/css/admin.css`) is being rebuilt against written design plans, one page at a time. This skill is the loop for that work.

## When to Use

Use when the task is any of:

- "BAGIAN <halaman> ITU HARUSNYA GIMANA?" — comparing a page against the plan.
- "SUDAH SESUAI RANCANGAN KAH?" — gap analysis for one page.
- Building or restyling an admin page under `resources/js/Pages/Admin/`.
- Cleaning raw enum/code leakage out of an admin screen.
- Auditing admin pages for dead props, dead computed values, or unused Inertia props.
- "CARI TEMPLAT BUAT PANEL ADMIN BISA GA?" — shortlisting third-party admin templates (see `references/template-sourcing.md`).
- "BUATIN TEMPLATE DUMMY DULU … BIAR SAYA LIHAT HASILNYA" — build a runnable prototype outside the repo; the recipe and the shadcn-vue registry workaround are in `references/template-sourcing.md` ("Build the dummy").
- Writing or rewriting `docs/ADMIN-PANEL.md` — the panel spec. Its inventory comes from routes/controllers/Inertia props, never from the prototype: `references/panel-spec-from-code.md`.
- The owner sends a **single-file HTML prototype** he made himself and says "baca/lihat file itu, diskusi dulu" — review procedure in `references/template-sourcing.md` ("Reviewing the owner's own prototype").

Do NOT use for payment, fulfillment, or Digiflazz backend behaviour — those have their own guardian skills.

## Precedence — which document wins

1. **Explicit owner instruction** beats every document.
2. **Newest design document wins absolutely** when plans conflict. The owner states this repeatedly and gets angry when an older plan is quoted back as authority.
3. Archive-status documents (`Status arsip`, `Status: Rancangan awal/arsip`, `HISTORICAL SNAPSHOT`) record **design intent, not completion**. Their unchecked `- [ ]` boxes are not a to-do list — much of that work shipped in later PRs. Never report "belum dikerjakan" from a checkbox.
4. `ADMIN.md`, `ARCHITECTURE.md`, and the `*-parity.md` files describe **current runtime state**. They are a photograph, not a plan: they must never be used to refute a newer design document. When code and plan disagree, say so — do not silently treat the doc as wrong.
5. A doc dated earlier that contradicts a newer visual direction is dead. Example: an older checkout migration note claiming "Admin stays white" loses to the later dark-theme design, and the shipped `admin.css` proves which side won.

**Current visual direction (owner decision): light.** Light canvas, near-black sidebar, a single lime accent, 13px dense type, grouped sidebar — the owner chose light explicitly after seeing both, so do not re-open the light-vs-dark question or quote the dark navy theme as the target. Login, forgot-password and reset-password screens go light in the same pass; a light panel behind a dark login reads as two different products.

## The rebuild order (current — overrides older advice below)

The owner's final instruction, in his words: *"Backend mau gamau dirubah juga nanti, dibilang lagi buat frontend dulu, nanti baru backendnya dicocokkan atau dibuang sekalian."*

**Frontend first. Backend is reconciled afterwards, and may be deleted, not preserved.** This reverses the older "split into three layers, keep 1–2, never touch 3" answer. Do not refuse a rebuild because controllers exist; do not audit the old panel to decide what survives.

- **The template HTML is the panel's inventory.** Earlier guidance said the prototype is only a look and the page list must come from routes. That is now wrong for this task: the owner said *"27 menu panel ada semua, ambil lah contoh template LFAMILIA PANEL HTML yang tadi gua kirim"* and then accepted **19** (the template's sidebar), with Notifikasi in the top bar instead of the sidebar. He later retracted the 27 himself: the run is **20 numbered menus + 2 detail pages = 22 screens**. Never quote 27 back to him and never offer to add "the missing 7" — that reads as inventing menus he never asked for.
- **The old panel is banned as a reference.** *"Panel lama itu udah ga berguna, ga boleh dijadiin acuan."* Do not list its 23 screens, do not ask which of them to keep, do not carry `admin.css` / `AdminShell.vue` / the old pages forward. A capability the new panel needs that has no route gets its route and controller **created**; one the new panel does not need gets them **deleted**.
- **The panel decides; the backend follows — never the reverse.** The owner's rule, verbatim: *"Controller atau route harus disesuaikan sama panel admin baru, bukan panel admin baru yang harus sesuai sama controller dan route yang ada."* So when a route has no control in the new panel, the default verdict is **the route is dead**: delete the route, the controller method, the service it alone used, and the tests that exercise it. Never grow a screen or a section just to "cover" a surviving endpoint — that is how a dropped feature walks back into the design. Ask the owner only when the capability is written in the PRD and the panel simply forgot it; then it is "add the control", not "restore the old screen".

  This is the failure this rule exists for: `PUT /admin/catalog/margin` still existed, so "margin global" was re-added to the new Produk page as a covering feature, and the owner answered *"BUKANNYA KEMAREN UDAH DIPUTUSIN GA ADA MARGIN GLOBAL?"*. Enumerate the orphans with `scripts/orphan-routes.py` and decide each one **against the design**, not against the backend.
- **Every form is a centred pop-up, never a side panel.** *"Ga mau banyak form, ga mau formnya malah editnya dibawa ke bawah, bukan pop-up."* and then, when a `Sheet` appeared: *"BUAT POP UP YA EDITNYA JANGAN DI SAMPING GITU."* Lists are lists; editing happens in a `Dialog` centred over a dimmed page (`AdminModal.vue`), full-screen on phones. No inline edit blocks, no settings forms stacked under a table, no right-side sheet. The template marks which button opens which surface (`data-sheet-open="sheet-x"`); read that markup for the fields, then render it as a centred dialog — the surface is the only thing that changes from the template.
- **Components: shadcn-vue** (`https://github.com/shadcn-ui/ui`), already vendored in `resources/js/Components/ui/`.
- **Copy verbatim from the HTML.** *"Jangan ngubah kata kata, sesuaikan aja sama yang di HTML."* Quote labels, descriptions, empty states and button text exactly; no paraphrasing, no "improving" the Indonesian.

### Planning ritual — all 19 menus, item by item, before one line of code

1. One menu at a time — **one menu per message, never two**. Read its `<section class="page" id="page-...">` from the template and list its parts in the template's own words: head + description + header buttons, stat boxes, filters, advanced filters, toolbar, table columns, pagination, activity blocks, sub-pages. State the **line range** of that section and of each sub-block (e.g. `1118–1240`, head at `1119–1122`) — he will ask "ada di bagian mana?" and the answer must be a path plus line numbers plus the literal HTML, not a re-description.

   **A menu's inventory is read from the template only — do not open a controller, route list, or model while listing a menu.** Controllers come after the menu is locked, to reconcile what the template asks for against what the backend can send (and to create or delete what it cannot). Shrinking the list to what a controller supports today, or asking "backend cuma punya 6 aset, betul?" while planning, is the failure the owner named: *"Ngapain cek langsung dari Controller sih? Kan gua bilang template jadiin contoh dulu, Controller itu malah nanti bisa aja ada yang dihapus."* The template is the example; the backend is reconciled afterwards.

   A menu includes the sheets its buttons open. When a menu carries a heavy editor (Produk's `Editor Produk` has five tabs and ~40 fields), the field list of that sheet is part of the menu's discussion: enumerate the tabs, then the fields per tab, and let him strike them one by one. Do not defer the sheet to build time and do not summarise it as "detail editor".

   **Number the menus from the sidebar, never from the file.** The `<section>` blocks sit in the HTML in authoring order, which does not match the navigation — `page-access` appears before `page-settings` in the file, while Integrasi, Kesehatan and Audit come before both in the sidebar. Walking the file order silently renumbers every menu after the divergence and the owner catches it ("Menu 16 bukannya harusnya integrasi?"). Enumerate the nav once before menu 1 and keep that list beside you:

   ```python
   for m in re.finditer(r'<button class="nav-item[^"]*" data-page="([^"]+)">(.*?)</button>', sidenav_html, re.S):
       print(m.group(1), re.sub(r'<[^>]+>', '', m.group(2)).strip())
   ```

   The walkthrough also counts `page-presentation` as its own menu, so the run is **20 numbered menus from 19 sidebar items**; the locked numbering is in `references/template-panel-inventory.md`. When he says a menu number and it does not match yours, assume your numbering is wrong, re-read the nav, and say so in one line instead of defending the list.

   **Read the number out of that table, never off the `<section>` list.** From 16 on the file order disagrees with the sidebar (`page-access` and `page-settings` sit before `page-integrations`, `page-health`, `page-audit`), so answering "menu 16" from byte offsets silently shifts six menus. And do not announce that the walkthrough is finished until number **20 (Pengaturan)** has actually been listed: the owner counts the menus he has seen and answers a premature "semua sudah final" with *"Terakhir pengaturan bodoh siapa yang bilang udah selesai?"*.

   **A tab strip over a registry shows the registry, not the template's tabs.** Integrations are an `IntegrationRegistry` list — nine today (Digiflazz, Validasi Akun, Midtrans, DOKU, Resend, Google, Notifikasi Telegram, Notifikasi Discord, Cloudflare Turnstile) while the template draws six. List every registry entry as a tab, keep the template's per-tab layout (header badges, 3 status boxes, credential fields with the "kosong = pertahankan" pattern, `Simpan integrasi` / `Tes koneksi`), and say which tabs the template omits. Same for the collapsible webhook/callback address block: derive the rows from the definitions that actually declare an endpoint instead of typing the template's four, so a new integration adds its address without a code change. A template count is never the count when a registry backs the screen.
2. Quote every label, description, hint, empty state and button text **character for character** from the HTML. `Jangan ngubah kata kata` is literal: no synonyms, no title-casing, no "improving" the Indonesian.
3. Ask **per item**: "ya atau buang", naming each element exactly as the template labels it. Never "mana yang mau dipertahankan" — that hands the decision back to the owner and gets read as building to my own taste.
4. End each menu's report with **exactly one** closing question ("ada yang dibuang dari daftar di atas?"). Do not append a numbered checklist of eight sub-questions — that gets "APASIH" — and do not dump a 30-item questionnaire in one message. Element-by-element means the list *is* the questionnaire.

   After the item list, add a short numbered **`Rekomendasi saya`** block (2–4 items: what to cut because no endpoint backs it, what to keep because the data is real, one addition worth making). The owner answers per number (`1 oke`, `2 buang`, `7 jangan lupa …`) and that closes the menu in one round — much faster than him auditing every element. Keep each item one sentence with the reason, and never let a recommendation contradict the template silently: say which template element it changes.
5. When he answers "menu N clear" / "cocok", lock it and move on. Never batch menus, never jump ahead, never start coding a "sample page to show direction" mid-plan — he already has the template screenshot for direction.
6. Only when all 19 are locked: write the final plan, get it agreed, then build.

**A stat strip in the template becomes stat boxes in the panel.** Where the HTML compresses counts into one inline sentence (`3 antrean · 2 penanganan manual · 2 gagal perlu ditangani · 1.186 terkirim hari ini`), the owner reads it as a row of `mcard` boxes like Dashboard and says so. When listing a menu, present such a strip as N boxes (number + label + small note) and flag it as a change from the template's rendering.

**One price rule — decided, do not re-open.** Pricing inputs existed in three places (Digiflazz markup/comparison, per-product margin, per-nominal price mode) with three vocabularies and no way to tell from the screen which one wins. His verdict: *"BUANG LAH TOLOL APA GUNANYA TERLALU BANYAK ATURAN HARGA … ADA DI PRODUK ADA DI NOMINAL JUGA ADA."* Dropped: per-product `Margin produk`, `Tambahan modal game` + `Markup jual game`, the four per-nominal price modes, and the whole `Pengaturan harga lanjutan` block above the product list (the "3 usulan harga", the 212-of-248 preview, the two-stage uplift).

The locked shape, in his words: markup is born **at import**, per game, from Digiflazz — seller price `+2%` becomes modal, modal `+10%` becomes the sell price. After import, a price changes only at the nominal: **fixed rupiah** or **markup % from modal**. No rule lives in the Produk page and none in the Digiflazz page.

**Those percentages are field defaults, never constants in code.** *"Aturan itu ga dijadiin di kode ya, aturan % harus bisa input sendiri, boleh jadiin angka default tapi masih bisa diganti."* Every import shows both inputs pre-filled with 2 and 10, and the stored result is whatever was typed at that moment. Same for any per-category variant: a pre-filled number, not a branch.

**Margin global is not a thing here — it was deleted, not hidden.** `PUT /admin/catalog/margin`, `POST /admin/catalog/margin/preview` and `AdminPricingPreviewService` are gone, together with the "pratinjau margin" block that recalculated every automatic product at once. The Produk page carries exactly two numbers, both inside the import pop-up: tambahan modal (%) and markup jual (%). Do not resurrect them because an old route or service still sits in the tree: a backend action with no control in the panel is dead code, and the panel does not grow a screen to justify it. A mass-recalculation preview is also dangerous by default — its margin field opens at 0%, so one "terapkan" after an unread preview re-prices the whole catalogue downward.

**The only three prices are seller → modal → sell.** Anything else that looks like a price input (a "max price", a per-source price, a baseline) is a guard or an internal tolerance, not a fourth price. When the owner asks what a stray pricing field is, answer in those three terms or delete it; explaining a fourth number is what produces *"TERLALU BANYAK HARGA HARGA ANJING RIBET BANGET"*.

When the owner says `tunda dulu` on a sub-topic, park it and return to it after the menu list is finished; do not re-raise it in the same breath.

**Template → shadcn-vue component mapping** (decide it while listing, not at build time):

- single-choice dropdown (`<select>` with one selected option) → `Select`
- multi-choice list (`<select multiple size="3">`, e.g. voucher Cakupan kategori / Cakupan produk) → `Combobox` with search + checkboxes, chosen values rendered as chips inside the field
- anything opened by `data-sheet-open` / `data-sheet` → centred `AdminModal`, never a `Sheet` and never an inline block under the table
- `data-confirm` / destructive row actions → `AdminConfirmDialog`, never `window.confirm`
- date fields → date picker, but the label keeps `(WIB)`

Ask about a mapping only when it changes behaviour the owner can see (e.g. Combobox vs a ctrl-click list); otherwise take the mapping and say which one you took in one line.

**A field that is concatenated into a provider request is a closed list, not free text.** Customer input keys are joined into `customer_no` (`{{user_id}}({{zone_id}})`) and sent to Digiflazz, so a typo in a key silently blocks the product from being sold. Render such keys as a Select limited to what the controllers read, keep drag-reorder for the order (it changes the wire format), and print the composed result under the list.

### The shipped layer

All 20 menus plus the two detail pages (Detail Pesanan, Detail Pelanggan) now render on the new layer; nothing imports `AdminShell.vue` any more (`search_files` for `AdminShell` under `resources/js` returns nothing). New pages import:

- `Components/Admin/AdminLayout.vue` — dark grouped sidebar, top search, notifications, mobile drawer;
- `Components/Admin/AdminModal.vue` — the only edit surface: reka-ui `DialogRoot` centred, `wide` prop, `Tutup` header button, `#footer` slot for `Batal`/`Simpan`;
- `Components/Admin/AdminMultiSelect.vue` — Combobox multi-pick (voucher scope);
- `lib/adminFormat.js` — `num`, `rupiah`, `wibDateTime`, `wibTime`, `wibDate`, `shortDate`, `label`, `tone`;
- `adm-*` classes from the second half of `admin.css`.

The old `.lf-admin-*` block is dead weight — every page renders on `adm-*`. Do not add new `.lf-admin-*` rules. When the owner approves deleting it, keep exactly the three classes the four login screens still use (`lf-admin`, `lf-admin-auth`, `lf-admin-logo`); pruning all of them leaves `/admin/login` unstyled, because those pages were never migrated. Procedure, the paren-safe selector pruner and the verification order: `references/legacy-layer-removal.md` + `scripts/prune-css-selectors.py`.

### Self-review sweep before moving to the next menu

After a batch of menus ships, the owner asks some form of *"ada yang slop ga?"* before letting the next menu start. Run the sweep yourself and answer with findings, not reassurance. Do not run it earlier — mid-batch it reads as stalling.

1. Read each new page's **rendered text nodes and every `<button>`**, not the script: `re.findall(r">([^<>{}]{3,})<", template)` per file gives the literal copy, and a regex for `<button>` tags lacking `@click` / `type="submit"` gives the dead ones (disabled pagers are legitimate; a control with state but no consumer is not).
2. Grep the page for local state that nothing reads (`selected`, `ref([])`, a toggle whose value only styles a label). Cross-check the controller: if `export`/`destroy` already accepts `selected`, the column is a missing button, not a stray column.
3. Compare each capability the page advertises against the controller prop it needs. A recommendation caption with no upload input, a status column fed by the wrong field, a filter that runs in `computed()` over the loaded page — all three are defects, not styling.
4. Classify into exactly three buckets and send them in that order: **benar-benar salah** (dead control, wrong data in a column, promise with no wiring), **asal jadi** (redundant buttons, two primaries competing, control crammed into a text row, save button floating in the body instead of the footer), **perlu keputusan backend** (one or two items max, phrased as a yes/no).
5. Exclude what the template itself dictates. A group named `Integrasi` appearing both as a section and an item comes from the owner's HTML — say so in one line instead of listing it as slop.
6. Close with one question: fix buckets 1–2 now without touching the backend, or does he disagree with any item. Never start fixing before he answers — the sweep is a report.

   **Slop means UI the template does not have.** The sweep above finds defects; the slop pass finds *inventions*: an explanatory paragraph under every card heading, a "Geser tabel ke samping" helper the template never prints, six stat boxes where the template has one sentence, static sub-labels under every number, hardcoded hex, `>Edit<`. Every one of those is a **count you print** (ours vs the template's), not a screenshot impression. Probes, the exact regexes, and the list of what is *not* slop (★ ratings, `Terapkan`+`Hapus filter`, `Buka`/`Atur`) are in `references/slop-audit.md`. Pure deletions that restore the template can be fixed in the same pass and reported with the count; anything that changes layout is still a report first.

A "fresh" build that turns out to be the old pages reshuffled is the single worst failure mode here: *"cuma nambal nambal doang, kerjaannya cuma dipindah-pindah, bukan rombakan total."* If the new structure keeps the old page count and the old page names, it is a patch, not a rebuild — say so before delivering it.

When he points at a screenshot and says "di gua ga ada", the answer is the file path plus line number and the literal HTML block — not a re-description. He cannot open `.md` files or read code; quoting the HTML verbatim is the only way he can check a claim.

See `references/design-docs.md` for the document inventory, what each one governs, and the known capability gaps no plan covers. `references/slop-audit.md` holds the AI-slop probes (template-vs-ours counts, what counts as invention, what is not). `references/template-panel-inventory.md` holds the accepted 19-menu order, the `<section id="page-...">` map, and the per-menu block checklist to read out during the planning ritual. The product-level rules a page must obey (statuses, refund split, payment channels, day boundaries) are written in `lfamilia-product-spec`; read it before promising a screen that shows money, status, or a refund. See `references/page-patterns.md` for the reusable layout recipes (summary strip, grid modifier, sheet widths, confirm dialog) so a new page does not reinvent them. See `references/catalog-page.md` for the Produk/Kategori contract, its audit checklist, and the guard tests that break when its structure moves. See `references/pre-push-self-review.md` for the defect sweep to run before pushing a branch — the owner expects Codex to find nothing, so find it first. `references/visual-audit.md` + `scripts/visual-audit.js` hold the measurable browser probes used by the visual audit section below. The generic, repo-agnostic version of that sweep (with a calibrated inventory script) lives in the `scenario-review` skill; use it when the work is not an admin page. `references/template-sourcing.md` holds the verified template shortlist, the compatibility test against `components.json`/`package.json`, and the reason a template swap cannot fix a broken cascade.

## Read before opining

When the owner asks "what should this page look like" or "does this match the plan", read the **full** documents first — every plan, not a grep of the section. Reading partially and then answering from memory is the single fastest way to be corrected, and the correction is harsh. If the read is large, say what you read and how many lines before drawing conclusions.

## Page loop

For each page, in this order:

0. **Resuming from a handoff?** Read `HANDOFF.md` (it sits in the repo parent, `/home/hatch/workspace/LFAMILIA-STORE/HANDOFF.md`, deliberately untracked) before anything else, then confirm its claims: `git log --oneline -1`, `git status`, `git config user.name/email`. The handoff names the next page and the exact sequence for it — follow that sequence over this skill's default, and do not re-litigate decisions it marks FINAL.

   A handoff's *recommendation* of which page comes next does not outrank the implementation plan's task order. When it suggests skipping ahead ("Produk first, biggest gap"), follow the plan's numbered order, say in one line that you are doing so, and start. Do not stop to ask the owner which page to do first — they read that as stalling on a question the plan already answers. When the owner names a page or section themselves ("di bagian ini dulu"), work only that one to completion before proposing anything else.
1. **Read the page and its controller together.** `resources/js/Pages/Admin/<Page>.vue` plus the Inertia props controller. Layout gaps are usually visible in both.

   Admin pages pack whole sections — even whole `<Table>` blocks — onto one physical line. Line-oriented reads (`sed -n`, `read_file` offsets) return an unusable wall and the output can collapse to "1 lines". Read them by character in Python instead:

   ```python
   lines = open(path).read().split('\n')
   for i in range(440, 545): print(i + 1, lines[i][:260])
   ```

   Grep for structure first (`<h2`, `<Sheet`, `editorTab`, `section class`) to get line numbers, then slice the ranges you need.
2. **Diff against the plan section** for that page. Produce a short list of concrete gaps with file:line evidence — not impressions.
3. **Report the gap list in plain Indonesian** (the owner is not a programmer). Numbered findings, each one sentence of what is wrong plus one line of what the plan asks for. Keep it short; the owner reads on a phone.
4. **Fix in one batch**, cheapest-visual-first: presentation-only changes before anything touching a controller.
5. **Run gates** (below), then commit.
6. **Ask before starting the next page** only if the owner signalled "bertahap"; otherwise continue.

## Gates — one parallel batch

Launch all of these simultaneously as separate processes and collect results at the end. Sequential preflight is why pushes crawl.

```bash
php vendor/bin/phpunit --filter '<TheRelevantTest>|<OtherTest>'   # only the tests covering this page
node --test tests/Browser/admin-ui-guards.test.mjs                 # static template guards, fast
npm run check                                                      # vue-tsc
npm run build                                                      # vite
~/php-cli/php-pint --test <changed php files>                      # mandatory for PHP
```

Run `pint` (write mode) once before `--test` when you added PHP; the guard fails on `class_attributes_separation`, `unary_operator_spaces`, and `not_operator_with_successor_space` by default.

`vue-tsc` needs the project flag — bare `npx vue-tsc --noEmit` fails with no `tsconfig.json`: `npx vue-tsc --noEmit -p jsconfig.json`. And PHPUnit here is not reachable through `artisan test`: run `./vendor/bin/phpunit tests/Feature/<File>.php` (one file, not the suite).

Bulk edits across a dozen `.vue` files are fastest as one Python pass (`re.sub` per page) followed by a single ESLint run — but a regex that removes markup must be anchored on the full element (`<p>…</p>\n` including indentation), or it leaves orphan tags. After any regex edit of a template, run `vite build`: a broken tag pair compiles fine under ESLint and vue-tsc and only fails at render.

When you extract a helper out of a controller and inject it, every closure that now calls it must capture it: `$logs->through(function ($row) use ($actorNames, $audit, $labels) { ... })`. A `fn () =>` arrow function captures automatically; a `function () {}` does not, and the failure surfaces only at request time as `Undefined variable $labels` inside a 500.

The same trap fires when you **add a new local variable into an existing closure body** — a `DB::transaction(function () use ($request, $package, $data, $audit) { ... })` that now needs `$product` or `$isActive` returns a 500 with `Undefined variable $product` while `php -l` and Pint both pass. Every new name in the body goes into the `use (...)` list in the same edit; the feature test is what catches it, so run the affected test file before believing the change compiled.

To inspect local data without a `mysql` binary, parse `.env` by hand and use PDO — `parse_ini_file()` chokes on Laravel's unquoted values:

```bash
php -r '$e=[]; foreach(file(".env",FILE_IGNORE_NEW_LINES) as $l){ if(str_contains($l,"=") && !str_starts_with(trim($l),"#")){ [$k,$v]=explode("=",$l,2); $e[trim($k)]=trim($v," \t\"\x27"); } } $p=new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]}",$e["DB_USERNAME"],$e["DB_PASSWORD"]); $p->exec("USE {$e["DB_DATABASE"]}"); print_r($p->query("select id,name,role from admin_users")->fetchAll(PDO::FETCH_ASSOC));'
```

Do not run the full PHPUnit suite per page — CI owns that. The one exception is the single baseline run the owner asks for when a phase closes ("oke satu kali seluruhnya"): start it in the background (`php vendor/bin/phpunit > /tmp/suite.log 2>&1; echo DONE >> /tmp/suite.log`), poll for the `DONE` marker, and report the final line once — not the per-page gates again.

**Refresh the test DB immediately before a baseline run:** `php artisan migrate:fresh --force --env=testing`. Local test-database drift shows up as hundreds of `E` rows on one `Base table or view not found`, not as `F` failures, and reads like a broken branch. Errors ≫ failures on a suite that was green earlier means the database is stale, not the code: migrate, re-run, and only then read the number. The full suite here takes ~6–7 minutes; the push guard runs Pint over every changed PHP file (~3 minutes) — see the no-estimate rule under Reporting style.

**Point `phpunit.xml` at a local test database without `force`.** A suite that shares the dev database destroys it the first time a `RefreshDatabase` test runs, and the wreckage reads as 100+ unrelated errors (`Unknown column 'kind'`, `phone_normalized`) instead of a config mistake. Add `<env name="DB_DATABASE" value="<project>_test"/>` **with no `force="true"`**: without `force` an exported CI variable (`DB_DATABASE=lfamilia_ci`) still wins, which is what you want; with `force` the local name overrides CI and every CI run dies on a database that does not exist there. Mirror it in `.env.testing`, migrate the test DB once (`php artisan migrate --force` with `DB_DATABASE` exported), and confirm the name with `db:show` before trusting any baseline.

When the install has no `mysql` client and no `tinker` command, bootstrap the app in a one-liner instead of hand-parsing `.env`:

```bash
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach (DB::table("product_input_fields")->select("field_key")->groupBy("field_key")->get() as $r) echo $r->field_key, "\n";'
```

Read the actual rows before asserting what a form must contain — guessing the vocabulary (field keys, statuses, channels) from the Vue page produces a dropdown with options the database never emits.

**Visual check needs the browser fixture, not just a server.** The local DB routinely has zero products, so `/admin/catalog` renders an empty list that looks like a broken page. Seed first, then drive the page at 390px and 1440px:

```bash
php tests/Browser/fixture.php        # products, packages, mappings, SKUs, admin account (local/testing only)
php artisan serve --host=127.0.0.1 --port=8000
```

Credentials for that seeded admin live in `tests/Browser/fixture.php`; never paste them into chat. `tests/Browser/admin-ui.mjs` already encodes the real-click flows (editor sheet, import picker) if you need a reference for what to click.

**The admin session drops between browser calls — re-authenticate, do not retype the form.** Long screenshot/test loops land on `/admin/login` mid-run and every selector afterwards returns `undefined`. Start each browser batch with a silent POST to `/admin/login` using the CSRF meta tag and the fixture password read from `tests/Browser/fixture.php`, then navigate. Logging in through the visible form is slower and fails on the CSRF field being re-rendered mid-submit.

**Prove the page is the panel before you measure or screenshot it.** Mid-loop the app renders the login screen instead, and a screenshot of it is not obviously wrong to a vision pass — it will happily review the login form and report layout findings about a page that never rendered. Assert the panel's own marker (`!!document.querySelector('.adm')`) and retry the navigation until it is true; a capture that fails the assert is void, never described. The login inputs also mount a beat after the document loads, so `fill_input('input[name=email]')` raises `element not found` on a fresh load — wait for `document.querySelectorAll('input').length` before filling, then re-issue the same selectors rather than switching to another login path.

## Visual audit — measure, do not eyeball

"Warna gajelas / tumpang tindih / UI UX nya ancur" is never answered by reading CSS or by one screenshot. Loop **every** route under `/admin/*`, at 390px and 1440px, and report numbers per page. Inject `scripts/visual-audit.js` with `js(open(path).read())`; it returns real occlusion (confirmed with `elementFromPoint`), contrast against the actual backdrop, and small/unnamed controls.

Three rules that decide the outcome:

- **Bounding-box overlap is not overlap.** Parents contain children and transparent wrappers span everything; a naive scan reports dozens of false positives and the owner stops believing the report. Confirm each candidate at its intersection centre.
- **`admin.css` carries two theme systems and the legacy light block still wins** (`--muted-foreground:#718198` on `#09090b` = 4.0:1). Decide the winner with `getComputedStyle`, never source order, and prove a legacy rule is dead before deleting it.
- **Sheet, popover and mobile-nav surfaces render in a portal**, outside `.lf-admin-content`, so content-scoped rules never reach them. Use `var(--popover)` / `var(--background)` / `var(--card)` there, and verify by opening the panel.
- **A contrast finding must come from pixels, not from a description of the screenshot.** `getComputedStyle` in this app returns `oklch(...)`, so a regex over the digits invents ratios (everything "2.48:1"); convert through a 1×1 canvas first. The vision model also reports "near-black text on near-black" for dark JPEGs that actually measure 16.5:1. Convert, then report — recipe in `references/visual-audit.md`.

Small controls are first-class findings, not nitpicks: height < 32px, width < 32px, contrast < 4.5, or no accessible name ("tombol kecil-kecil juga lihat, lihat sedetail mungkin"). Generic dark-SaaS defaults read as slop however correct the markup — drive palette and density through the `ui-ux-pro-max` CLI. Recipes, calibration findings and the `admin.css` dead-class sweep: `references/visual-audit.md`.

## Commit policy for phased work

When the owner says the pages are being done in stages and audited together:

- **Commit locally after each page. Never push, never open a PR.** The whole batch becomes one PR at the end for review.
- Do not ask for commit permission on every page once the policy is stated; commit and report the SHA.
- Keep scratch audit notes **outside the repo** so they do not dirty the tree.
- Verify `git config user.name/user.email` before committing — the hook smoke test can leave a bogus identity behind.

## Review loop (Codex on the PR)

The owner's standing order: request the review the moment the PR — or a fix — is pushed. Never sit waiting for CI first; CI and review are parallel tracks, and the review finds defects CI structurally cannot.

- After `gh pr create` and after **every** push: `gh pr comment <n> --body "@codex review"`.
- Poll with `gh api repos/<owner>/<repo>/pulls/<n>/reviews --jq '.[-1] | "\(.submitted_at) \(.state)"'` and compare against the push time. A `COMMENTED` review whose body is only the "About Codex" boilerplate is a receipt, not a review.
- **Count findings from both endpoints.** Line findings live in `pulls/<n>/comments`; a P1 finding is often written into the **review body** itself (marker `<!-- codex-code-review-finding:v1 -->`) with no line comment at all. Listing only `/comments` undercounts, and the owner counts too.
- **Report the round before fixing it.** Enumerate every finding with its severity and the operator-visible consequence ("saldo tidak berubah walau tombol simpan ditekan"), separate real defects from owner-policy conflicts, propose an order, and ask. The owner answers per number; only then start the fixes. "Banyak CI merah + banyak review" is a briefing request — answer it with the list, not with edits.
- Once approved, fix each finding and **commit** immediately — do not batch findings until CI goes green, and do not wait for a CI run before starting the fix. **Commit is not push**: run `references/pre-push-self-review.md` over the new commit, report what it found, fix and commit again, and push only on a round that reports nothing new. The owner reads a push straight after a commit as skipping the review.
- **Fix by class, not by finding.** A finding names one line, but the pattern behind it normally sits in several files: a form submitting a value the server ignores, a dialog rewriting fields it never displayed, a client-only password guard, a route with no control. Grep the pattern across `resources/js/Pages/Admin/` and `app/Http/Controllers/Admin*` and fix every instance in the same commit. One finding fixed in isolation guarantees the same finding returns at another line next round, and every push makes the reviewer re-read whole files — so the loop never ends. Two or three rounds when fixing findings one at a time; one round when fixing the class.
- **After the agreed fix set is pushed, report further findings without acting.** The owner decides whether another review round is worth it; opening a new fix cycle on your own reads as never finishing. State the finding, the consequence, and that nothing was changed.
- **A review round is not the same as a test run.** Tests prove what already has a test; they cannot find an unhandled mode, a dropped field or a missing button. The owner asks for a self-review *before* every commit for exactly that reason — running the gates and pushing is the failure he names.
- "You have reached your Codex usage limits for code reviews" is temporary; ask again later, it clears on its own.
- Report each round as: the finding in one plain sentence, the commit that fixed it, and the test result. No replay of the whole branch.

## Pitfalls

**A shared CSS class has a blast radius.** Before changing a rule on a design-system class (`lf-admin-summary`, `lf-admin-record`, `lf-admin-tabs`), grep every consumer and count them. Changing `.lf-admin-summary` to a 4-column grid silently reflowed eleven pages. Scope page-specific overrides with an additive modifier (`.lf-admin-summary-4`) applied on the one page that needs it, and leave the base class alone.

**Raw codes leak into the UI and nobody notices until the owner does.** After finishing a page, sweep for values rendered straight from the database or PHP enums: `{{ n.severity }}` (`INFO`/`WARNING`), `{{ order.payment_status }}` (`PENDING`/`PARTIAL_REFUND_REVIEW`), `{{ order.payment_channel }}` (`qris`, `ewallet`, `virtual_account`), and audit strings like `product #231` / `catalog.product.updated`. Every one needs a human label.

Before claiming a leaked code, check the translation file: paginator labels come from `lang/id/pagination.php` and are already Indonesian, so `{{ link.label }}` is correct and reporting `&laquo; Previous` as a leak is a false finding. Confirm a label is raw by looking at the value the backend actually sends, not at the template alone.

**Do not write a second label dictionary.** Before adding a translation map, grep for one that already exists (`resources/js/lib/orderStatus.js` has `paymentStatusLabel`; the audit controller has module/operation/subject maps). Reuse it. If it is locked inside a controller, extract it to `app/Support/` and inject it — one source, both callers.

**A label map is only finished when every emitted code is in it.** A fallback that returns `'Aktivitas <modul>'` or `'Data sistem'` looks safe but makes distinct events indistinguishable in the UI and in the filter dropdowns. Enumerate the real codes from the source instead of eyeballing: scan `app/` for the audit calls, then diff against the map keys and the target-type map.

```python
import re, glob
s = open('app/Support/AuditLabels.php').read()
i = s.index('private const ACTION_LABELS'); labels = set(re.findall(r"'([a-z_]+\.[a-z_.]+)'\s*=>", s[i:s.index('];', i)]))
acts = set()
for p in glob.glob('app/**/*.php', recursive=True):
    t = open(p, errors='ignore').read()
    acts |= set(re.findall(r"record\(\s*\$request\s*,\s*'([^']+)'", t))
print(sorted(acts - labels))
```

**A create form must restore its own defaults, not inherit the last edit.** `openEdit()` calls `form.defaults(record)` + `form.reset()`; if `openCreate()` only re-sets a few fields afterwards, the previous record's name and slug stay in the sheet and the next submit either duplicates it or fails on a duplicate slug. Give `openCreate()` its own `createDefaults()` object and `form.defaults(...)` it before `reset()`.

**A sheet that stores the record object goes stale — store the id, derive the record.** `selected.value = item` keeps the copy captured when the row was clicked; an Inertia visit (media upload, delete, save) replaces the prop array but not that copy, so a freshly uploaded image appears to vanish, a removed one keeps showing, and deleting the open row leaves an empty sheet behind. Hold an id and resolve it against the live prop, then clear the id on success:

```js
const selectedId = ref(null);
const selected = computed(() => props.categories.find((item) => item.id === selectedId.value) || null);
// delete handler
router.delete(url, { preserveScroll: true, onSuccess: () => { selectedId.value = null; } });
```

Lists cloned into a local `ref` (`Content.vue`, `Catalog.vue`) are only safe when a `watch(() => props.list, …)` re-clones them; a sheet fed from a plain object copy is stale no matter what the watcher does. Audit every `Sheet` on the page this way before calling it done.

An editor that holds its own copy must also **close on success and re-clone the prop** — `onSuccess: () => { editorOpen = false }` plus `watch(() => props.rows, …)` copying the fresh row back. Without the close, a save reports success, the pop-up stays open over stale values, and the operator's next click resubmits them.

**A submitted toggle the controller ignores is a lie in the UI.** When the sheet posts `is_active` (or any switch) and the controller validates only some fields and then hardcodes the value, the save reports success and the record lands in the opposite state. Add the field to `$request->validate()` and persist the submitted value; keep the hardcoded default only as the fallback for clients that omit it.

**A field the pop-up shows but the payload omits is a silent no-op save.** The manual-nominal cost lives on the provider mapping, not on the package, so a `PUT` that carries only the package `payload` closes the dialog with the old cost still in place and margin/accounting quietly stale. Before trusting any save, trace where the value is *read* from and hit the endpoint that owns that table; when one dialog edits values from two tables, it needs two writes or a server action that does both atomically.

**A URL map keyed by a `type` discriminator must cover every type the page can send.** `URLS[type] + '/' + id` with a missing key becomes `undefined/<id>` — a 404 the operator reads as "delete is broken" while the backend route is fine. Enumerate the types from the page's own branches (edit *and* delete) and assert each has an entry; a two-line guard over the map is cheaper than a review finding.

**A route behind password confirmation needs the client helper, not a bare `router.post`.** The middleware redirects to the confirmation screen and deliberately does not replay the request, so the typed amount and reason are lost and nothing is saved. Any new form targeting a `admin.password.confirm` route goes through `withSensitive` (as the customer detail page does) — including forms added to a *list* page that already looks like the detail one.

That guard still leaks in two ways. A client condition written as `Boolean(target.id) && changed` skips the **create** path entirely, because a new record has no id — a new Super Admin gets created with no prompt at all. And a client prompt is not enforcement: the route group itself must carry `admin.password.confirm`, or a direct request changes role, permissions or status, or deletes an account, without one. Put the middleware on the routes and call `withSensitive` for create as well as edit.

**"Ini terlalu jelek, ulang dari nol" is a rewrite request with a spec attached.** When the owner says the panel should be rebuilt fresh ("buang semua yang ada disana berserta route-route nya"), split the ask into three layers before agreeing to anything: presentation (Vue pages, `admin.css`, layout — safe to replace), routing/controllers (addresses, validation, button actions), and business logic (payment, Digiflazz, fulfillment, no-refund rule — must be preserved). Say plainly that layers 1–2 can be rebuilt and layer 3 is called from the new code, never rewritten; a full rewrite of layer 3 is re-doing the riskiest work for a visual complaint. Then propose building **beside** the old panel rather than in place, so orders can still be handled while it is unfinished, and ask only for the decisions that change the shape (side-by-side vs in-place, keep `/admin/*` URLs, which pages are dead, whether login is rebuilt). The same answer applies to "fresh" requests for the product docs: the PRD is rewritten first, because a UI built against a stale spec re-litigates every screen. When the owner also rules the customer frontend out of scope ("frontend customer sih udah cukup"), the work is documents + admin pages + the controllers that feed them: every `/admin/*` address stays and only presentation changes. State that boundary back before starting — "bangun ulang" otherwise reads as permission to touch checkout.

**A prototype is a look, not an inventory — except in the current rebuild.** Historically the page list came from routes/controllers and copying the prototype's sections got "JANGAN JADIKAN PATOKAN". For the rebuild now in progress the owner explicitly reversed this: the template's 19 sidebar menus *are* the panel's menu list. Palette, density, radius, table/sheet shapes, page inventory **and the fields of every pop-up** all come from the template: read them out of the `data-sheet-open="sheet-…"` block that the button opens, not out of a controller. Controllers are opened only after the menu is locked, to reconcile — a template block with no backing table is a backend task to report, never a reason to cut the item from the list. Outside that rebuild, keep using `references/panel-spec-from-code.md`.

**"Rombak" and "buat baru" are different orders — do not offer them as a menu.** Rombak means the old panel stays and gets patched. Baru means the presentation layer is discarded (pages, layout, `admin.css`) and only the engine (routes, controllers, services, schema) is reused. When the owner says *buat panel baru*, treat it as the second and say so in one line; asking him to choose between the two reads as not understanding the request and escalates the tone. The same applies to "where does it live": propose side-by-side once, in one sentence, and move on — do not re-ask it in a later turn. Note the reversal recorded above: in the current rebuild he rejected side-by-side and ordered the replacement in place at `/admin`, frontend first.

**Never propose a menu structure of your own.** Not seven screens, not ten, not "kerangka kerja bukan halaman". Every regrouping is read as deciding the panel for him ("KOK LU KERJAIN LANGSUNG SIH? EMANG LU TAU GUA MAU PANEL NYA APA AJA?"). The menu list is the template's sidebar (19) and the items are locked one by one by him. Same for discovery interviews — "hal pertama yang mau Anda lihat?" gets "APASIH"; the template already answers what the panel contains.

**The template's asset and page lists outrank the controller's — even when the controller is stricter.** Menu 7 lists 8 store assets (including `manual_qris` and `payment_header`, both live in `AdminPaymentController`) and 6 policy pages (about, terms, privacy, refund, contact, faq), while `AdminContentController@index` sends only 6 assets and `updatePage` whitelists only `terms|refund|privacy`. That gap is a backend task to report, never a reason to trim the template's list. The owner caught this exactly: *"Ngapain cek langsung dari Controller sih? Kan gua bilang template jadiin contoh dulu, Controller itu malah nanti bisa aja ada yang dihapus."* When he then asks "ngapain QRIS disini?", the answer is where it is used (checkout manual payment screen), not a defence of the list.

**Absence in the controller you happened to open is not absence in the system.** Do not tell the owner a template feature is fake because the page controller you read does not implement it. Scheduled sync, background jobs and settings live outside the controller: grep `routes/console.php`, `app/Jobs`, `app/Services`, and `system_settings` keys before calling anything unimplemented. Digiflazz auto-sync looks missing from `AdminDigiflazzController` but is real — a `lfamilia:sync-digiflazz-catalog` command on the scheduler reads `digiflazz.auto_sync` and `digiflazz.auto_sync_interval_minutes`, and `AdminDigiflazzMonitorService` already persists interval, low-stock and price-warning thresholds. Recommending a switch be disabled as "belum aktif" when it is wired is the same class of error as trimming the template, and it costs the owner's trust in every other recommendation in the same message.

**A UI audit of the old panel is not a prerequisite for the rebuild — and offering one gets you yelled at.** When the order is "buat fresh / rombak total", do not run route-vs-page crosschecks, do not build an inventory of the old screens, and do not hand him a "daftar periksa" of what to keep. He reads that as deciding for him and as preserving the panel he just banned ("semua yang lu mau gua mau" followed by "cuma nambal-nambal"). The audit that matters is the template's sections plus what the controllers can send.

**Every image field carries its recommended size and ratio — no exceptions.** The owner asked for this explicitly ("semua gambar gambar ini jangan lupa ya rekomendasi ukuran/ratio gambarnya"). Print it next to the upload control, and only when an upload input is actually wired. **The numbers are not invented — copy them from `resources/js/Composables/mediaRecommendations.js`**, which already holds the approved copy per slot (it is what the old pages used, so the strings are consistent across the panel):

| slot | recommendation (from `mediaRecommendations.js`) |
|---|---|
| Banner desktop | 2560×800 (16:5), for areas up to 1280 px |
| Banner mobile | 1320×600 (11:5), keep important text centred |
| Banner produk | 2560×640 desktop; mobile shows 2:1 (390×195) |
| Gambar berita | 1600×900 (16:9) |
| Pop-up pengumuman | 1200×800 (3:2), avoid text near edges |
| Logo toko | 256×256 (1:1) PNG/WebP transparan |
| Favicon | 512×512 (1:1), readable at 16–32 px |
| Footer desktop | 3840×232, safe area 1920×116 |
| Footer mobile | 780×140, safe area 390×70 |
| Gambar kategori | 128×128 (1:1) transparan |
| Ikon nominal | 256×256 (1:1) transparan |
| Card produk | 600×900 (2:3) |
| QRIS manual | 1:1, minimum 600×600 |

When a slot has no entry in that file, extend the file rather than typing a number into the Vue page — one source, both callers.

**A long list without a filter is a defect.** Reviews (up to 200 rows) shipped as a plain scroll list and got "masa harus scroll 1 1 kan ga lucu". Any list that can exceed ~20 rows gets a search box plus the status filter that matters (for ulasan: Tampil / Disembunyikan), and the filters run server-side once the list is paginated.

**`defineModel` typed `Boolean` turns a non-empty string into a permanently open dialog.** Antrean and Detail Pesanan drive `v-model:open` with `ref('')` + `ref('complete'|'fail')`, so Vue's Boolean cast made `''` falsy but the reka-ui `DialogRoot` still mounted on the first render — the pop-up sat open over every visit, locked the page with `overflow:hidden` and shrank the layout to a 14px strip on phones. Keep the model untyped and coerce explicitly:

```js
const open = defineModel('open', { default: false });
const isOpen = computed({ get: () => !!open.value, set: (v) => { if (!v) open.value = false; } });
```

Verify by loading the page with nothing clicked: `document.querySelectorAll('.adm-dialog').length === 0` and `document.body.getAttribute('style') === null`.

**Measure layout only on a page with no dialog mounted.** While a reka-ui dialog is open, `document.body` carries `padding-right: 15px; overflow: hidden; pointer-events: none` and `.adm` collapses to a narrow strip, so every width and offset read in that state is garbage and reads like a catastrophic CSS regression. Before any measurement loop, assert `document.querySelectorAll('.adm-dialog').length === 0`; if it is not, close the dialog (or re-navigate) and measure again before touching CSS. Navigating with `goto_url` re-renders the page but does not clear a dialog the previous route left open — check, do not assume.

**A filter row aligns by sharing one flex basis, not by wrapping free widths.** `flex-wrap` with per-field `style="flex:2 1 220px"` produces a ragged row whose buttons detach from the last select. Give the search field a `search` class, one basis for every field, and wrap the buttons in their own group:

```css
.adm-filters .adm-field, .adm-filters .adm-field.narrow { flex: 1 1 80px; min-width: 0; }
.adm-filters .adm-field.search { flex: 1.6 1 120px; }
.adm-filter-actions { display: flex; gap: 8px; margin-left: auto; flex: 0 0 auto; }
@media (max-width: 620px) {
    .adm-filters .adm-field, .adm-filters .adm-field.narrow { flex: 1 1 calc(50% - 4px); }
    .adm-filters .adm-field.search, .adm-filter-actions { flex: 1 0 100%; }
    .adm-filter-actions > button { flex: 1 1 0; }
}
```

Do not put the buttons inside the flex row as bare `<button>` siblings with `margin-left:auto` — once they wrap they land on their own line anyway and lose the grouping. Also keep `align-items:flex-end` and give labels `min-height: 2.9em` so a two-line label does not push its input 9px below its neighbour.

**"Masih banyak kolom yang ga sejajar" is answered with a number per row, not a CSS guess.** When the complaint repeats, stop tweaking one form and measure every admin page: bucket each visible `input/select/textarea` by `Math.round(rect.top / 10)`, and flag a bucket when `max(top)-min(top) > 2` or `max(height)-min(height) > 2`. Pages still on `.lf-admin-*` measure the same way and usually come back clean — the culprit is the new `adm-*` row. Fix only the rows the numbers name, then re-measure the same pages; a fix that scores better but looks worse in the screenshot gets reverted (`kalau jadi lebih jelek ya jangan deh`).

**A pager that wraps puts `Sebelumnya` and `Berikutnya` on different lines.** `.adm-pager` is `flex-wrap` with the count and a `.sp` spacer; under 620px the spacer still claims space and the two buttons split. Hide the spacer and give the count `flex: 1 0 100%`, then let the buttons share the next line (`flex: 1 1 0`).

**Stat boxes are a grid, not a flex-wrap row.** `display:flex; flex-wrap:wrap` with `flex:1 1 150px` leaves the last row's cards stretched to full width, and `minmax(210px,1fr)` splits an 8-card strip into 6+2 — an orphan pair that reads as more important than the rest (measured, not estimated). Use:

```css
.adm-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; }
@media (min-width: 1100px) {
    .adm-stats:has(> :nth-child(8):last-child) { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (max-width: 640px) { .adm-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
```

6 boxes land on one row, 8 land 4+4, phones 2 per row. Verify by bucketing `getBoundingClientRect().top` per card and printing `[cards per row]` for every page — do not eyeball a screenshot for this.

**Bar height must be the metric the card names.** A card titled *Pesanan dan omzet* whose bars are scaled by revenue draws one visible bar on a day with 2 orders and Rp 100 jt, and reads as a broken chart. Scale by the count, put rupiah in the tooltip, and print the peak in one line above the plot so the height has a unit.

**`form.transform()` applies to the submission that follows it.** Calling it after `form.post()` / `form.put()` ships the untransformed payload — the voucher form saved with every field empty and reported no error. Set the transform, then submit.

**The pre-commit guard's whitespace message names no file.** `whitespace error or unresolved conflict marker` fires for any staged hunk `git diff --check` rejects, including one trailing blank line at EOF in an archived doc. Run `git diff --cached --check` first; it prints `file:line: new blank line at EOF`. In a hand-edited `.vue` the culprits are blank lines that kept their indentation — strip whitespace-only lines (`[l.rstrip() if not l.strip() else l for l in open(p).read().split('\n')]`), re-stage, commit again. The "conflict marker" half of that message is not a marker detector: grepping the staged diff for `^+.*====` matches comment banners like `<!-- ===== TAB 1 ===== -->`, so confirm a real conflict with `git status` (`UU`/`AA`) instead of the grep.

**Prove a save in the browser, then delete the row you created.** Set values through `input` events and submit the real form by id (`document.getElementById('voucher-form').requestSubmit()`), assert the pop-up closed and the row appeared, then remove the test record. Leftover `UJI123` vouchers and `Uji Kategori` cards pollute every later screenshot and the local DB the owner actually looks at.

**A checkbox column with no bulk action is slop, and the backend usually already supports one.** A `Pilih` column plus select-all that nothing consumes tells the operator a batch action exists. Check the export/destroy signature first (`'selected' => ['nullable','array','max:100']` means the endpoint takes ids) and wire the toolbar button; only drop the column when no endpoint accepts a list.

**A copy button that gives no feedback reads as broken.** `navigator.clipboard.writeText` inside `try/catch {}` swallows both the success and the denial, so the operator clicks three more times. Set a short-lived `copied` state on the row/button (`'Tersalin'` for ~1.5 s, revert in a timer cleared on unmount) and say `Gagal menyalin` when the promise rejects.

**A size recommendation without an upload input is a promise the page cannot keep.** Printing "Rekomendasi 512×512, PNG transparan" next to `Belum ada gambar` and no file input leaves the only stated capability unimplemented while the endpoint (`/admin/catalog/media/category/{id}`) exists. Either wire the input in the same pass or cut the recommendation block.

**A preview must fall back to sample data, not to the field's placeholder.** Placeholders are instructions (`Masukkan User ID`), so `row.placeholder || SAMPLE[key]` composes `Masukkan User ID(Masukkan Zone ID)` — the one line meant to prove the wire format shows nothing usable. Invert the precedence (`SAMPLE[key] || row.placeholder`), or keep placeholder as an input `placeholder=` attribute only.

**A filter over the loaded page lies once the list is paginated.** `props.products.filter(...)` in a `computed()` searches only the rows the server sent, so a product on page 3 reports "tidak ada" while it exists. Either filter in the controller with `withQueryString()` pagination, or drop the search box until the list is server-driven — never leave a client-side filter above a server-side pager.

**A chart with a fixed default metric renders zero-height bars for a restricted role.** When the controller zeroes a series for users without finance access, the page must pick the visible series by permission (`ref(props.canViewFinance ? 'revenue' : 'orders')`), not hardcode the hidden one.

**Header action rows overflow at 1024 px, not at 390.** A `flex flex-col gap-2 sm:flex-row` button row goes single-column on phones but still lays every button on one line from `sm` up, pushing the last one past the right edge and making the whole page scroll sideways. Add `sm:flex-wrap` (plus `sm:justify-end`) to that row across the admin pages at once rather than fixing pages one at a time.

**A hint that promises "the section you have open" must print the section.** Wiring a period label (`rangeLabel`) into a sentence about the active report tab produces a sentence that never changes when the tab changes; bind `sections.find((item) => item.id === section)?.label` instead.

**Deleting a dead prop usually breaks a static guard test.** `tests/Browser/admin-ui-guards.test.mjs` asserts `Array.isArray(props.<key>)` for a fixed list of Dashboard props, and feature tests assert the prop is present. Remove the prop from the controller, the Vue page, the guard list, and the Inertia assertion in the same commit, or the change lands red.

**A guard test that pins markup dies with the markup — rewrite it, do not delete it.** After a rebuild the guard file fails wholesale (20 of 30, in this repo) and the temptation is to remove it. Split the failures instead: assertions on the old surface (`<Sheet>`, `lf-admin-tabs`, `lf-admin-summary`, dark-theme tokens, `AdminResponsiveTable`) get replaced with the new one (`AdminModal`, `adm-*`); assertions on behaviour — client+server validation messages, WIB date formatting, null-safe props, meaningful empty states, "the export records its own audit trail" — keep their intent and only change their selectors. Then add one guard that every menu entry the backend sends also appears in the sidebar, so a menu cannot vanish silently.

**The runtime browser smoke is a second, separate harness — `tests/Browser/admin-ui.mjs`, launched by `tests/Browser/smoke.sh` inside the `foundation` CI job.** The static guards above run under `node --test`; this one drives real Chrome over CDP, walks every admin page at 8 widths (360→1920), and clicks the editor, import picker, manual-order and add-admin dialogs. After a redesign it fails on the removed selectors (`.lf-admin`, `[data-slot="table-container"]`, old labels) and turns CI red at the smoke step while the static guards are already green. Rewrite it in the same branch: page list = the 21 sidebar menus plus the 3 detail routes, assert the shell marker (`.adm-side` + `.adm-body`), table containment in `.adm-scroll`, no horizontal overflow, dialog centred/body-locked/focused, and one real Digiflazz import whose price then appears on the storefront. Run it locally before pushing — fix **every** harness in the tree in one pass, not just the one CI named: CI aborts at the first failing step, so a redesign that breaks both `admin-ui.mjs` and `checkout-visual.mjs` reveals them one CI run at a time. **But run one `group` at two widths, never the full matrix, per commit.** The 21-page × 8-width sweep is a CI job: it costs ~5 minutes and has never been the thing that caught a review finding — those come from reading the code and from the one targeted PHPUnit file. Use the full run only when the shell itself (sidebar, layout, dialog component) changed, not when one page's fields did. After any shell or selector rename, `grep -ln 'lf-admin\|data-slot=' tests/Browser/*.mjs` and re-run each hit. `php tests/Browser/fixture.php`, serve on a **free** port (never stop an existing listener), then `ADMIN_UI_BASE_URL=http://127.0.0.1:<port> node tests/Browser/admin-ui.mjs <chrome-binary> <group>`; the `group` argument (`shell|catalog|orders|content|operations|customers|payments|settings`) keeps a failing run to one screen instead of 8 widths of everything. Kill the spawned Chrome before deleting its temp profile — `rm(profile)` on a live profile throws `ENOTEMPTY`; pass `maxRetries` and swallow it, a leftover profile directory is not a test failure.

**A test file named in the CI workflow cannot be deleted alone.** `.github/workflows/validate-rebuild.yml` invokes `node --test tests/Browser/<a>.test.mjs tests/Browser/<b>.test.mjs …` by path: remove or rename a file without editing that line and CI fails on a missing file; add one without editing it and the new guard never runs. Grep the workflow for the filename before and after any test-file change. A test file the workflow does not name (an old `admin-relocation.test.mjs`, an `ordering.test.mjs` pinning pre-redesign markup) can be deleted outright — check first, then say in the report that CI never ran it.

**`window.confirm` is not a themed dialog.** Native confirms appear grey and unbranded on an otherwise dark panel. Use `resources/js/Components/AdminConfirmDialog.vue` (`:open`, `title`, `message`, `confirm-label`, `destructive`, `@confirm`); it wraps reka-ui `DialogRoot`. Sweep **`resources/js/Components/` as well as `Pages/Admin/`** — a shared component such as `AdminMediaControl` (used by product, package, banner, pop-up, news, asset and category editors) keeps the native box alive on seven pages while every page-level grep reports clean, and a reviewer that only reads the diff will never flag it.

**Money math in a Vue preview is a second implementation of the pricing rule.** `CatalogPricingSettings` / `DigiflazzFixedPricePolicy` / `CheckoutPricing` all round with `intdiv(cost * (1000000 + percent*10000) + 999999, 1000000)` (integer ceil, 4-decimal percent). A `Math.ceil(cost * (1 + p/100))` copy in the page drifts on the edge cases and the admin sees a preview that is not the price charged. Mirror the exact integer formula (`Math.ceil(cost * (1000000 + Math.round(p * 10000)) / 1000000)`), keep one helper for every card that previews, and change the PHP and the JS in the same commit.

**A feature the plan relocates is only done when it lands at the destination.** Removing "produk terlaris" from Dashboard satisfies half of "move it to Produk"; the page is still wrong until the destination renders it (or a note says why it cannot). Check every "dipindahkan ke …" sentence in the plan against the destination page, not just the source.

**A section that exists both inside the editor and as a page-level tab is a duplicate, not a feature.** When the plan puts a capability into the per-record workspace (customer input fields, notices, images), delete the outer tab and its form — keeping both means two write paths with different validation and a guard test that can only cover one. Move the last unique action into the editor before removing the tab.

**Gate a conditional feature on the data that enables it, not on a coarse mode.** Voucher stock (`stock_key`, `stock_counts`, code import) only makes sense for packages that already carry a `VOUCHER_STOCK` mapping. Rendering the block for every `AUTO_PROVIDER` product invites admins to type a stock key into a Digiflazz package and silently rewrites its mapping. Render it when `pack.mappings.some(m => m.provider_code === 'VOUCHER_STOCK')`, and show the import affordance only from that state.

**Advanced settings parked above the list are the page's real problem.** Global markup, fixed-price policy, and two-stage uplift cards at the top of `Catalog.vue` push the actual product list below the fold and read as required steps. The plan wants them reachable but collapsed — put them behind a `<details>`/secondary section and leave the list first.

**Counting UI elements by grepping the whole file produces false findings.** A `grep -c '<Card'` over a page counts every card on the page, not the ones in the block being discussed, and the resulting "8 cards for 6 numbers" claim is wrong and erodes trust. Count inside the exact line range (`sed -n '163,170p' file | grep -c`), or read the block.

**A test asserting an absolute count fails on the local DB, not in CI.** Feature tests that assert `summary.total`, `has('x', N)`, or any table-wide count assume an empty table. The local database keeps QA accounts and fixtures, so the count is N+1 locally while CI is green. Do not "fix" the controller: clear the table at the start of the test inside its `DatabaseTransactions` scope (`Model::query()->delete();`), and verify afterwards that the pre-existing rows survived the rollback.

**Never `git stash` a dirty tree to prove a failure is pre-existing.** This worktree carries unrelated uncommitted work and leftover conflict residue; stash push/pop replayed a `UU` conflict into a file that was never touched. Decide from the assertion plus a direct look at the data (read `.env` / query the table) instead, or copy the repo to `/tmp`. If a stash pop does report a conflict, grep the whole `resources/js` and `app` trees for `<<<<<<<` before doing anything else.

**Dates and "today" boundaries are WIB, never the app clock.** `.env` sets `APP_TIMEZONE=Asia/Jakarta` but `config('app.timezone')` resolves to `UTC`, so `now()->startOfDay()` is 00:00 UTC = 07:00 WIB. Two rules: every rendered timestamp pins `timeZone: 'Asia/Jakarta'` in `Intl.DateTimeFormat` and prints `WIB`; every "today" / date-range boundary is built in WIB and then expressed in the storage zone — `Carbon::parse($date, 'Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone', 'UTC'))`, not a hardcoded `->utc()`. Hardcoding `utc()` is right only while the app is configured UTC; converting to `config('app.timezone')` keeps the boundary correct if `APP_TIMEZONE` is ever honoured. Rolling cutoffs take the same treatment: a `last_7_days` total written as `Carbon::now('Asia/Jakarta')->subDays(7)->utc()` drifts by the offset and counts older rows, so route it through the same storage-zone helper as the day filters. A bare `toLocaleString('id-ID')` and a raw `now()->startOfDay()` still miscount "Hari ini" by seven hours. Prove a suspected defect with data before reporting it: read one stored row, convert it to WIB, and print both counts.

```bash
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); use Illuminate\Support\Facades\DB; use Illuminate\Support\Carbon; $row=DB::table("audit_logs")->orderByDesc("id")->first(); echo $row->created_at, " UTC = ", Carbon::parse($row->created_at)->timezone("Asia/Jakarta"), " WIB\n"; echo "page count: ", DB::table("audit_logs")->where("created_at",">=",now()->startOfDay())->count(), " | correct: ", DB::table("audit_logs")->where("created_at",">=",Carbon::now("Asia/Jakarta")->startOfDay()->utc())->count(), "\n";'
```

**Feature parity gaps are not UI gaps.** Some missing capabilities (adding payment channels, wallet top-up proof review, admin ledger views) are backend holes that no design plan closes. Report them as separate work; do not fabricate a UI for them.

**The prototype's refund block must never be ported.** The owner's HTML prototype carries "Refund Top Up Perlu Rekonsiliasi" with a "Selesaikan refund" button that debits the customer's wallet — that is an automatic refund path and is forbidden for this store. Refunds are real but manual: governed by the public Kebijakan Pengembalian Dana (5 eligible conditions, request within 7 calendar days via Hubungi Kami, CS review 1–7 business days, funds up to 14 business days). The panel may list refund *requests* and record a manual settlement (Super Admin wallet adjustment with a mandatory reason plus an audit entry); it may not decide or execute one. Never write "this store has no refund" — it has one, and it is manual. Details in `lfamilia-payment-guardian`.

## Reporting style

- Plain Indonesian, short sentences, no jargon.
- **Never quote a duration.** "Dua menit lagi" is a promise; the push guard, the browser matrix and the suite each take minutes and the estimate is wrong often enough that it costs more trust than silence. Say what is running and what is left ("suite penuh jalan, sudah 59%, semua hijau"), never how long it will take.
- Lead with the answer to the question asked, then evidence.
- **"Lu ngerjain apa?" / "belom selesai kah?" is a stop-and-answer, not a nudge to keep going.** During a long CSS/browser loop he cannot see the tool calls, so silence reads as ignoring him. Reply in the very next message — what you were doing, what is fixed and committed, what is left — before any further tool call; then continue if he says so. Answering with another measurement run instead of a sentence is what turns one question into three. **Repeated or escalating wording ("BELOM SELESAI KAH?", "JAWAB CHAT DULU") means the answer must be the first thing in the message and no tool call may precede it** — finish the pending commit first if it is one command, otherwise report state as it is and let him decide.
- When the owner asks "sudah sesuai rancangan?", answer with a match/mismatch list, not a narrative of what you did.
- Never claim visual QA you did not perform in a real browser.
- **He cannot open files — the answer goes in the chat message.** `.md` and `.pdf` both fail: "gua ga bisa liat filenya, ga tau buat apa apa nya, gua kan bukan programmer". Do not answer with a document, a path, or an attachment as the deliverable. Read the content out in the message itself (sections, numbers, the literal strings), and keep any file as an optional extra, never as the reply.
- **Kirim PDF hanya kalau dia minta PDF, dan tetap sertakan isinya di chat.** Resep yang jalan: tulis HTML bergaya (`@page { size: A4; margin: 18mm }`, chip warna per tingkat P1/P2/P3, satu `<h2>` per bagian), lalu cetak dengan Chrome headless — `$(ls -d ~/.agent-browser/browsers/chrome-*/chrome | head -1) --headless --no-sandbox --no-pdf-header-footer --print-to-pdf=<out>.pdf file://<file>.html` — dan cek `pdfinfo <out>.pdf | grep Pages`. Pesan chat tetap memuat temuan dan angka utamanya; PDF adalah artefak untuk dia teruskan, bukan jawabannya.
- **A question is a question, not a work order.** When the owner asks "ada skill yang cocok?", "gimana caranya biar kayak Codex?", or "bahas dulu", answer it and stop — do not dispatch agents, edit files, or start a sweep while they are still deciding. Acting during the discussion gets "dengerin dulu jangan langsung kerjain".
- **"Diskusi dulu" also bans reconnaissance — every tool, not just repo files.** No `ls`, no `grep`, no `execute_code` read of a `.md`/`.php`/`.vue` file, no `read_file`, no opening the prototype in the browser, no screenshot "to prepare" an answer. Reading is work in the owner's eyes and gets "siapa yang nyuruh lu kerja". The only file you may open is the one named in that same message ("baca file itu" authorises that file, nothing else). Answer from the conversation and the documents already in hand, and say plainly when a claim needs a file you have not opened.

  Treat these as discussion-mode triggers for the **whole** message, even when the message also names files or asks for a plan: `diskusi dulu`, `bahas dulu`, `menurutmu`, `gimana`, `yakin bakal sama?`, `apa lagi?`, `stop`. In that mode the reply is prose only. A later message that opens a new topic ("ulang yuk buat X") re-enters discussion mode — it does not inherit permission from an earlier "gas". The test before any tool call: did the owner ask me to *do* something, or to *say* something? If say — no tools.

  **Naming a menu ("Menu 9", "lanjut menu 10") is a request to READ that section, not to build it.** The planning ritual runs for all 19 before one line of code; reading the `<section>` and listing it is the expected tool use, and nothing else is. Opening controllers, models or configs during the list is the failure that gets "jangan langsung kerja dulu belom disuruh". Reading the template is always allowed in this mode; reading the backend is not.

  When the order changes mid-run ("eh stop dulu", "siapa yang nyuruh lu kerja"), the correct reply is one short paragraph: what was already done, whether anything is committed/pushed, and nothing else. Do not restart the analysis to show progress.
- **Ask only what the code cannot answer.** A question whose answer is already fixed by the controller — one pop-up row exists, these six asset keys are the ones sent, delete means soft-hide — is a statement, not a question. Put the decision in one line inside the item and keep the single closing question.
- **At most two questions per message.** Send only the ones whose answer changes what you do next; decide the rest yourself and state the decision in one line. A numbered list of eight questions gets "apa lagi yang mau lu tanyain" and burns the turn. During the menu-by-menu planning ritual the rule is different and stricter: the element list *is* the questionnaire, so close each menu with a single "ada yang dibuang?" — not a per-item ya/buang checklist.
- **A quality complaint is a work order, not a survey.** Once the owner says the result looks broken ("error jelek banget", "UI UX nya ancur"), stop offering options, stop comparing templates, and fix the cause. Each extra menu of choices after that reads as stalling and escalates the tone; one plain sentence of what you are fixing, then the fix, then the evidence.
- **Do not answer "which one do you want" twice.** If the owner deflects a choice ("shadcn itu kebanyakan kotak kotak doang kan"), that is a verdict on the option, not an invitation to relist the shortlist. Take the decision yourself, say which way you are going in one line, and proceed — the owner corrects a decision faster than he answers a menu.
- **"Yakin bakal sama?" is a request for a bounded proof, not for a promise.** Answer honestly: fidelity is limited by the component base (shadcn primitives vs hand-written CSS), by mock data vs empty real data, and by pages that have no prototype at all. Name those limits, then build **one** page and send it side by side with the prototype before touching the rest — that is what turns the doubt into "gas". "Pasti sama" is the answer he is testing for and will reject.
- **"Stop" ends the run, not the current command.** Halt immediately, then report exactly what is dirty: which files changed, whether anything is committed/pushed/deployed, and where the backup is. Do not run a build, test, commit, or cleanup to "leave it tidy". "Diem" is stronger than stop: zero tool calls of any kind, including builds, checks and reads. If something was already in flight before the order, say so in one line and let it finish — never launch anything new to tidy up after it.
- **An in-flight build is not permission to keep going.** Announcing "build is still running, want me to kill it?" after a stop order is itself a new action the owner did not ask for. State it as a fact, offer nothing, and wait.
- **"Buat dulu biar saya lihat" means build, not compare.** Once the owner asks to see a result, the deliverable is a running page plus real screenshots at 1440px and 390px — never another table of candidates. Build it outside the repo, keep the repo untouched, and say where it lives and how to open it.
- **A prototype review is read + open + screenshot, then one decision.** When the owner sends an HTML mockup and says "diskusi dulu", touch no repo file: hash it against earlier copies, open it over `file://` in the real browser, screenshot 1440px and 390px, state how its visual direction differs from the shipped `admin.css` (a light prototype against a dark panel is a direction change, not a polish item), add your own measured observations, and close with the single question that unblocks the build. Its buttons are all `data-dummy` toasts — never present them as planned features. Recipe: `references/template-sourcing.md` ("Reviewing the owner's own prototype").
- **Name the visual failure in the owner's terms first.** "Popup keluar dari tema, jadi teksnya tidak kelihatan" lands; "the sheet portal is outside the `.lf-admin-content` scope so tokens do not cascade" is the explanation, never the headline. Cause second, evidence third.
