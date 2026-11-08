# Folder `.claude/` — perangkat kerja agen

Folder ini berisi perangkat kerja untuk agen AI yang mengerjakan repo ini: berkas peran di `agents/` dan skill di `skills/`. Ini **bukan kode toko** — tidak ada bagian aplikasi, tes, atau CI yang bergantung pada folder ini.

Aturan susunan (jangan diubah sembarangan):

- Skill tetap **rata** di `skills/<nama-skill>/`, satu folder per skill, masing-masing dengan `SKILL.md` di dalamnya. Alat agen menemukan skill persis di pola itu; jangan memasukkan skill ke subfolder kategori — pengelompokan hidup di peta ini, bukan di disk.
- Tabel pemetaan tugas → skill yang mengikat ada di `AGENTS.md` bagian 3. File ini hanya peta isi folder.

## Peran — `agents/`

Satu tugas dikerjakan satu peran utama; baca berkas perannya sebelum mulai.

| Berkas | Peran |
|---|---|
| `coder.md` | Menulis kode Laravel |
| `data.md` | Query dan rekonsiliasi data (bawaan: hanya baca) |
| `deployer.md` | CI/CD dan runbook deploy |
| `frontend.md` | Vue/Inertia/Tailwind dan desain tampilan |
| `payment.md` | Pembayaran Midtrans/DOKU |
| `planner.md` | Rencana dan desain sebelum koding |
| `reviewer.md` | Review, audit, dan keamanan |
| `tester.md` | Tes Pest dan tes browser |

## Skill — `skills/` (53)

### Khusus LFAMILIA (5)

| Skill | Gunanya |
|---|---|
| `lfamilia-admin-panel` | Mendesain ulang halaman panel admin LFAMILIA |
| `lfamilia-digiflazz-specialist` | Integrasi Digiflazz: sinkron katalog, seller/SKU, pemilihan supplier |
| `lfamilia-payment-guardian` | Penjaga pekerjaan pembayaran toko: webhook, idempotensi, rekonsiliasi |
| `lfamilia-product-spec` | Menulis ulang PRD dan spek panel LFAMILIA |
| `lfamilia-release-guardian` | Penjaga alur rilis: branch → PR → CI hijau → review → merge → deploy |

### Laravel & PHP (9)

| Skill | Gunanya |
|---|---|
| `laravel-best-practices` | Praktik terbaik menulis dan merefaktor kode Laravel |
| `laravel-security` | Keamanan Laravel: auth, otorisasi, CSRF, XSS, keamanan API |
| `laravel-verification` | Gelung verifikasi Laravel: lint, analisis statis, tes, pindai keamanan |
| `fortify-development` | Autentikasi Laravel Fortify: login, 2FA, passkey |
| `pest-testing` | Menulis dan memperbaiki tes Pest |
| `medialibrary-development` | Upload dan pengelolaan media dengan spatie/laravel-medialibrary |
| `integrate-midtrans-payments` | Integrasi gerbang pembayaran Midtrans sampai tuntas |
| `debug-using-debugbar` | Menyelidiki permintaan lambat dan query N+1 lewat Laravel Debugbar |
| `wayfinder-development` | Menghubungkan frontend ke route/controller Laravel lewat Wayfinder |

### Frontend & desain (12)

| Skill | Gunanya |
|---|---|
| `inertia-vue-development` | Aplikasi Inertia.js v2 + Vue: halaman, form, navigasi |
| `vue-best-practices` | Praktik terbaik Vue.js (Composition API, `<script setup>`) |
| `tailwindcss-development` | Menulis dan memperbaiki kelas Tailwind di template |
| `ui-to-vue` | Mengubah tangkapan layar/ekspor desain menjadi komponen Vue 3 |
| `frontend-design` | Arah visual yang khas saat membangun atau mengubah UI |
| `design-md` | Menulis dan memvalidasi berkas token desain DESIGN.md |
| `popular-web-designs` | 54 sistem desain nyata (Stripe, Linear, Vercel) sebagai HTML/CSS |
| `web-design-guidelines` | Meninjau kode UI terhadap pedoman antarmuka web |
| `ui-ux-pro-max` | Kecerdasan desain UI/UX untuk web, mobile, dan desktop |
| `accessibility` | Audit dan perbaikan aksesibilitas mengikuti WCAG 2.2 |
| `click-path-audit` | Menelusuri setiap tombol pengguna sampai perubahan state-nya |
| `phone-internationalization-guard` | Aturan normalisasi nomor telepon dan kode negara |

### Audit & keamanan (11)

| Skill | Gunanya |
|---|---|
| `audit-context-building` | Memahami codebase sebelum berburu bug di dalamnya |
| `differential-review` | Review keamanan atas selisih perubahan kode |
| `gha-security-review` | Review keamanan GitHub Actions |
| `security-review` | Review keamanan dengan pemodelan penyerang dan batas kepercayaan |
| `code-review` | Review perubahan sejak titik tetap, dua sumbu: standar dan spek |
| `find-bugs` | Mencari bug dan celah keamanan pada perubahan branch lokal |
| `sharp-edges` | Mengidentifikasi API dan konfigurasi yang rawan disalahgunakan |
| `skill-security-auditor` | Memindai skill agen sebelum dipasang |
| `supply-chain-risk-auditor` | Mengaudit risiko rantai pasok dependensi proyek |
| `scenario-review` | Review sebelum push dengan matriks skenario |
| `spec-to-code-compliance` | Memeriksa kode terhadap dokumen spesifikasinya |

### Proses & disiplin kerja (16)

| Skill | Gunanya |
|---|---|
| `grill-me` | Menginterogasi rencana/desain sampai tuntas, satu cabang keputusan sekali jalan |
| `writing-plans` | Menulis rencana sebelum menyentuh kode untuk tugas banyak langkah |
| `to-spec` | Mengubah percakapan menjadi spek dan menerbitkannya ke issue tracker |
| `technical-documentation` | Membangun dan meninjau dokumentasi teknis dan berkas instruksi agen |
| `code-scaffolding` | Menghasilkan boilerplate mengikuti konvensi codebase yang ada |
| `code-tour` | Membuat berkas CodeTour: penelusuran kode bertahap per persona |
| `cicd-deployment` | Mengelola pipeline CI/CD dan alur rilis |
| `data-fetch-analysis` | Mengambil dan menganalisis data dari database, API, dan log |
| `systematic-debugging` | Debugging akar masalah dalam 4 fase |
| `verification-before-completion` | Wajib verifikasi berjalan sebelum mengklaim pekerjaan selesai |
| `webapp-testing` | Menguji aplikasi web lokal dengan Playwright |
| `playwright` | Otomasi dan pengujian browser lewat Playwright |
| `dogfood` | QA eksploratif aplikasi web: mencari bug dengan bukti |
| `strict-api` | Larangan mengarang API: hanya memakai yang terbukti ada |
| `zero-hallucination-coder` | Gelung kerja disiplin Discuss → Map → Decompose → Execute → Verify |
| `karpathy-coder` | 4 prinsip koding Karpathy: asumsi terbuka, sederhana, bedah presisi, tujuan terverifikasi |

## Riwayat kerapian

- 2026-11-08: peta ini dibuat. Duplikat `grilling` digabung ke `grill-me` — keduanya skill interogasi rencana yang sama gunanya; `grill-me` dipertahankan karena lebih lengkap (punya references dan scripts) dan dirujuk berkas peran `planner.md`. Kelompok desain (5 skill) dan kelompok review (6 skill) memang mirip-mirip namanya, tapi isinya berbeda — rinciannya ada di tabel di atas.
