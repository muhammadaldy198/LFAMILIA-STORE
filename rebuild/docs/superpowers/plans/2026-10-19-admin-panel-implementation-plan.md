# Rencana Implementasi Teknis — Desain Ulang Total Panel Admin LFAMILIA Store

> **Status arsip:** Rencana awal; implementasi terkait telah dimulai dan commit lokal `9c9ce4e6` dibuat pada branch `feat/admin-total-redesign`. Checklist di bawah tidak diperbarui sebagai laporan completion. Implementasi mengubah backend untuk katalog/harga selain UI. Tidak ada push, PR, merge, atau deployment yang diotorisasi oleh dokumen ini.

> **For agentic workers:** REQUIRED SUB-SKILL: Use `software-development/executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun ulang seluruh UI panel Admin dari awal — struktur halaman, shell, navigasi, dan semua halaman — tanpa mengubah backend, endpoint, permission, atau perilaku bisnis yang ada.

**Architecture:** Setiap halaman Admin dibangun ulang sebagai file Vue baru yang menggantikan halaman lama. Shell (`AdminShell.vue`) dibangun ulang pertama kali sebagai fondasi bersama. Backend props/routes tidak berubah; hanya template Vue yang diganti. Komponen bersama baru disimpan di `resources/js/Components/Admin/`.

**Tech Stack:** Vue 3 Composition API, Inertia.js v2, Tailwind CSS v4, Reka UI, token CSS dari `resources/css/tokens.css`

**Spec:** `docs/superpowers/plans/2026-10-19-admin-panel-total-redesign.md`

## Global Constraints

- Tema gelap; aksen menggunakan token yang sudah ada: `--lf-color-primary` (biru), `--lf-color-secondary` (ungu), `--lf-color-highlight` (lime).
- Hindari kartu/kotak berlebihan. Gunakan tabel, baris, pembatas, judul bagian. Kartu hanya untuk: ringkasan angka dashboard, koneksi/saldo Digiflazz.
- Tabel Pesanan, Fulfillment, SKU Digiflazz dapat digeser horizontal di ponsel (`overflow-x-auto`).
- Semua route, nama permission, dan props dari backend tidak berubah.
- Tidak ada refund, tidak ada endpoint baru, tidak ada perubahan data/model.
- Setiap halaman wajib lulus `npm run check` dan `npm run lint` sebelum commit.
- Pint (`~/php-cli/php-pint`) hanya dijalankan jika ada perubahan PHP; tidak ada perubahan PHP pada plan ini.
- Branch kerja: `feat/admin-total-redesign` (buat dari `feat/shared-game-customer-template`).
- Commit per task; pesan commit: `feat(admin): <nama task>`.

## Review Focus

- Izin berbasis permission: pastikan setiap halaman hanya merender data/aksi sesuai izin yang sama seperti sebelumnya.
- Tabel horizontal mobile: cek overflow tidak menyebabkan layout rusak di lebar < 375px.
- Kartu dashboard: pastikan hanya muncul saat data izin tersedia, tidak pecah saat kosong.
- Form dengan tindakan berisiko (batalkan pesanan, hapus, sesuaikan saldo): pastikan konfirmasi tetap ada.
- Navigasi aktif: pastikan `active()` di shell masih bekerja untuk semua route setelah shell dibangun ulang.

---

## Task 1: Buat branch dan setup

**Files:**
- Tidak ada file diubah; hanya operasi git.

- [ ] **Step 1: Buat branch baru dari branch saat ini**
```bash
cd /home/hatch/workspace/LFAMILIA-STORE/rebuild
git checkout -b feat/admin-total-redesign
```
- [ ] **Step 2: Verifikasi branch aktif**
```bash
git branch --show-current
```
Expected: `feat/admin-total-redesign`

---

## Task 2: Token warna dan CSS admin

**Files:**
- Modify: `resources/css/admin.css` — tambah variabel token khusus Admin dark jika belum ada; tidak hapus yang lama kecuali duplikat.

**Interfaces:**
- Produces: variabel CSS `--admin-*` yang dipakai semua komponen Admin baru sebagai alias dari token customer.

- [ ] **Step 1: Baca token yang tersedia**

Baca `resources/css/tokens.css` dan `resources/css/admin.css` untuk inventaris variabel yang ada.

- [ ] **Step 2: Tambah alias token Admin (jika belum ada)**

Tambahkan di `resources/css/admin.css` dalam `:root` atau `.lf-admin`:
```css
.lf-admin {
  --admin-bg: var(--lf-color-background);
  --admin-surface: var(--lf-color-surface);
  --admin-border: var(--lf-color-border);
  --admin-text: var(--lf-color-text);
  --admin-muted: var(--lf-color-muted);
  --admin-accent: var(--lf-color-primary);
  --admin-accent2: var(--lf-color-secondary);
  --admin-lime: var(--lf-color-highlight);
}
```

- [ ] **Step 3: Build dan cek tidak ada error**
```bash
npm run check && npm run lint
```
- [ ] **Step 4: Commit**
```bash
git add resources/css/admin.css
git commit -m "feat(admin): add admin dark token aliases"
```

---

## Task 3: Shell Admin (AdminShell.vue)

**Files:**
- Modify: `resources/js/Components/AdminShell.vue` — bangun ulang template; logika JS (search, shortcut, active, menu) dipertahankan.
- Create: `resources/js/Components/Admin/Sidebar.vue` — sidebar dengan kelompok menu.
- Create: `resources/js/Components/Admin/TopBar.vue` — topbar desktop: search, notifikasi, akun.

**Interfaces:**
- Produces: `<AdminShell>` yang dapat dipakai semua halaman Admin (sama seperti sekarang).
- Sidebar menerima `menu` array dari `page.props.adminPanel.menu` (tidak berubah dari backend).

Kelompok sidebar (label dan icon sesuai implementasi; urutan ini disepakati):
1. Dashboard
2. Transaksi: Pesanan, Fulfillment, Promo
3. Katalog: Produk, Kategori
4. Konten: Tampilan Toko, Bantuan & Kebijakan, Berita & Ulasan
5. Integrasi: Digiflazz, Provider, Validasi Akun
6. Keuangan: Pembayaran, Laporan
7. Pelanggan: Daftar Pelanggan, Membership (pengaturan tier)
8. Layanan Pelanggan
9. Sistem: Integrasi (layanan), Kondisi Sistem, Audit Log
10. Pengaturan: Toko & Kontak, Admin & Akses

Pengelompokan final boleh disesuaikan implementor sesuai menu yang tersedia dari props, tanpa mengubah backend.

- [ ] **Step 1: Buat `Admin/Sidebar.vue` dengan kelompok menu**

Sidebar gelap, lebar tetap di desktop (`w-56` atau `w-60`), collapsible drawer di mobile. Gunakan `menu` props dari `page.props.adminPanel.menu`. Tandai item aktif dengan `active(href)` yang sudah ada.

- [ ] **Step 2: Buat `Admin/TopBar.vue`**

Topbar: search (Ctrl+K), badge notifikasi, dropdown akun. Pindahkan logika search dan dropdown dari `AdminShell.vue` ke komponen ini; pancarkan event ke shell bila perlu.

- [ ] **Step 3: Bangun ulang template `AdminShell.vue`**

Layout: sidebar kiri tetap + area konten kanan + topbar di atas konten. Mobile: sidebar menjadi drawer (Sheet Reka UI, sudah dipakai saat ini). Pertahankan semua logika JS; hanya template yang diganti.

- [ ] **Step 4: Cek di browser (jika server dev bisa diakses)**

Verifikasi navigasi, active state, mobile drawer, dan search tidak rusak.

- [ ] **Step 5: Lint dan check**
```bash
npm run check && npm run lint
```
- [ ] **Step 6: Commit**
```bash
git add resources/js/Components/AdminShell.vue resources/js/Components/Admin/
git commit -m "feat(admin): rebuild AdminShell with grouped sidebar"
```

---

## Task 4: Login, Lupa Kata Sandi, Reset Kata Sandi

**Files:**
- Modify: `resources/js/Pages/Admin/Login.vue`
- Modify: `resources/js/Pages/Admin/ForgotPassword.vue`
- Modify: `resources/js/Pages/Admin/ResetPassword.vue`

**Interfaces:**
- Konsumsi: form action routes yang sudah ada (`admin.login.store`, `admin.password.email`, `admin.password.update`).
- Tidak ada perubahan props atau backend.

- [ ] **Step 1: Bangun ulang `Login.vue`**

Tema gelap, logo toko, field email + password, tombol masuk, link lupa kata sandi. Validasi error tetap dari `$page.props.errors`. Tidak ada field/action baru.

- [ ] **Step 2: Bangun ulang `ForgotPassword.vue` dan `ResetPassword.vue`**

Desain konsisten dengan Login. Field dan action mengikuti yang ada.

- [ ] **Step 3: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Login.vue resources/js/Pages/Admin/ForgotPassword.vue resources/js/Pages/Admin/ResetPassword.vue
git commit -m "feat(admin): rebuild login and password reset pages"
```

---

## Task 5: Dashboard

**Files:**
- Modify: `resources/js/Pages/Admin/Dashboard.vue`

**Interfaces:**
- Konsumsi: props yang sudah ada dari `AdminDashboardController` (metrics, chart data, recent orders, activity, integration status, notifications).

Urutan konten:
1. Kartu ringkasan angka (permission-aware) — 2 kolom di mobile, 4 di desktop.
2. Grafik revenue/order-count, selector 7/30/90 hari, default 7.
3. Tabel ringkas max 5 pesanan terbaru + link ke Pesanan.
4. Aktivitas, integrasi, notifikasi belum dibaca — baris/teks ringkas, bukan kartu.

Produk terlaris tidak ditampilkan di dashboard.

- [ ] **Step 1: Bangun ulang template Dashboard.vue** mengikuti urutan dan aturan di atas.
- [ ] **Step 2: Pastikan kartu metric tidak pecah saat data kosong/null.**
- [ ] **Step 3: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Dashboard.vue
git commit -m "feat(admin): rebuild Dashboard page"
```

---

## Task 6: Pesanan dan Detail Pesanan

**Files:**
- Modify: `resources/js/Pages/Admin/Orders.vue`
- Modify: `resources/js/Pages/Admin/OrderDetail.vue`

**Interfaces:**
- Konsumsi: props dari `AdminOrdersController` dan `AdminOrderDetailController`.

Pesanan:
- Urutkan bermasalah/tertunda dulu, lalu terbaru.
- Ringkasan jumlah: Menunggu pembayaran, Perlu diproses, Perlu perhatian.
- Pencarian invoice/pelanggan; filter status, tanggal, metode, provider.
- Kolom: invoice, pelanggan, produk/target, total, status bayar, status proses.
- Aksi manual order dan download data tetap ada.
- Mobile: tabel geser horizontal (`overflow-x-auto`, `min-w-[700px]`).

Detail pesanan:
- Status saat ini dan langkah berikutnya di paling atas.
- Pembayaran dan riwayat di bawah.
- Tindakan berisiko (batalkan, dll.) dikelompokkan dengan peringatan konfirmasi.

- [ ] **Step 1: Bangun ulang `Orders.vue`.**
- [ ] **Step 2: Bangun ulang `OrderDetail.vue`.**
- [ ] **Step 3: Pastikan konfirmasi batalkan pesanan tetap ada.**
- [ ] **Step 4: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Orders.vue resources/js/Pages/Admin/OrderDetail.vue
git commit -m "feat(admin): rebuild Orders and OrderDetail pages"
```

---

## Task 7: Produk dan Kategori

**Files:**
- Modify: `resources/js/Pages/Admin/Catalog.vue` — pecah menjadi tampilan daftar produk dan ruang kerja satu produk dalam satu file (via state aktif), atau buat komponen terpisah di `Admin/`.
- Create (opsional): `resources/js/Components/Admin/ProductEditor.vue` — editor satu produk/denominasi.

**Interfaces:**
- Konsumsi: props dari `AdminCatalogController`.

Daftar produk: nama, kategori, jenis penanganan, jumlah denominasi, status; search/filter di atas.
Ruang kerja: info dasar → denominasi/harga (jual, modal, sumber berdampingan) → field input pelanggan → tampilan storefront (preview gambar/banner/pemberitahuan) → pengaturan markup lanjutan (tersembunyi default).
Denominasi: tiap denominasi punya editor gambar sendiri.
Stok voucher: hanya tampil pada produk voucher manual.
Tidak ada backup provider source.
Kategori: daftar dengan nama, ikon, urutan, jumlah produk; edit inline; gambar dan aktif/nonaktif tersedia.

- [ ] **Step 1: Bangun ulang daftar produk di `Catalog.vue`.**
- [ ] **Step 2: Bangun ruang kerja produk (editor denominasi + gambar per denominasi).**
- [ ] **Step 3: Bangun daftar dan editor kategori.**
- [ ] **Step 4: Pastikan voucher stock hanya tampil pada produk yang relevan.**
- [ ] **Step 5: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Catalog.vue resources/js/Components/Admin/
git commit -m "feat(admin): rebuild Catalog (products + categories)"
```

---

## Task 8: Manual / Fulfillment

**Files:**
- Modify: `resources/js/Pages/Admin/Fulfillment.vue`

**Interfaces:**
- Konsumsi: props dari `AdminFulfillmentController`.

Antrean utama: item yang perlu tindakan staf dahulu. Item dipantau tetap bisa ditemukan via filter. Mobile: tabel geser horizontal. Detail dan aksi tindakan tampil dalam konteks yang sama; aksi menyesuaikan status.

- [ ] **Step 1: Bangun ulang `Fulfillment.vue`.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Fulfillment.vue
git commit -m "feat(admin): rebuild Fulfillment page"
```

---

## Task 9: Banner & Konten (tiga area)

**Files:**
- Modify: `resources/js/Pages/Admin/Content.vue` — pecah secara visual menjadi tiga area (Tampilan Toko, Bantuan & Kebijakan, Berita & Ulasan) tanpa mengubah routes.

**Interfaces:**
- Konsumsi: props dari `AdminContentController` dan `AdminPresentationController`.

Tampilan Toko: daftar banner ringkas (baris); pilih satu → editor + pratinjau desktop/HP. Pop-up punya editor + pratinjau sendiri. Aset logo/favicon sebagai daftar ringkas.
Bantuan & Kebijakan: daftar FAQ bisa dibuka per item untuk edit; tiap kebijakan punya editor + pratinjau.
Berita & Ulasan: berita sebagai daftar + editor terpisah; ulasan fokus moderasi (approve/sembunyikan).

- [ ] **Step 1: Bangun ulang `Content.vue` dengan tiga area navigasi.**
- [ ] **Step 2: Bangun ulang editor banner dengan pratinjau desktop/HP.**
- [ ] **Step 3: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Content.vue
git commit -m "feat(admin): rebuild Content page (3 areas)"
```

---

## Task 10: Digiflazz

**Files:**
- Modify: `resources/js/Pages/Admin/Digiflazz.vue`

**Interfaces:**
- Konsumsi: props dari `AdminDigiflazzController`.

Koneksi dan saldo: boleh pakai kartu ringkas.
Daftar SKU: tabel geser horizontal di mobile; bukan kartu. Kolom: produk/denominasi, harga supplier, ketersediaan/stok, kesehatan. Default tampil SKU terhubung produk; filter untuk semua SKU. Aksi sinkronisasi di atas tabel. Fitur "Harga Max" tidak ditampilkan.

- [ ] **Step 1: Bangun ulang `Digiflazz.vue`.**
- [ ] **Step 2: Pastikan tombol/form Harga Max tidak ada di template baru.**
- [ ] **Step 3: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Digiflazz.vue
git commit -m "feat(admin): rebuild Digiflazz page"
```

---

## Task 11: Provider

**Files:**
- Modify: `resources/js/Pages/Admin/Providers.vue`

**Interfaces:**
- Konsumsi: props dari `AdminProviderController`.

Satu baris per provider: status koneksi, jenis layanan, jumlah denominasi tertaut, status aktif, aksi. Bukan kartu.

- [ ] **Step 1: Bangun ulang `Providers.vue`.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Providers.vue
git commit -m "feat(admin): rebuild Providers page"
```

---

## Task 12: Pembayaran

**Files:**
- Modify: `resources/js/Pages/Admin/Payments.vue`

**Interfaces:**
- Konsumsi: props dari `AdminPaymentController`.

Dua area: **Pengaturan Pembayaran** dan **Transaksi Pembayaran**.
Pengaturan: satu halaman, bagian berurutan: metode (incl. QRIS manual) → gateway dan biaya → top-up → tampilan checkout.
QRIS manual dibedakan dari gateway QRIS.
Transaksi: riwayat terpisah dari pengaturan.

- [ ] **Step 1: Bangun ulang `Payments.vue` dengan dua area.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Payments.vue
git commit -m "feat(admin): rebuild Payments page"
```

---

## Task 13: Pelanggan dan Detail Pelanggan

**Files:**
- Modify: `resources/js/Pages/Admin/Customers.vue`
- Modify: `resources/js/Pages/Admin/CustomerDetail.vue`

**Interfaces:**
- Konsumsi: props dari `AdminCustomerController`.

Daftar: pencarian, info pelanggan utama. Pengaturan membership/saldo di detail.
Detail: satu halaman berurutan — profil → pesanan → saldo/top-up → tiket → akun game tersimpan.
Aturan tier membership umum: tampilkan di area Pelanggan (bisa berupa bagian/tab di halaman `Customers.vue`), menggunakan route `admin.settings.membership.update` yang sudah ada.
Izin Super Admin untuk wallet/membership tetap dipakai.

- [ ] **Step 1: Bangun ulang `Customers.vue`.**
- [ ] **Step 2: Bangun ulang `CustomerDetail.vue`.**
- [ ] **Step 3: Pastikan aksi sesuaikan saldo tetap ada konfirmasi.**
- [ ] **Step 4: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Customers.vue resources/js/Pages/Admin/CustomerDetail.vue
git commit -m "feat(admin): rebuild Customers and CustomerDetail pages"
```

---

## Task 14: Layanan Pelanggan

**Files:**
- Modify: `resources/js/Pages/Admin/Support.vue`

**Interfaces:**
- Konsumsi: props dari `AdminSupportController`.

Daftar tiket masuk; membuka tiket menampilkan detail dan form balas. Tidak digambarkan sebagai chat langsung. Quick replies tetap ada sesuai backend.

- [ ] **Step 1: Bangun ulang `Support.vue`.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Support.vue
git commit -m "feat(admin): rebuild Support page"
```

---

## Task 15: Promo

**Files:**
- Modify: `resources/js/Pages/Admin/Promotions.vue`

**Interfaces:**
- Konsumsi: props dari `AdminPromotionController`.

Halaman khusus voucher: buat dan pantau voucher. Pengaturan produk populer dipindah ke Produk (sudah dikerjakan di Task 7, route `admin.vouchers.popular.update` tetap dipakai dari konteks Produk).

- [ ] **Step 1: Bangun ulang `Promotions.vue` khusus voucher.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Promotions.vue
git commit -m "feat(admin): rebuild Promotions page (vouchers only)"
```

---

## Task 16: Laporan

**Files:**
- Modify: `resources/js/Pages/Admin/Reports.vue`

**Interfaces:**
- Konsumsi: props dari `AdminReportController`.

Tiga bagian: Penjualan, Produk, Operasional. Rentang tanggal dan unduh data per bagian. Nilai keuangan hanya untuk Super Admin sesuai permission.

- [ ] **Step 1: Bangun ulang `Reports.vue`.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Reports.vue
git commit -m "feat(admin): rebuild Reports page"
```

---

## Task 17: Admin & Akses

**Files:**
- Modify: `resources/js/Pages/Admin/Access.vue`

**Interfaces:**
- Konsumsi: props dari `AdminAccessController`.

Daftar akun Admin; pilih akun → editor profil dan izin terpisah. Route store/update/destroy tetap dipakai.

- [ ] **Step 1: Bangun ulang `Access.vue`.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Access.vue
git commit -m "feat(admin): rebuild Access page"
```

---

## Task 18: Pengaturan

**Files:**
- Modify: `resources/js/Pages/Admin/Settings.vue`

**Interfaces:**
- Konsumsi: props dari `AdminSettingsController`.

Dua bagian jelas: Toko & Kontak dan Ekspor Pengaturan. Aturan tier membership sudah dipindah ke Customers (Task 13); halaman ini tidak lagi mengelola tier.

- [ ] **Step 1: Bangun ulang `Settings.vue`.**
- [ ] **Step 2: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Settings.vue
git commit -m "feat(admin): rebuild Settings page"
```

---

## Task 19: Integrasi, Kondisi Sistem, Audit Log

**Files:**
- Modify: `resources/js/Pages/Admin/Integrations.vue`
- Modify: `resources/js/Pages/Admin/Health.vue`
- Modify: `resources/js/Pages/Admin/Audit.vue`

**Interfaces:**
- Konsumsi: props dari masing-masing controller.

Integrasi: daftar layanan; pilih → editor koneksi/env/tes/callback. Rahasia tidak ditampilkan.
Health: bermasalah di atas, daftar status lengkap di bawah. Tidak memicu transaksi/tes.
Audit: filter pelaku/aksi/target/tanggal; detail sebelum/sesudah per baris; data sensitif disanitasi.

- [ ] **Step 1: Bangun ulang `Integrations.vue`.**
- [ ] **Step 2: Bangun ulang `Health.vue`.**
- [ ] **Step 3: Bangun ulang `Audit.vue`.**
- [ ] **Step 4: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Integrations.vue resources/js/Pages/Admin/Health.vue resources/js/Pages/Admin/Audit.vue
git commit -m "feat(admin): rebuild Integrations, Health, Audit pages"
```

---

## Task 20: Notifikasi dan Validasi Akun

**Files:**
- Modify: `resources/js/Pages/Admin/Notifications.vue`
- Modify: `resources/js/Pages/Admin/NicknameTools.vue`

**Interfaces:**
- Konsumsi: props dari `AdminNotificationController` dan `AdminNicknameController`.

Notifikasi: daftar belum/sudah dibaca; pilih → buka halaman terkait.
Validasi Akun: alat cek nickname/region/PLN sebagai fungsi utama; hasil dekat formulir. Daftar kode game di bagian terpisah.

- [ ] **Step 1: Bangun ulang `Notifications.vue`.**
- [ ] **Step 2: Bangun ulang `NicknameTools.vue`.**
- [ ] **Step 3: Lint, check, commit**
```bash
npm run check && npm run lint
git add resources/js/Pages/Admin/Notifications.vue resources/js/Pages/Admin/NicknameTools.vue
git commit -m "feat(admin): rebuild Notifications and NicknameTools pages"
```

---

## Task 21: Build final dan review

**Files:**
- Tidak ada file baru; hanya verifikasi.

- [ ] **Step 1: Full build production**
```bash
npm run build
```
Expected: tanpa error.

- [ ] **Step 2: Lint dan type-check**
```bash
npm run check && npm run lint
```
Expected: 0 error, 0 warning.

- [ ] **Step 3: Review cross-page**

Cek secara manual atau via code-review:
- Semua halaman memakai `AdminShell` baru.
- Tidak ada kartu berlebihan di luar yang disepakati.
- Tabel mobile horizontal sudah terpasang di Pesanan, Fulfillment, Digiflazz.
- Tindakan berisiko punya konfirmasi.
- Permission check tidak berubah dari sebelumnya.

- [ ] **Step 4: Commit final jika ada perbaikan kecil**
```bash
git add -A
git commit -m "chore(admin): final build cleanup and review fixes"
```

- [ ] **Step 5: Push dan buat PR**

Push hanya atas perintah eksplisit pengguna.
```bash
git push -u origin feat/admin-total-redesign
```

---

## Catatan implementasi

- Perbaikan alur guest order access code (kode akses pesanan, error validasi form tiket) dikerjakan **setelah** seluruh Admin siap, sebagai PR terpisah.
- Halaman `Presentation.vue` (tema/presentasi toko) belum dibahas secara eksplisit dalam sesi desain; implementor boleh menerapkan visual yang konsisten tanpa mengubah props/routes-nya.
- Tidak ada subagent; implementasi dilakukan native oleh model yang sama sesuai perintah pengguna.
