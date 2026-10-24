# Produk (`/admin/catalog`) — plan contract and gap checklist

Source of truth: `docs/superpowers/plans/2026-10-19-admin-panel-total-redesign.md` §4.5 + §4.6 and the implementation plan's Task 7. Re-read both before editing; this file is the condensed contract plus the traps found while auditing the page.

## Contract

**Two surfaces, not one.**

- **List**: nama, kategori, jenis penanganan, jumlah denominasi, status. Search and filters above the list. `tab` splits `AUTO_PROVIDER` / `MANUAL`. Clicking a row opens the workspace.
- **Workspace** (one product, in a `Sheet`, driven by `?edit=<id>`): Informasi → Nominal & Harga → Tampilan Produk → Data Pelanggan → Penanganan. Basic info and status first; markup settings opened only when needed.

**Nominal & Harga**: sell price, store cost, and source side by side; each nominal has its own image editor (`AdminMediaControl type="package"`), not only the product image. No backup provider source.

**Data Pelanggan**: field name, order, required — inside the workspace. Provider's technical concatenation format (`customer_no_template`) goes in an advanced `<details>`, prefilled by "Tambah <field>" buttons.

**Tampilan Produk**: image, banner, notices with a storefront preview, plus popular-product toggle (`PUT /admin/vouchers/popular/{id}` — the route keeps its name even though the control now lives here).

**Kategori**: its own menu (nama, ikon, urutan, jumlah produk, inline edit, image, active toggle).

**Produk terlaris**: belongs on Produk, removed from Dashboard. Nothing renders it today — Laporan/Reports still computes `topProducts`.

## Nominal MANUAL — satu status, dua baris

Modal nominal MANUAL tinggal di `provider_mappings.cost_idr`, bukan di `product_packages`, dan status aktifnya ada di **dua** tempat. Menyimpan `is_active` hanya ke paket membuat nominal yang baru diaktifkan dari pop-up tetap nonaktif di pemetaan: paket tampil aktif di panel tapi tidak bisa dibeli di checkout.

- Tulis `is_active` ke paket **dan** ke pemetaan provider dalam satu permintaan.
- Tolak mengaktifkan nominal MANUAL dengan modal ≤ 0, dan tolak `sell_price_idr` < modal — paket aktif tanpa modal mustahil dibeli, dan tidak ada jalur refund untuk membatalkannya.
- Form yang mengubah nominal manual wajib mengirim `cost_idr`; pop-up yang hanya mengirim `payload` menutup dialog dengan modal lama dan margin/akuntansi ikut basi.
- Nominal baru dari form menyimpan `is_active`, `pricing_mode`, dan `sell_price_idr` apa adanya dari form — jangan hardcode `false`.

## Audit checklist for this page

1. Category still a tab (`catalogTabs`) instead of its own menu.
2. Page-level "Kolom Data Pelanggan" tab duplicates the in-editor Data Pelanggan tab — two write paths for `PUT /products/{id}/fields`.
3. "Produk terlaris" homeless.
4. Three pricing cards (fixed-price policy, global margin, two-stage settings) sit above the list, uncollapsed.
5. Seven native `confirm()` calls: apply fixed prices, lock sell prices, duplicate-nominal import, delete category/product/package, delete notice.
6. `markupExample` in the page re-implements `CatalogPricingSettings` rounding.
7. Voucher-stock block rendered for every `AUTO_PROVIDER` product, not only ones with a `VOUCHER_STOCK` mapping.
8. Hard-coded palette left in the sheet: `border-slate-200`, `bg-cyan-400 text-slate-950`.

## Guard tests that will fail if you move things

- `tests/Browser/admin-relocation.test.mjs`: requires `Tampilan Produk`, the `admin/vouchers/popular/` call, and the exact popular-toggle button markup.
- `tests/Browser/admin-ui-guards.test.mjs`: create form must stay in `<Sheet :open="showCreateProduct">`; no `<form v-if="showCreateProduct">`; `AdminResponsiveTable scroll class="min-w-[1100px]"`; `store_cost_idr` fallback; `price=props.checkoutPreviews[pack.id]`; import-preview markup; notices preview `aria-label`.
- `tests/Browser/admin-ui.mjs`: drives the real editor sheet and asserts `document.querySelector('[role=dialog]')` plus the five tab labels by exact text. Renaming a tab or wrapping the workspace outside the sheet breaks it.
- Feature: `AdminPanelRestorationTest` asserts `checkoutPreviews.<id>`, `products.0.packages.0.*`, pricing-settings percentages, package order; `AdminDigiflazzRestorationTest` asserts the Digiflazz menu entry.

## Local DB reality

`products` and `product_packages` are usually **empty** locally, while `provider_mappings` keeps rows. An empty catalog page is not a bug — run `php tests/Browser/fixture.php` first. Column name is `product_package_id` on `provider_mappings`, not `package_id`.
