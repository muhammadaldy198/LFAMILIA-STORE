# LFAMILIA Store UI Visual Redesign & Design System Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development or executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menghilangkan kesan "tambal-sulam" (patchwork UI) dengan membangun Design System konsisten (warna, tipografi, kartu, tabel, tombol, modal) dan merombak tampilan visual Admin serta Storefront secara menyeluruh tanpa mengubah logika/backend yang sudah teruji.

**Architecture:** Membangun core design tokens dan komponen Vue reusable di `resources/js/Components/ui/` (atau menyempurnakan yang ada seperti Button, Card, Badge, Modal, Table), lalu menerapkannya secara konsisten ke halaman Admin dan Customer.

**Tech Stack:** Vue 3, Inertia.js, Tailwind CSS, Radix Vue primitives.

**Spec:** Rencana perombakan visual total (A).

## Global Constraints
- Jangan mengubah logika backend, kalkulasi pricing 2-tahap, atau test suite yang sudah hijau (505 tests).
- Pertahankan struktur routing, form submission, dan Inertia props yang ada.
- Gunakan Tailwind CSS utility dengan konsisten (pilih palet warna yang koheren, misal Slate / Zinc untuk netral, Indigo / Violet / Emerald untuk aksen).

## Review Focus
1. Apakah perombakan visual merusak fungsionalitas form yang ada? (Setiap form harus dites via browser/feature test).
2. Apakah tombol, modal, dan tabel konsisten di semua halaman admin?
3. Apakah tampilan mobile tetap rapi dengan tabel *horizontal scroll* atau kartu yang terstruktur?

---

### Task 1: Design Tokens & Base UI Component Polish

**Files:**
- Modify: `resources/js/app.js` or global CSS (`resources/css/app.css`)
- Modify: `resources/js/Components/ui/*` (Button, Input, Card, Modal)

- [ ] **Step 1: Tentukan palet warna konsisten dan tipografi dasar di CSS/Tailwind config**
- [ ] **Step 2: Pastikan komponen dasar (Button, Card, Input) memiliki style state (hover, focus, disabled) yang seragam dan rapi**
- [ ] **Step 3: Commit**
