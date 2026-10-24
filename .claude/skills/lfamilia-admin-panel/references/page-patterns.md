# Reusable page patterns (LFAMILIA admin)

Two systems live in `resources/css/admin.css`. **New pages use the `adm-*` block and the `Components/Admin/*` set** — dark grouped sidebar (`AdminLayout.vue`), centred pop-up (`AdminModal.vue`), Combobox multi-pick (`AdminMultiSelect.vue`), formatters in `lib/adminFormat.js`. The `.lf-admin-*` recipes below are the legacy layer, kept only so pages not yet migrated still render; never start a new page on them.

## Metric summary strip (legacy `.lf-admin-summary`)

Markup: a `<section class="lf-admin-summary">` containing `<Card class="p-4">` children with `<p class="text-xs text-muted-foreground">Label</p>` + `<p class="mt-2 text-2xl font-semibold">Value</p>`.

CSS behaviour:

- base: 2 columns on mobile, `repeat(auto-fit,minmax(140px,1fr))` from 768px up;
- cards lose their own border inside the strip and share hairline dividers;
- page-specific column counts go through an additive modifier, never by editing the base rule: `.lf-admin-summary.lf-admin-summary-4{grid-template-columns:repeat(4,minmax(0,1fr))}` applied as `class="lf-admin-summary lf-admin-summary-4"`.

The base class is used by Dashboard, Digiflazz, Health, Integrations, Orders, Reports (×3), Access, CustomerDetail, Customers, Payments, Promotions. Any change to the base rule reflows all of them.

A page that hand-rolls its own row of big numbers (`flex` row of `<p class="mt-2 text-2xl font-semibold">`) is a gap, not a variant — it loses the dividers and the 2-column mobile stack. `Audit.vue` is the one remaining page doing this with its four counts.

## WIB timestamps and date filters

Every admin timestamp is printed in WIB and every date boundary is built in WIB, because `config('app.timezone')` is `UTC` even though `.env` says `APP_TIMEZONE=Asia/Jakarta`.

```js
const date = value => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : 'Belum tersedia';
```

```php
// "hari ini" dan batas filter: bangun di WIB, simpan/bandingkan dalam UTC
$start = Carbon::now('Asia/Jakarta')->startOfDay()->utc();
$from = Carbon::parse($request->query('from'), 'Asia/Jakarta')->startOfDay()->utc();
$to   = Carbon::parse($request->query('to'), 'Asia/Jakarta')->endOfDay()->utc();
```

Bare `now()->startOfDay()` and bare `toLocaleString('id-ID')` are the two tells of a page that skipped this; both appear in `Audit.vue` / `AdminAuditController`.

## Three-column "activity / integration / notification" strip

The design plans forbid turning these into a stack of cards. Use `.lf-admin-strip`:

```html
<section class="lf-admin-strip" aria-label="Aktivitas, integrasi, dan notifikasi">
  <div class="lf-admin-strip-col">
    <div class="lf-admin-strip-head"><strong>Judul</strong><Link class="text-primary hover:underline">Semua</Link></div>
    <article class="lf-admin-strip-row">
      <strong class="lf-admin-strip-title">…</strong>
      <p class="lf-admin-strip-note">…</p>
      <time class="lf-admin-strip-time">…</time>
    </article>
  </div>
</section>
```

One bordered box; columns stack on mobile and become 3 columns with vertical hairlines from 1024px. Variant `.lf-admin-strip-inline` puts a label and a badge on one row; `.lf-admin-strip-empty` is the empty state; `.lf-admin-strip-foot` is a bottom summary line.

## Edit surface — centred pop-up, full-screen on phones

`Components/Admin/AdminModal.vue` is the only edit surface. reka-ui `DialogRoot` + `DialogPortal`, `v-model:open`, `title`, optional `description`, `wide` for editor workspaces, `#footer` slot for `Batal` / the submit button. On phones it becomes `100vw × 100svh` with no radius.

```html
<AdminModal v-model:open="modal" :title="editing ? 'Ubah kategori' : 'Tambah kategori'" :description="editing?.slug || ''">
    <form id="category-form" class="adm-grid" @submit.prevent="save">…</form>
    <template #footer>
        <button type="button" class="adm-btn" @click="modal = false">Batal</button>
        <button type="submit" form="category-form" class="adm-btn primary" :disabled="busy">Simpan</button>
    </template>
</AdminModal>
```

Rules that came out of the build:

- **Form inside the dialog, buttons in the footer via `form="<id>"`.** Keeps the submit reachable when the body scrolls; no button buried under the fields.
- **Close on success.** `onSuccess: () => { modal = false }` on the Inertia options. A pop-up that stays open after a successful save reads as a failed save.
- **Nested pop-ups are fine and expected** — nominal editor over the product editor, confirmation over the record pop-up. Use the last `.adm-dialog` in the DOM when scripting, and the backdrop dims one more level automatically.
- **Never `window.confirm`** — a `removeTarget` ref plus a second `AdminModal` titled `Hapus …`, body explaining the consequence, footer `Batal` / `Hapus`.
- **Bottom padding for phones:** `.adm-dialog-body` needs `calc(24px + env(safe-area-inset-bottom))` or the save button sits under the gesture bar.
- **Do not type the `open` model as `Boolean` when a page passes a string marker.** Pages that use one ref as both flag and kind (`const modal = ref('')`, then `modal.value = 'complete'`) hand an empty string to the dialog. Vue's boolean casting turns `''` into `true`, so the pop-up renders on mount, locks the page (`body{overflow:hidden;pointer-events:none}`) and the operator sees a dialog nobody opened. Declare `const open = defineModel('open', { default: false })` and drive the dialog from a computed that only closes on falsy (`get: () => !!open.value`, `set: v => { if (!v) open.value = false }`), so a string kind still closes to `false`.

## Filter rows and form grids — alignment

Every field is `label.adm-field > span + input/select`. Alignment is a CSS problem, not a markup one:

```css
.adm-grid .adm-field > span:first-child { display: block; min-height: 2.9em; }  /* two label lines */
.adm-grid .adm-field { align-content: start; }
.adm-grid .adm-input, .adm-grid .adm-select { height: var(--a-control); padding-block: 0; }
```

Without the `min-height`, a wrapping label ("Kolom server / zona") pushes only its own input down 9–10px and the row reads as broken.

Filter rows (`.adm-filters`) share widths instead of hugging content:

```css
.adm-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.adm-filters .adm-field, .adm-filters .adm-field.narrow { flex: 1 1 80px; min-width: 0; }
.adm-filters .adm-field.search { flex: 1.6 1 120px; }
.adm-filter-actions { display: flex; gap: 8px; margin-left: auto; flex: 0 0 auto; }
```

- Give the search field its own `.search` class; do not use `:first-child` (the class survives reordering and lets the advanced-filter row use the same rule).
- **Delete inline `style="flex:2 1 220px"` from the markup before tuning the class rule.** An inline flex beats every `.adm-filters .adm-field` rule, so the widths never converge and each CSS attempt looks like it "did not apply". Grep `flex:2 1` under `Pages/Admin/` first.
- Never put `max-width` on a growing field — the item stops growing and leaves a hole while the rest stretch.
- Group the submit + reset buttons in one `.adm-filter-actions` div; two loose buttons get separate `margin-left:auto` and drift apart.
- Under 620px: `.adm-field { flex: 1 1 calc(50% - 4px) }`, `.search { flex: 1 0 100% }`, `.adm-filter-actions { flex: 1 0 100% }` with `> button { flex: 1 1 0 }`.
- Pager: `.adm-pager > span:first-child { flex: 1 0 100% }` on phones so the count sits above two equal buttons instead of wrapping one button to a second line.
- **Keep the desktop filter row on flex; switch to grid only under 620px.** A `display:grid` row for the whole filter bar pushes the action group onto its own line at desktop widths and re-creates the "tombol menggantung" complaint. Flex + `.adm-filter-actions{margin-left:auto}` is the shape that holds.
- **Trust the measured rectangles over the vision description.** A screenshot review reported "gaps 4 / 8 / 16px, kolom nempel" on a row that measured exactly 8px between every child; chasing it produced four CSS rewrites and no change. Compare `getBoundingClientRect()` gaps first; only act on a visual claim the numbers confirm.

**Measure with `.adm-field` rectangles only.** Buttons sit on `align-items:flex-end`, so their `top` is one control-height below the labels; including them in a row-grouping scan reports a phantom second row on every page. Group by `Math.round(top/10)` over inputs/selects only, and check `dT > 2 || dH > 2` within a group.

**Assert no dialog is open before auditing layout.** An open `AdminModal` sets `pointer-events:none` and a scrollbar-compensation `padding-right` on `body`; every element then measures a few pixels wide (a 375px page reports `.adm` at 14px) and the audit invents a catastrophic bug. Check `document.querySelectorAll('.adm-dialog').length === 0` and `getComputedStyle(document.body).pointerEvents !== 'none'` first, and reload after closing a dialog rather than measuring the dimmed page.

**`wait_for_load()` returns before Vue mounts — poll for the element, never query once.** A `js("...querySelector('.adm-stats').children...")` fired straight after navigation throws `Cannot read properties of null` on some pages and succeeds on others, which reads as an intermittent bug in the page. Wrap every probe:

```python
def probe(url, expr):
    goto_url(url); wait_for_load()
    for _ in range(15):
        try: r = js(expr)
        except Exception: r = None
        if r: return r
        time.sleep(0.4)
    return r
```

Guard the expression with `if(!g) return null` so the retry sees a clean miss instead of an exception. The same applies to the login form: poll `document.querySelectorAll('input').length` before `fill_input`, or the fill fails with `element not found` on a page that is still booting. And after a successful login the redirect can land on a page other than the one you asked for — always assert `location.pathname` before measuring.

**`capture_screenshot()` overwrites one path — copy it per page or you review the wrong page.** It returns the same file every call, so a loop that screenshots five routes and then hands the paths to `vision_analyze` shows the *last* page five times; worse, a mid-loop session expiry makes every shot a login form and the vision review confidently describes the login screen as the page under audit. Copy to a distinct name immediately after each capture and assert the page is the one you asked for:

```python
for p in ('customers', 'support'):
    goto_url(f'http://127.0.0.1:8123/admin/{p}'); wait_for_load()
    assert js("location.pathname") == f'/admin/{p}'
    shutil.copy(capture_screenshot(), f'/tmp/shot-{p}.png')
```

A vision verdict that describes a layout the code cannot produce (a login card where a table should be) means the screenshot is stale — re-shoot before acting on it, and never report its findings to the owner.

**A pop-up that opens itself on a page whose `open` ref is a string.** Symptom: the dialog covers the page on first load, `body` is locked, and every measurement reports a ~14px page. Cause: `defineModel('open', { type: Boolean })` — Vue casts the empty string to `true`, so `const modal = ref('')` reads as "open". Declare the model without a Boolean type and drive the dialog from a computed that only ever closes:

```js
const open = defineModel('open', { default: false });
const isOpen = computed({
    get: () => !!open.value,
    set: (v) => { if (!v) open.value = false; },
});
```

Widening the type to `[String, Boolean]` does **not** fix it — the empty string still passes validation. Verify by reloading the page and asserting `document.querySelectorAll('.adm-dialog').length === 0` before touching any measurement.

## Legacy sheet widths

`<SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">` for a single-record editor (account, small form). `sm:max-w-2xl` for a form with a preview. `sm:max-w-3xl`/`5xl` only for genuinely wide workspaces (product editor, nominal editor). A 3xl panel holding a 4-column form reads as a page, not an editor — narrow the panel before adding columns.

## Confirmation dialog

`resources/js/Components/AdminConfirmDialog.vue` wraps reka-ui `DialogRoot` + `DialogOverlay`/`DialogContent` at z-95/96 with the dark theme.

```html
<AdminConfirmDialog :open="Boolean(deleting)" title="Hapus akun Admin"
  :message="deleting ? 'Akun ' + deleting.name + ' akan dihapus permanen.' : ''"
  confirm-label="Hapus akun" destructive
  @update:open="value => { if (!value) deleting = null; }"
  @confirm="confirmRemove" />
```

State pattern: `const deleting = ref(null)` + `askRemove(row)` sets it, `confirmRemove()` clears it and fires the request. Never `window.confirm`.

## Human labels for raw codes

- payment status → `paymentStatusLabel` from `resources/js/lib/orderStatus.js`;
- order status → `orderStatusInfo` in the same file;
- audit action / target type → `App\Support\AuditLabels` (`actionLabel()`, `targetLabel()`), inject it into the controller and pass `action_label` / `target_label` to the page;
- notification severity → a four-entry map in the page (`INFO`→Info, `WARNING`→Peringatan, `ERROR`→Galat, `CRITICAL`→Kritis);
- payment channel → join `payment_channels` and send `channels.name`; the raw `channel_code` (`qris`, `ewallet`, `virtual_account`, `manual_qris`, `saldo`) is not display text. Fallback when no row matches: `strtoupper(str_replace('_', ' ', $code))`.

## Permission-scoped sections

A chart of **order counts** is not financial data. Gate the revenue metric, not the whole block:

- controller: build the chart when `$finance || $canOrders`, and select revenue as `$finance ? 'SUM(...)' : '0'`;
- page: `v-if="canViewFinance || canOrders"` on the card, `v-if="canViewFinance"` on the revenue/orders toggle.
