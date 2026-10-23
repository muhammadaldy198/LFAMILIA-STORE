# Global Admin & Storefront Visual Redesign Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merombak tampilan visual seluruh halaman admin dan storefront secara menyeluruh agar memiliki Design System yang seragam, bersih, modern, dan menghilangkan kesan "tambal-sulam".

**Architecture:** Membangun global design tokens (colors, typography, spacing, radius) dan menyelaraskan komponen UI bersama (AdminShell, Navigation, Tables, Cards, Modals, Badges, Forms) di seluruh halaman Inertia Vue.

**Tech Stack:** Vue 3, Inertia.js, Tailwind CSS, Radix Vue primitives.

**Spec:** Perintah user untuk merombak total UI secara keseluruhan (bukan cuma katalog).

## Global Constraints
- Jangan mengubah logika backend, kalkulasi pricing, migration, atau test suite yang sudah hijau.
- Pertahankan struktur routing, form submission, dan data props dari backend.
- Pastikan semua halaman admin responsif dan bersih dari gaya inkonsisten.

---

### Task 1: Global Design Tokens & Typography Foundation
**Files:**
- Modify: `resources/css/app.css` (atau file styling global utama)
- Create: `resources/js/Components/ui/ThemeTokens.js` (jika diperlukan untuk konstanta visual)

- [ ] **Step 1: Definisikan palet warna konsisten (slate/zinc netral, indigo/emerald/violet aksen) dan skala tipografi global.**
- [ ] **Step 2: Bersihkan style duplikat / konflik antar modul CSS.**
- [ ] **Step 3: Commit**

### Task 2: Admin Layout & Shell Unification (`AdminShell.vue`)
**Files:**
- Modify: `resources/js/Components/AdminShell.vue`
- Modify: Header, Sidebar, dan Navigation components.

- [ ] **Step 1: Perbarui `AdminShell.vue` agar header, sidebar navigasi, dan container utama memiliki kontras, spacing, dan border-radius yang seragam.**
- [ ] **Step 2: Pastikan breadcrumbs dan state aktif menu terlihat jelas dan bersih.**
- [ ] **Step 3: Verifikasi responsivitas mobile menu (drawer / collapse).**
- [ ] **Step 4: Commit**

### Task 3: Global UI Component Standardization (Tables, Cards, Modals, Buttons)
**Files:**
- Modify: `resources/js/Components/ui/table.vue`, `card.vue`, `modal.vue`, `button.vue`, `input.vue`

- [ ] **Step 1: Standarisasi padding, border, shadow, dan hover state pada Card & Table di seluruh halaman admin.**
- [ ] **Step 2: Seragamkan tombol aksi (primary, secondary, danger) dengan ukuran dan state disabled yang jelas.**
- [ ] **Step 3: Pastikan Modal dan Dialog memiliki backdrop blur dan batasan ukuran layar yang konsisten.**
- [ ] **Step 4: Commit**

### Task 4: Visual Polish Across Admin Pages (Dashboard, Orders, Providers, Settings, dll.)
**Files:**
- Modify: `resources/js/Pages/Admin/*.vue` (Dashboard, Orders, Providers, Vouchers, Settings, Integrations, dll.)

- [ ] **Step 1: Terapkan komponen UI yang sudah diseragamkan ke halaman Dashboard dan Orders.**
- [ ] **Step 2: Terapkan ke halaman Providers, Vouchers, dan Support.**
- [ ] **Step 3: Terapkan ke halaman Settings, Integrations, dan Health/Audit.**
- [ ] **Step 4: Commit**

### Task 5: End-to-End Visual & Functional Verification
**Files:**
- Modify: `tests/Browser/admin-ui.mjs`

- [ ] **Step 1: Jalankan seluruh suite test PHPUnit dan Playwright browser smoke test untuk memastikan tidak ada fungsionalitas yang rusak.**
- [ ] **Step 2: Commit akhir.**
