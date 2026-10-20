# Linear / Vercel Enterprise Admin Redesign Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merombak total visual seluruh panel admin LFAMILIA ke gaya **Data Dense Enterprise / Linear-Vercel style** (garis pemisah tipis halus, background gelap pekat yang dalam, tanpa card kotak-kotak kaku, tabel rapat ber-tabular numbers, dan pop-up bersih tanpa teks mentah).

**Architecture:** Memperbarui global CSS tokens (`resources/css/app.css`), memperbarui komponen dasar (`Table`, `Card`, `Modal`, `Button`, `Input`) di `resources/js/Components/ui/`, lalu menyelaraskan seluruh 17 halaman admin agar menggunakan gaya data-dense yang konsisten.

**Tech Stack:** Vue 3, Inertia.js, Tailwind CSS v4, Reka UI.

**Spec:** Pilihan user: Gaya **Data Dense Enterprise / Linear-Vercel style**.

## Global Constraints
- Jangan mengubah logika backend, kalkulasi pricing, migration, atau test suite yang sudah hijau.
- Pertahankan struktur routing, form submission, dan data props dari backend.
- Haram card kotak-kotak kaku di mobile; wajib tabel baris bersih dengan horizontal scroll.

---

### Task 1: Global Design Tokens & Enterprise Dark Theme (`resources/css/app.css`)
**Files:**
- Modify: `resources/css/app.css`

- [ ] **Step 1: Terapkan token warna enterprise dark (deep charcoal/zinc-950 background, hairline borders `#ffffff10`, monospaced tabular numbers, single crisp accent color).**
- [ ] **Step 2: Commit**

### Task 2: Core UI Components (Table, Modal, Button, Input) Redesign
**Files:**
- Modify: `resources/js/Components/ui/table.vue`, `card.vue`, `modal.vue`, `button.vue`, `input.vue`, `AdminResponsiveTable.vue`

- [ ] **Step 3: Rombak `table.vue` & `AdminResponsiveTable.vue` jadi compact, data-dense, hairline borders, horizontal scroll wajib di mobile tanpa card bertumpuk.**
- [ ] **Step 4: Rombak `modal.vue` jadi clean modal tanpa JSON mentah, backdrop blur pekat, aksi di bawah jelas.**
- [ ] **Step 5: Rombak `button.vue` & `input.vue` jadi ringkas, tegas, dan responsif.**
- [ ] **Step 6: Commit**

### Task 3: Admin Shell & Navigation Unification (`AdminShell.vue`)
**Files:**
- Modify: `resources/js/Components/AdminShell.vue`

- [ ] **Step 7: Rombak `AdminShell.vue` dengan sidebar gelap rapat, header minimalis, dan breadcrumb bersih.**
- [ ] **Step 8: Commit**

### Task 4: Rollout to Core Admin Pages (Catalog, Orders, Dashboard, etc.)
**Files:**
- Modify: `resources/js/Pages/Admin/*.vue`

- [ ] **Step 9: Sesuaikan halaman utama (Catalog, Orders, Dashboard, Providers, Settings) ke gaya data-dense enterprise.**
- [ ] **Step 10: Commit**

### Task 5: Verification & Tests
**Files:**
- Test suite & Browser smoke test

- [ ] **Step 11: Jalankan PHPUnit dan Playwright smoke test untuk memastikan semuanya hijau dan stabil.**
- [ ] **Step 12: Commit**
