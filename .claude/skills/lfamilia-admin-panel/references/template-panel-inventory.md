# Template panel inventory — the 19 menus to plan against

Source of truth: the owner's single-file HTML prototype (`template-panel-admin-lfamilia-store.html`, ~3,000 lines, 23 `<section class="page">` blocks, 22 sheets). He accepted **19 sidebar menus** and said Notifikasi lives in the top bar instead. Plan menu by menu in this order; quote labels verbatim.

Extraction recipe — never eyeball it:

```python
import re
s = open(path, encoding='utf-8').read()
starts = [m.start() for m in re.finditer(r'<section[^>]*class="[^"]*\bpage\b[^"]*"', s)]
# slice [start_n, start_n+1), strip <script>/<style>, replace tags with newlines, keep non-empty lines
```

Sheets are found by `id="sheet-..."`; a button opens one via `data-sheet-open="sheet-..."`.

## Locked walkthrough numbering (20 menus, 19 sidebar items)

`page-presentation` is not a sidebar item — it is where the `Tema & Presentasi Toko →` link in the Banner & Konten head goes — but it gets its own turn in the ritual, so the run is 20 numbered menus. **This numbering is agreed with the owner; use it, never re-derive it from file order.** The `<section>` blocks sit in the file in authoring order (`page-access` and `page-settings` come *before* `page-integrations`, `page-health`, `page-audit`), so walking the file renumbers everything from 15 on and he corrects it: "Menu 16 bukannya harusnya integrasi?".

1. Dashboard → `page-dashboard`
2. Pesanan → `page-orders` (+ sub-page `page-order-detail`)
3. Manual → `page-fulfillment` (titled **Antrean Pesanan**)
4. Promo → `page-promo` (titled **Voucher & promo**)
5. Produk → `page-catalog`
6. Kategori → `page-categories`
7. Banner & Konten → `page-content`
8. Tampilan Toko → `page-presentation` (reached from menu 7's head link)
9. Digiflazz → `page-digiflazz`
10. Validasi Akun → `page-nickname` (titled **Alat cek nama game**)
11. Provider → `page-providers` (titled **Penyedia layanan**)
12. Pembayaran → `page-payments`
13. Laporan → `page-reports`
14. Pelanggan → `page-customers` (+ sub-page `page-customer-detail`)
15. Layanan Pelanggan → `page-support` (titled **Bantuan pelanggan**)
16. Integrasi → `page-integrations`
17. System Health → `page-health` (titled **Kesehatan Sistem**)
18. Audit Log → `page-audit` (titled **Catatan Audit**)
19. Admin & Akses → `page-access`
20. Pengaturan → `page-settings`

**Off-sidebar screen:** `page-notifications` (top-bar bell).

## Menu 7 — Banner & Konten (`page-content`)

Head: `Banner & Konten` + *"Atur gambar, berita, ulasan, FAQ, dan halaman kebijakan toko dalam satu tempat. Tema warna dan urutan bagian halaman utama diatur lewat menu Tema & Presentasi Toko."* Right side of the area tabs: `Tema & Presentasi Toko →`.

Three area tabs, each with its own inner tab strip:

| Area | Inner tabs | Rows |
|---|---|---|
| Tampilan Toko | Banner Utama · Pop-up Pengumuman · Logo & Gambar Cadangan · Teks Footer & Bantuan | see below |
| Bantuan & Kebijakan | Pertanyaan Umum (FAQ) · Kebijakan Toko | FAQ list + `Tambah FAQ`; 6 policy rows |
| Berita & Ulasan | Berita · Ulasan Pelanggan | news list + `Tambah berita`; review rows with `Tinjau` |

- **Banner Utama** — toolbar note *"Banner geser di halaman depan toko"* + `Tambah banner`. Row: name · `/promo/grand-opening · urutan 1 · desktop & ponsel` · badge Aktif/Nonaktif · `Ubah`. Sheet `sheet-banner`: Nama banner (juga menjadi teks alternatif gambar) · Teks kecil pada banner (opsional) · Alamat tujuan saat banner ditekan · Urutan tampil · Tampil di desktop · Tampil di ponsel · Aktif · Gambar desktop · Gambar mobile · Pratinjau banner. Footer `Hapus` `Tutup` `Simpan`.
- **Pop-up Pengumuman** — a single row. Sheet `sheet-popup`: Judul · Isi · Jangan tampil lagi selama (hari) · Aktif · Gambar pop-up · Pratinjau pop-up. Footer `Hapus` `Tutup` `Simpan pop-up`.
- **Logo & Gambar Cadangan** — **8** rows, each opening `sheet-asset`: Logo toko · Favicon · Banner cadangan desktop · Banner cadangan mobile · Gambar footer desktop · Gambar footer mobile · **QRIS manual** (`manual_qris`) · **Gambar halaman pembayaran** (`payment_header`). Sheet: Tautan saat gambar ditekan · Gambar aset · Hapus gambar · Aktif dipakai toko.
- **Teks Footer & Bantuan** — one form, 9 fields: Judul bagian berita di halaman utama · Pengantar bagian berita · Blok bantuan (sakelar) · Label kecil · Teks tombol · Judul bantuan · Deskripsi bantuan (full width) · Kalimat footer · Tombol mengambang (sakelar). Footer: `Buka Pengaturan` · `Simpan teks bantuan`.
- **Kebijakan Toko** — **6** rows: Tentang kami (`about`) · Syarat dan ketentuan (`terms`) · Kebijakan privasi (`privacy`) · Kebijakan pengembalian (`refund`) · Kontak (`contact`) · Bantuan (`faq`). Sheet `sheet-policy`: Judul halaman · Pengantar · Isi (awali paragraf dengan `##`, item dengan `-`) · Aktif · Pratinjau halaman.
- **Berita** — row: judul · sumber · tanggal · urutan · badge · `Ubah`. Sheet `sheet-news`: Judul · Alamat halaman · Sumber berita · Ringkasan · Isi · Urutan tampil · Tanggal publikasi · Aktif · Gambar berita · Pratinjau berita.
- **Ulasan Pelanggan** — row: `nama · produk` · bintang + potongan isi · badge Tampil/Disembunyikan · `Tinjau`. Sheet `sheet-review`: the review text, sakelar *Tampilkan di halaman produk*, `Hapus` `Tutup` `Simpan`.

Known template/backend gaps to report **after** the menu is locked, never as a reason to trim the list: the backend today exposes 6 asset keys (the template has 8), 3 policy pages (the template has 6), and `destroyReview` tombstones the row instead of deleting it — so the `Hapus` button's real effect has to be described to the owner in those words.

## Per-menu block order to list

Dashboard: head + reload · 8 metric cards (Omzet Hari Ini, Pesanan Hari Ini, Produk Aktif, Saldo Digiflazz, Pembayaran Berhasil, Saldo Wallet, Tiket Terbuka, Pending Fulfillment) · Grafik Penjualan & Pesanan (Hari Ini / 7 / 30 / 90 hari, Omzet vs Jumlah Pesanan, baris puncak) · Pesanan Terbaru (5 baris, 7 kolom) · Aktivitas Terbaru · Status Integrasi · Notifikasi.

Pesanan: head + `Catat pesanan manual` (sheet) · 5 metric cards · search + status (9 options) · Filter lanjutan (Penyedia / Metode pembayaran / Dari tanggal WIB / Sampai tanggal WIB / Pesanan per halaman) · toolbar (Terakhir diperbarui + Perbarui otomatis 30 detik + Muat ulang + Unduh hasil filter) · table 8 columns (Pilih, Pesanan, Pelanggan, Produk & tujuan, Pembayaran, Status Pembayaran, Status Proses, Tindakan) · pagination · Aktivitas pesanan terbaru.

Detail pesanan: back link · invoice + status + created line · Salin nomor / Hubungi pelanggan / Muat ulang / Batalkan pesanan · Status saat ini + Selesaikan pesanan / Nyatakan gagal · Ringkasan pesanan · Pelanggan dan tujuan · Hasil pengiriman · Riwayat pesanan · Pembayaran (Periksa / Konfirmasi) · Proses pesanan (Selesaikan / Nyatakan gagal / Lanjutkan pengiriman).

Antrean Pesanan: head + `Buka Pesanan` · counts strip → **render as stat boxes** · Cari antrean / Cakupan antrean / Status / Baris per halaman · toolbar · table (Pesanan, Pelanggan, Produk & tujuan, Sumber, Status, Aksi) · pagination.

Voucher & promo: head + Muat ulang / Tambah voucher · 4 counts · search + status (6) · Filter lanjutan (Jenis diskon / Voucher per halaman) · table 8 columns (Voucher, Diskon, Minimum, Pemakaian, Periode, Cakupan, Status, Tindakan) · footer count. Sheet `sheet-voucher` = Kode, Nama promo, Jenis diskon, Nilai, Maksimum, Minimum transaksi, Total kuota, Limit per pelanggan, Mulai, Berakhir, Status, Deskripsi internal, Cakupan kategori, Cakupan produk. `Tambah voucher` reuses the same sheet with a different title.

Produk: head (Kategori / Lihat toko / Pengaturan harga lanjutan) · the `Pengaturan harga lanjutan` block is **deleted**, see the price rule in SKILL.md · tabs Produk Otomatis / Produk Manual · Impor Digiflazz + Tambah produk · filters (Cari produk atau nominal, Kategori, Status, Urutkan, Produk per halaman) · table 7 columns (Produk, Kategori, Jenis, Nominal, Urutan, Status, Aksi) · pagination. Sheets: `sheet-add-product` (Kategori*, Nama produk*, Ubah tautan, Merek game, Margin persen, Deskripsi, Atur posisi) and `sheet-product` = **Editor Produk**, five tabs:

| Tab | Fields as the template writes them |
|---|---|
| Informasi | Nama, Alamat produk, Publisher / merek, Kategori, Jenis penanganan, Margin produk (%), Markup jual game (%), Tambahan modal game (%), Posisi di toko, Deskripsi, Status, Aktifkan cek nickname, Kode game, Kolom User ID, Kolom Server / Zone |
| Nominal & Harga | table (Nominal, Sumber / Modal, Harga jual, Margin, Urutan, Status, Aksi), Impor nominal Digiflazz, Tambah nominal |
| Tampilan Produk | Gambar produk / card, Banner halaman produk, Inisial + warna cadangan, Label Instan, Tab grup nominal + urutan tab, Pemberitahuan produk |
| Data Pelanggan | kolom isian (nama, kode, contoh, jenis, wajib), Tambah kolom, Pratinjau isian pelanggan |
| Penanganan | Sinkron seluruh katalog + table (Nominal, Sumber / kode, Status koneksi, Aksi) |

Editor footer: Hapus · Kembali ke produk · Tutup · Simpan produk.

### Produk — locked decisions (do not re-ask)

- **Impor Digiflazz does not live in Produk.** *"Impor dari digiflazz ini buat di digiflazz aja lah biar ga ribet, produk sini."* The Digiflazz menu owns the flow: pick game → pick SKU → import into the target product. Produk only shows the result.
- **Dropped from Tab Informasi:** `Margin produk`, `Markup jual game`, `Tambahan modal game` (they became the two import inputs), and `Kolom User ID` / `Kolom Server / Zone` (duplicated the nickname-validation rule; the requested customer fields come from the game code's validation config instead).
- **`Kode game` stays, and it needs a `Tidak ada` option** — choosing it turns nickname check off for that product.
- **`Jenis penanganan` stays editable** and the template's guard (*"Kosongkan nominal terlebih dahulu jika jenis penanganan memang perlu diubah"*) is removed: switching auto↔manual only stops using the SKU, it does not delete it, and it can be switched back.
- **`Posisi di toko` stays numeric** (owner: "iya bener") and rows are also drag-reorderable — the reorder affordance is mandatory, not optional.
- **Dropped from the nominal sheet:** `Badge`, `Nilai nominal` (order comes from Posisi/drag), `Tambahan modal nominal`, `Mapping fulfillment`, `Kunci harga`, and the whole `Stok Kode Digital` block — stock-code delivery is a `Jenis penanganan` value, not a per-nominal panel.
- **`Kode internal` becomes derived, not typed** — take the Digiflazz SKU when one exists, otherwise generate it from product + nominal. Manual products are the only case with no SKU to borrow.
- **`Posisi` moves to the top of the nominal sheet**, next to the name.

### Produk — Tab Tampilan Produk (locked)

- **`Inisial cadangan` + `Warna aksen cadangan` dropped** — derived from the product name and category, never typed.
- **`Label Instan` dropped, and the `instant` flag with it.** Owner: *"Ga ada gua ga mau! Hapus itu."* Do not carry `products.instant` into the new panel; the card badge it drives stops existing.
- **`Tab grup nominal` switch and `Urutan tab nominal` text box dropped.** The switch forced the customer to click a tab before seeing a nominal; the text box made you retype group names and silently failed on a typo. Groups render as a plain heading above their nominals (`Umum`, then `Pass`, …) with everything scrolling — the storefront already works this way, so match it. Group membership is set only in the nominal sheet's `Grup / tabel` field; empty means `Umum`; group order comes from drag, not typing.
- **Images stay, with the recommended size printed under each field and a live preview below it.** Sizes come from the repo, not from the template's placeholder text.
- **`Pemberitahuan produk` is confirmed real**: a modal on the storefront product page (`Catalog/Show.vue`, one-per-product localStorage dismiss key, `1/2` + `Berikutnya` for multiple). Editing stays in the panel as a list + sheet.

### Produk — Tab Data Pelanggan (locked)

Tab 4 **stays** (option A): nickname validation supplies the defaults, the admin can still add columns for odd providers.

- **`Kode kolom` is a fixed dropdown, not free text.** It is not cosmetic: the product's `customer_no_template` joins those keys (`{{user_id}}({{zone_id}})`) and that string becomes `customer_no` in the Digiflazz transaction request. A typo (`userid`, `user-id`) breaks template validation and the product cannot be sold. Offer exactly the keys the code consumes — `user_id`, `zone_id`, `destination`, `email`, `phone` — and add a key to the list only when a controller starts reading it.
- **Field order is wire order.** `{{user_id}}({{zone_id}})` and `{{zone_id}}({{user_id}})` are different requests, so drag-reorder in this tab is mandatory and must not be presented as a display preference.
- **Show the composed result** under the list (e.g. `Hasil kirim ke penyedia: 12345678(1234)`) so the owner can see the order without reading the template string.

## Menus 8–11 — block order

**Tampilan Toko** (`page-presentation`): head + `Banner & Konten` / `Lihat toko` · 4 tabs.

| Tab | Contents |
|---|---|
| Teks halaman | filter Halaman (Beranda / Checkout / Layout/Header / Layout/Footer) + Cari tulisan · row list (label · current value · badge `Diubah`/`Bawaan`). Sheet `sheet-pres-text`: Teks yang tampil, footer `Gunakan bawaan` + `Selesai` |
| Font & Panel | two blocks, Ponsel and Desktop, same fields: Judul halaman (px) · Nama produk (px) · Harga produk (px) · Teks isi (px) · `Kembalikan ukuran bawaan` |
| Bagian Homepage | 7 switches: Banner utama · Produk populer · Semua produk/katalog · Berita & promo · Ulasan pelanggan · Blok bantuan · Papan peringkat member |
| Warna Website | colour + hex per row, live preview (`Bayar Sekarang →` + Berhasil/Menunggu/Gagal badges), `Kembalikan warna bawaan` |

Sticky footer: *"Perubahan tampilan langsung dipakai halaman pelanggan."* + `Simpan perubahan`.

Locked: **all theme colours** (`config/store_theme.php` has 14 keys, the template shows 4) and **all typography fields** (`config/customer_presentation.php` has 19, the template shows 4) — the owner said "sesuaikan semua warna" and "semua" to both. Never truncate either list to the template's four.

**Digiflazz** (`page-digiflazz`): head + `Impor ke produk` / `Muat ulang` / `Sinkron daftar harga` · 6 cards (Koneksi, Saldo, SKU dalam daftar, Normal, Peringatan, Kritis) · collapsible *Pengaturan pemantauan & sinkron otomatis* (auto-sync switch, interval minutes, low-stock threshold, price-warning %) · price table (Cari SKU, Kategori, Produk, Kondisi SKU; advanced: Cakupan daftar, Brand, Baris per halaman; columns Produk & nominal, SKU / Seller, Harga modal, Stok & jam layanan, Kondisi, Aksi) · *Transaksi Digiflazz terbaru* (Pesanan, Produk, Status, Waktu, Catatan).

Locked: **`Impor ke produk` exists only here**, never on Produk · per-row `Sinkron` dropped (no per-SKU endpoint; the whole pricelist is pulled) · `Jadikan pembanding` + the "Pembanding: Rp …" sub-line dropped (nothing stores it) · service hours dropped, `stock` kept · the Kritis card filters the table on click · the monitoring panel is **real**, do not present it as disabled.

**Alat cek nama game** (`page-nickname`): head + `Siap digunakan` pill + `Pengaturan integrasi` · 4 tabs (Cek nama game, Cek server, Cek PLN, Daftar kode game). Each check tab is a two-column split: form (Pilih game, ID pemain, Server/zona + hint, `Cek data` / `Bersihkan`, result box) beside a *Alamat layanan yang dipakai* block (`Disimpan di server` pill, endpoint, "Kunci akses tidak pernah dikirim ke peramban"). The code list has search + `Tambah kode game`, columns Urutan (↑↓) · Game · Kode game · Cek nama · Server/zona · Region · Status · Produk · Tindakan (`Salin` `Ubah` `Nonaktifkan` `Hapus`); sheet `sheet-gamecode` = Nama game, Kode game, 4 switches (Bisa dicek nama panggilannya, Wajib server/zona, Perlu cek region, Aktif).

Locked: `Hapus` only renders when the game has `0 produk`; a game still used by products shows `Nonaktifkan` instead · `Salin` must blank the code before opening the sheet (codes are unique, an untouched duplicate always fails) · Cek server lists only games flagged *Wajib server/zona*.

**Penyedia layanan** (`page-providers`): head + `Buka Integrasi` · *Daftar penyedia* table (Penyedia/keterangan, Jenis layanan, Koneksi & status, Nominal tertaut, Performa 24 jam, Urutan, Tindakan `Ubah`) · *Nominal produk yang dilayani penyedia* (Cari nominal, Penyedia, `Kelola di Produk`; advanced: Status nominal, Nominal per halaman; columns Produk, Nominal, Penyedia & kode, Modal / Batas, Pembaruan harga, Sumber, Status) · sheet `sheet-provider` (Nama tampilan, Urutan, Keterangan, Aktif).

No `Tambah penyedia` button — providers come from Integrasi; do not add one. Do not hardcode provider rows (`KokinPay` in the template is not in the system); render whatever the database holds. `Nominal tertaut` and `Performa 24 jam` are computable from mappings and `fulfillment_attempts`, so keep them.

## Menus 12–20 — block order and locked decisions

**Pembayaran** (`page-payments`): 3 tabs — Pengaturan Pembayaran · Transaksi Pembayaran · **Tampilan Halaman Pembayaran** (the owner moved the payment-page editor into tab 3; it is not a separate menu). Methods are **two levels**: category (QRIS, E-Wallet, VA Bank, Gerai Retail) then the entries under it (QRIS otomatis, QRIS manual, VA BCA, VA BRI, ShopeePay, GoPay, LFAMILIA Coin, …). Every entry needs its own active switch so it disappears from checkout — "editable harus ada tombol nonaktifnya juga kan biar ga keliatan di panel customer".

- **QRIS manual is two entries and two different images.** `logo metode` is the small mark in the method list; `gambar QRIS` is the code sent to the payment page. They are uploaded through different endpoints (`uploadChannelLogo` vs `uploadManualQris`) and must be labelled as two separate fields everywhere they appear.
- **LFAMILIA Cash is renamed LFAMILIA Coin**, listed as a checkout method, funded only by top-up inside the customer account.
- **DOKU exists but is inactive**; its credentials already live on the VPS — do not present it as missing.
- Tab 3's field list comes from `PaymentPageSettingsService::defaults()` (accent hex, banner image, eyebrow, three state titles, invoice notice title/text, three status texts, three button labels, support text/url, five visibility switches), not from the template's markup.

**Laporan** (`page-reports`): 3 tabs — Penjualan · Produk · Operasional. Penjualan carries **11 period boxes plus one separate "Saldo pelanggan saat ini" box**; `Rentang khusus` is what enables the Dari/Sampai columns; `Unduh laporan` follows the active tab and names the file `laporan-<tab>-<dari>_<sampai>.csv`.

**Pelanggan** (`page-customers` + `page-customer-detail`): 2 tabs. **6 tiers** BASIC / SILVER / GOLD / DIAMOND / PLATINUM / MAFIA.

- **MAFIA is panel-only.** It never appears to customers as a badge or a progress target, and it carries no minimum spend — a PLATINUM customer's progress bar stops at PLATINUM. Only an admin assigns it.
- **MAFIA pricing is the locked cost, not a percentage.** Discount = sell price − `store_cost_idr` from the mapping, so a MAFIA order charges exactly the stored modal; the payment fee is still computed on the discounted `chargeable`. No `store_cost_idr` means no discount — never sell below modal. Cutoff still disables the nominal when the Digiflazz price passes the cap.
- Leaderboard membership renders as a `Ikut` / `Tidak ikut` badge, not a column of ranks.
- `Hapus akun` only for customers with zero orders; everyone else gets `Nonaktifkan akun`.

**Bantuan pelanggan** (`page-support`): the ticket detail is an **in-page panel, not a pop-up** — the conversation needs the width, and the pop-up rule governs editing, not reading. Quick replies persist in `system_settings`, no new table. Guest tickets are real (a migration makes `support_tickets.user_id` nullable and `/support` accepts visitors), so keep the `Sumber tiket` filter with `Akun pelanggan` / `Tanpa akun`.

**Integrasi** (`page-integrations`): one tab per `IntegrationRegistry` entry (9 today), never the template's 6. The webhook/callback fold is derived from the definitions that declare an endpoint (4 today: Digiflazz, Midtrans, DOKU, Google) — no `Tambah alamat` button; a new integration adds its own row.

**Kesehatan Sistem** (`page-health`): only application, MySQL and Redis have real probes today. Render every other template component (backup lokal/off-site, saldo Digiflazz, website toko, job gagal) as `Belum diperiksa` — never a plausible number. `Rekonsiliasi tertunda` maps to `stale_fulfillment`; the gateway panel reads the payment methods table; `Muat ulang` re-runs the checks rather than reloading the page.

**Catatan Audit** (`page-audit`): the Admin / Aksi / Sasaran dropdowns are filled from the distinct values actually present in the audit table, never a hardcoded list. IP is masked (`103.•••.••.12`). Role `Sistem` covers automated actions. `Sebelum` / `Sesudah` only render when the row stored a diff; otherwise the action reads `Tidak ada detail perubahan`.

**Admin & Akses** (`page-access`): `Pemilik aktif` becomes **`Super Admin aktif`**. No delete-admin button — deactivating preserves the audit trail. A password confirmation is required to demote a Super Admin, deactivate an admin, or change permissions. The permission checklist is the **14 permissions** in `AdminPermissionService` (Dashboard mandatory), not the template's 8.

**Pengaturan** (`page-settings`): 11 `store.*` keys, already validated server-side (WhatsApp charset, Instagram/Discord host allow-list, support URL internal-or-http, name 2–80, tagline 3–160). Contacts display **unmasked**; business hours stay free text. The JSON export is **Super Admin only** and already strips every password/secret/token/api-key column plus all `integration.*` keys.

## Template quirks worth naming to the owner

- Counts rendered as an inline sentence are read as boxes (see Antrean Pesanan).
- The Cakupan kategori / Cakupan produk fields are `<select multiple>` in the HTML — they map to a Combobox, not a Select.
- Some sidebar labels differ from the page `<h1>` (Manual vs Antrean Pesanan, Validasi Akun vs Alat cek nama game, Provider vs Penyedia layanan, System Health vs Kesehatan Sistem, Audit Log vs Catatan Audit). Quote the screen title as the page title and the sidebar label as the menu label; do not harmonise them without being told.
- All buttons carrying `data-dummy` are toasts in the prototype — they are not evidence that a feature exists; the real capability has to come from the controller.
- The template's `Tambah nominal` is an inline form under the table; every create/edit surface in the new panel is a Sheet, so present it as a pop-up and say so.
- The `<select multiple size="3">` fields render as an always-open box with three rows, which is why the owner asked "dropdown kalo sekarang itu bukan dropdown kan?" — describe the difference (closed field, opens on click, search inside, chips for chosen values) before he approves the Combobox mapping.
