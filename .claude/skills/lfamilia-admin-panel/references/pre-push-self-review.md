# Pre-push self-review (act like the reviewer, before the reviewer does)

The owner expects a PR to be *already* clean when Codex sees it. Each Codex round costs a 10–20 minute CI cycle, so the goal is to find the defects first. Run this pass over every page touched since the branch point, in one batch, before pushing.

## 1. Stale-object audit (highest hit rate)

Static greps only catch the shape you already guessed. The reliable method is per-page **scenario simulation**: for each `Sheet` on the page, write down the ref that controls it, every place that ref is set, and every place it is cleared — then walk the sequences below. A ref with no `onSuccess` clear, or an editor target stored as an object, shows up as a wrong final screen every time.

Sequences to walk for every entity (banner, pop-up, news, FAQ, review, asset, category, product, package, voucher, payment channel, admin, ticket):

1. Open editor → upload image → (props refresh) → does the image still show?
2. Open editor → save → is the sheet closed and the row updated?
3. Open editor → delete that row → is the sheet gone, or empty but open?
4. Open row A → close → open row B → does B show B's data?
5. Open create form → submit invalid → fix → submit → does it save?
6. Open create form right after editing another row → is it empty?
7. Delete → cancel in the confirm → nothing posted, sheet still open?
8. Change filter/page while a sheet is open → does the sheet survive with stale content?
9. Row deleted elsewhere (or by a second tab) → does the open sheet point at a ghost?
10. Restricted role (ADMIN vs SUPER_ADMIN) → do the buttons that are hidden also fail server-side?
11. 390 px → is the full-width sheet's close/scroll reachable?

Run these as **several parallel auditors**, one per group of pages, each returning only file:line + scenario + observable wrong behaviour. One agent reading 55 files always stops early and reports the easy ones.

Have each auditor **append its findings to a file** (`/tmp/audit-<group>.md`), not only return a summary. A child whose parent session ends mid-run comes back `status=unknown` with no summary at all, and the only surviving record is that file. Treat every claim from a child as a lead, not evidence: re-open the file:line yourself before putting it in a report.

Any state that holds a record object instead of an id goes stale on the next Inertia visit:

```python
import glob, re
for p in sorted(glob.glob('resources/js/Pages/Admin/*.vue')):
    s = open(p).read()
    for name in re.findall(r'const (\w+) = ref\(null\)', s):
        if re.search(rf'{name}\.value = (item|row|admin|attempt|channel|voucher|pack|tier|payment|product|notice|asset|banner|popup|news|faq|review)\b', s):
            print(p, name)
```

For each hit decide: is it an editor target (must become `selectedId` + `computed(() => props.list.find(...))`), or a pending-confirmation object (fine, it is cleared before the request)? Editors that embed `AdminMediaControl`, upload, or delete are the ones that visibly break — the image comes back wrong the moment the child component resets its preview.

## 1b. Route tanpa pemanggil — sweep wajib setelah tulis-ulang lapisan UI

Redesign memindahkan kontrol antarhalaman; endpoint-nya tetap, tombolnya hilang. Ini penyumbang temuan P2 terbanyak setelah rebuild, dan tidak terlihat di diff mana pun — diff hanya memuat yang ditulis, bukan yang tidak ditulis.

```bash
php artisan route:list --path=admin --json > /tmp/admin-routes.json
```

Untuk setiap route `POST/PUT/PATCH/DELETE` di `/admin`, cari URL-nya di frontend (`grep -rn "/admin/<segmen>" resources/js`). Nol pemanggil berarti salah satu dari dua hal, dan keduanya wajib diputuskan sebelum push: **kontrol yang hilang** (kembalikan tombolnya ke menu barunya) atau **endpoint mati** (hapus route-nya; jangan dibiarkan menggantung). Hal yang sama untuk `GET` detail: route tanpa tautan = halaman yang tidak bisa dicapai.

Jangan menyimpulkan "sudah tidak dipakai" dari satu grep — URL bisa dirakit dari peta (`URLS[type] + '/' + id`), dibungkus helper (`withSensitive`), atau dipanggil dari komponen bersama. Periksa juga komponen di `resources/js/Components/`.

**Jangan uji dengan potongan terakhir URL saja.** Memeriksa apakah `last-segment` muncul di `resources/js` selalu menghasilkan nol yatim, karena nama metode/segmen generik (`sync`, `products`, `mappings`) muncul di tempat lain. Yang bekerja: grep **ekor khas** dari path — `sources/digiflazz`, `mappings/`, `products/${...}/sync`, `packages/${...}/duplicate` — satu per satu, lalu baca hasilnya. Sweep yang melaporkan "0 yatim" padahal ada empat route tanpa tombol adalah sweep yang salah metode, bukan repo yang bersih.

## 2. Destructive-action close

For every `router.delete(...)`: does the sheet that hosted the row close on success? Grep `router.delete` and check for `onSuccess` clearing the id — `preserveScroll: true` alone leaves an empty panel open with no content. Bulk-audit this; it was the single most repeated finding across pages (reviews, banners, payment channels).

## 3. Confirmation is themed, never native

```bash
grep -rn 'window\.confirm\|window\.alert' resources/js/Pages/Admin/ resources/js/Components/
```

Zero hits expected; every one is a grey browser box on a dark panel. **Search `Components/` too**: a shared control (`AdminMediaControl` is embedded in seven editors) keeps the native box alive on every page that mounts it while a page-only grep reports clean. Shared components are the highest-leverage place to look, because one miss multiplies by the number of consumers and no diff-based reviewer sees it.

## 4. Label sweep

Raw values reaching the screen: `{{ x.status }}`, `{{ x.severity }}`, `{{ x.payment_status }}`, `{{ x.channel_code }}`, `{{ row.action }}`, snake_case codes, English button text. Also check the *server* side — notification bodies and audit actions are written into the DB and read verbatim by admins, so translate them at the source, not only at render time.

## 5. Kontrak form → server, tiga arah

Untuk setiap field yang dikirim, pastikan controller memvalidasi **dan** menyimpannya. Switch yang divalidasi lalu di-hardcode (`'is_active' => false`) melaporkan sukses dan menyimpan kebalikannya. Pasangkan tiap form dengan daftar `$request->validate()`-nya.

Periksa tiga arah, bukan satu — dua arah sisanya yang paling sering jadi temuan reviewer:

1. **Form → server:** field dikirim, server tidak membacanya. Tombol simpan melaporkan sukses, nilainya tidak pernah tersimpan.
2. **Server → form:** field wajib di server, tidak ada di pop-up. Request langsung lolos, tapi admin tidak bisa mengisi lewat layar; atau sebaliknya pop-up menampilkan kontrol yang tidak punya field di payload.
3. **Server → server (paling berbahaya):** payload edit menimpa kolom yang tidak pernah ditampilkan. Diff setiap kunci payload terhadap kontrol yang benar-benar ada di pop-up:

```python
import re
src = open('resources/js/Pages/Admin/Catalog.vue').read()
block = src[src.index('const saveNominal'):src.index('const uploadNominalImage')]
print(sorted(set(re.findall(r'^\s{8}([a-z_]+):', block, re.M))))
```

Setiap kunci hasil itu harus punya pasangan `<input v-model="...">` di modal yang sama. Kunci tanpa kontrol (`nominal_value: null`, `margin_percent: 0`, `pricing_mode` hasil pemetaan) **menghapus data yang tidak pernah dilihat admin** — kirim nilai tersimpan atau jangan kirim sama sekali.

Dua pola tambahan yang selalu lolos kalau hanya membaca diff:

- **Mode/nilai dipetakan ulang saat membuka pop-up.** `row.pricing_mode === 'SELL_PRICE' ? 'FIXED' : 'MARKUP'` menyeragamkan empat mode tersimpan jadi dua, lalu simpan mengirim mode hasil seragam itu. Admin yang hanya mengganti nama ikut mengubah aturan harga. Petakan apa adanya; kirim field harga hanya bila admin menyentuhnya.
- **Dua sumber untuk satu angka.** Setelah kunci setelan lama diganti (mis. `catalog.default_margin_percent` → `pricing.sell_markup_percent`), grep kunci lama di **controller** juga, bukan hanya di Vue: prop lama yang masih dikirim (`defaultMargin`) akan dipakai form mana pun yang belum disentuh, dan angkanya menang tanpa terlihat.

## 6. Boundary arithmetic

Every "today", "7 days", "this month" cutoff must be built in WIB and expressed in the storage zone through one helper. Grep for `->utc()` in controllers that filter timestamps — a hand-converted cutoff is the one that drifts.

## 7. Restricted-role rendering

For each page that zeroes a series for a role without finance access, check the page's default selection matches the visible series. A `ref('revenue')` default renders flat bars forever.

## 8. Copy that promises a value

Any sentence like "mengikuti bagian yang sedang dibuka" must bind the actual current value (section label), not a period label that never changes.

## 9. Test-tree sweep

```bash
grep -rn "<old label>" tests/Browser/
node --test tests/Browser/admin-ui-guards.test.mjs tests/Browser/admin-relocation.test.mjs
node tests/Browser/admin-ui.mjs <chrome> <group>   # one group, not all
```

Renaming a heading, tab, or button breaks a static guard or a click locator; the `all` matrix aborts at the first miss and costs 15 minutes to learn one line.

## 10. Data fixtures are idempotent

Run `php tests/Browser/fixture.php` twice and count the affected rows in between. A random idempotency key or an upsert keyed on the wrong unique index makes every run add rows that later break count assertions and the import picker.

## 11. Pindai statis — dan apa yang tidak dicakupnya

```bash
env -u PYTHONPATH semgrep --config p/php --config p/javascript --config p/secrets \
  --metrics=off --json --output /tmp/semgrep.json <berkas berubah>
```

`PYTHONPATH` milik Hermes membuat semgrep gagal impor; `env -u PYTHONPATH` menyelesaikannya.

**Semgrep tidak mem-parse `.vue`.** Berkas Vue masuk daftar target tapi tidak menghasilkan apa pun — `paths.scanned` kosong dan "0 temuan" di situ berarti "tidak diperiksa", bukan "bersih". Tutup bagian Vue dengan ripgrep, dan sebutkan pembagian itu di laporan supaya angka nol tidak dibaca sebagai jaminan:

```bash
rg -n 'v-html|innerHTML|eval\(|new Function|dangerouslySetInnerHTML' resources/js/Pages/Admin resources/js/Components
```

Nol temuan semgrep **bukan** bukti tidak ada bug kontrak form/server — pemindai statis tidak tahu field mana yang dibaca controller. Itu pekerjaan bagian 5, dan itulah sebabnya putaran reviewer tetap menemukan hal baru walau pemindaian selalu hijau.

## Reporting the pass

Report **each round** as a short list — what was checked, what was fixed, what was deliberately left — and do not claim "semua aman" from a grep you did not run.

**Jangan push commit yang baru saja selesai diperbaiki.** Alur yang diminta pemilik: commit → self-review commit itu → laporkan temuannya → perbaiki → commit lagi → review lagi, dan push hanya pada putaran yang tidak menemukan skenario baru. Push tepat setelah commit dibaca sebagai melewati review, dan setiap putaran yang langsung di-push menambah satu putaran reviewer otomatis lagi.

Saat temuan reviewer datang belakangan, **mulai perbaikannya** saat itu juga tanpa menunggu CI (CI hanya membuktikan commit lama) — tapi perbaikannya tetap lewat loop yang sama: commit, self-review, baru push.

Push yang berakhir tanpa exit code (koneksi putus, job latar mati) **belum terjadi**. Pastikan dengan `git rev-parse HEAD origin/<branch>`; kalau berbeda, push lagi, dan sebutkan SHA mana yang benar-benar ada di remote — jangan melaporkan pekerjaan sebagai terkirim berdasarkan perintah yang tidak pernah mengembalikan status.

## Calibrating a static sweep script

A regex sweep is only useful once it stops reporting noise. Two rules:

- **Match shapes, not lines.** `x.value = <rhs>` matches message strings and ternaries; constrain the right-hand side to a bare identifier from a known list or an object literal, and ignore values that start with an operator.
- **Accept the indirect close.** A panel whose ref looks unreset may be closed by a helper (`closeEditor()`) or by the form's own `onSuccess`. Treat any `close*()` inside `onSuccess`/`onFinish` as a reset, or the real hits drown.

Every surviving marker is a question to answer, not a finding to report.
