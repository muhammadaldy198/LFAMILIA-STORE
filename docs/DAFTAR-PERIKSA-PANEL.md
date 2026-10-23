# DAFTAR PERIKSA — panel admin, sebelum dibangun baru

**Tanggal:** 22 Oktober 2026
**Sifat:** dokumen kerja. Bukan aturan. Dipakai untuk memutuskan nasib setiap route, controller, dan kolom sebelum panel baru ditulis.
**Cara pakai:** tiap baris punya usulan nasib. Koreksi yang salah, lalu dokumen ini jadi dasar kerja.

---

## 0. Keadaan sekarang (angka nyata, bukan perkiraan)

| Hal | Jumlah | Sumber |
|---|---|---|
| Route admin | **142** (termasuk 3 redirect) | `php artisan route:list --path=admin` |
| Controller admin | 25 | `app/Http/Controllers/Admin*` |
| Halaman Vue admin | 27 (23 layar + 4 auth) | `resources/js/Pages/Admin` |
| Komponen admin bersama | 8 | `resources/js/Components/Admin*` |
| Baris CSS admin | 365 | `resources/css/admin.css` |
| Izin (permission) | 14 | `AdminPermissionService` |

**Halaman yang benar-benar dirender (23):** Dashboard, Orders, OrderDetail, Fulfillment, Promotions, Catalog, Categories, Content, Presentation, Digiflazz, NicknameTools, Providers, Payments, Reports, Customers, CustomerDetail, Support, Access, Settings, Integrations, Health, Audit, Notifications.

**Halaman auth (4):** Login, ForgotPassword, ResetPassword, PasswordConfirm.

---

## 1. Route yang saya curigai — perlu keputusan

Ini yang **paling penting**. Semua route lain sudah jelas dipakai halaman.

### 1.1 `POST admin/payments/refund-reviews/{topupId}/resolve`

**Apa:** menyelesaikan "top up refund review" — memindahkan dana untuk top up yang perlu rekonsiliasi.
**Controller:** `AdminPaymentController@resolveTopupRefund`
**Middleware:** payments.manage + SuperAdmin + password confirm
**Kenapa dicurigai:** ini satu-satunya jalur di panel yang **memindahkan uang** tanpa keputusan manusia di luar sistem. PRD §19 melarang refund otomatis.
**Usulan:** **ganti**, bukan hapus. Jadi `POST admin/customers/{userId}/wallet` (penyesuaian saldo) sudah ada — cukup pakai itu. Route ini **dihapus**, fungsinya pindah ke penyesuaian saldo.
**Perlu Anda putuskan:** setuju dihapus?

### 1.2 `safe_to_failover` (kolom database)

**Apa:** penanda bahwa percobaan pengiriman yang gagal boleh dialihkan ke sumber lain.
**Kenapa dicurigai:** failover otomatis sudah **dimatikan permanen** di `FulfillmentService` ("Automatic failover is permanently disabled"). Kolomnya masih ada dan masih ditampilkan di daftar antrean dan detail pesanan.
**Usulan:** **hapus kolomnya** dan bersihkan tampilannya. Kalau tidak, panel baru akan menampilkan tombol "kirim ulang ke sumber lain" yang tidak boleh dipakai otomatis.
**Perlu Anda putuskan:** hapus, atau simpan untuk kirim ulang manual?

### 1.3 `POST admin/orders/{id}/resend-delivery`

**Apa:** mengirim ulang pengiriman ke penyedia.
**Kenapa dicurigai:** kalau dipakai saat status belum pasti, pelanggan bisa menerima produk dua kali.
**Usulan:** **pertahankan**, tapi beri syarat: hanya muncul kalau status sudah **gagal terkonfirmasi** atau `BLOCKED`, wajib alasan, dan masuk audit.
**Perlu Anda putuskan:** setuju dibatasi?

### 1.4 `POST admin/orders/{id}/cancel`

**Apa:** membatalkan pesanan.
**Keadaan:** sudah minta password, sudah SuperAdmin, sudah ada service sendiri (`AdminOrderCancellationService`).
**Yang kurang:** **alasan wajib** belum ada.
**Usulan:** pertahankan, tambah alasan wajib.
**Perlu Anda putuskan:** alasan wajib atau cukup centang "yakin"?

### 1.5 `POST admin/customers/{userId}/wallet`

**Apa:** penyesuaian saldo pelanggan (tambah/kurangi).
**Keadaan:** sudah SuperAdmin + password confirm.
**Yang kurang:** **alasan wajib** belum ada, dan namanya di layar masih perlu dipastikan "Penyesuaian saldo", bukan "Refund".
**Usulan:** pertahankan, tambah alasan wajib.
**Perlu Anda putuskan:** setuju?

### 1.6 `POST admin/customers/cleanup/run`

**Apa:** menghapus akun pelanggan kosong sekaligus.
**Kenapa dicurigai:** ini operasi hapus massal.
**Usulan:** pertahankan tapi **wajib pratinjau**: tampilkan dulu berapa akun yang akan terhapus dan siapa saja, baru boleh dijalankan.
**Perlu Anda putuskan:** setuju wajib pratinjau?

### 1.7 `GET admin/configuration/export`

**Apa:** mengekspor seluruh konfigurasi (termasuk pengaturan sensitif?).
**Kenapa dicurigai:** isinya bisa memuat hal yang tidak boleh keluar dari server.
**Usulan:** pertahankan, tapi pastikan **kredensial tidak ikut** dan ekspor tercatat di audit.
**Perlu Anda putuskan:** masih perlu fitur ini?

### 1.8 `POST admin/orders/manual`

**Apa:** mencatat pesanan manual (pesanan yang terjadi di luar alur checkout).
**Kenapa dicurigai:** ini bisa membuat pesanan tanpa pembayaran nyata.
**Usulan:** pertahankan, wajib: alasan, nama pelanggan, dan tanda "sudah dibayar di luar sistem" + bukti.
**Perlu Anda putuskan:** masih perlu?

### 1.9 `PUT admin/vouchers/popular/{productId}`

**Apa:** menandai produk populer. **Letaknya aneh** — route promo menangani produk.
**Usulan:** pindahkan ke bawah `admin/catalog` supaya jelas. Bukan hapus.
**Perlu Anda putuskan:** setuju dipindah?

### 1.10 DOKU

**Keadaan:** ada di `IntegrationRegistry`, kartu di halaman Integrasi, tapi tidak aktif.
**Usulan:** **pertahankan** (Anda bilang nanti mau dipasang), tapi tampil sebagai "belum dipakai" dan tidak bisa diaktifkan dari layar sampai ada keputusan tertulis.
**Perlu Anda putuskan:** setuju?

### 1.11 Route redirect (3)

- `admin` → `admin/panel`
- `admin/panel/login` → `admin/login`
- `panel/admin`, `panel/admin/login` → alamat lama

**Usulan:** `admin` dan `admin/panel/login` **pertahankan**. Alamat `panel/admin*` **hapus** kalau memang tidak dipakai lagi.
**Perlu Anda putuskan:** `panel/admin*` masih dipakai?

---

## 2. Halaman yang saya curigai terlalu padat

Bukan soal route, soal **satu layar menampung terlalu banyak**.

| Halaman | Baris Vue | Isi sekarang | Usulan |
|---|---|---|---|
| `Catalog.vue` | 818 | produk, nominal, sumber Digiflazz, harga, field input, catatan, gambar | **pecah**: daftar produk (1 layar) + editor produk (1 layar penuh) |
| `Payments.vue` | 627 | metode, gateway, routing, QRIS manual, top up, tampilan checkout, transaksi | **pecah** jadi 2: Pengaturan Pembayaran + Transaksi |
| `Content.vue` | 676 | banner, popup, berita, FAQ, ulasan, halaman hukum, aset | **pecah**: Konten (banner/popup/berita) + Halaman & Kebijakan |
| `NicknameTools.vue` | 574 | alat cek + daftar kode game | **pecah** kalau alat cek dipakai sehari-hari |
| `Integrations.vue` | 571 | 9 integrasi dalam satu layar | **pertahankan** sebagai daftar kartu, detail per integrasi jadi panel samping |
| `Support.vue` | 391 | daftar tiket + isi tiket + balasan cepat | **pertahankan**, tapi pisahkan daftar dan percakapan di HP |
| `Access.vue` | 399 | admin + izin + aktivitas | **pertahankan** |

**Perlu Anda putuskan:** mana yang mau dipecah. Saran saya: **Catalog**, **Payments**, **Content** — tiga itu yang paling padat.

---

## 3. Yang **tidak** saya usulkan dihapus

Semua ini dipakai dan jelas gunanya:

`AdminDashboardController`, `AdminOrdersController`, `AdminOrderDetailController`, `AdminFulfillmentController`, `AdminPromotionController`, `AdminCatalogController`, `AdminCatalogMediaController`, `AdminContentController`, `AdminPresentationController`, `AdminDigiflazzController`, `AdminNicknameController`, `AdminProviderController`, `AdminPaymentController` (kecuali §1.1), `AdminReportController`, `AdminCustomerController`, `AdminSupportController`, `AdminAccessController`, `AdminSettingsController`, `AdminIntegrationController`, `AdminHealthController`, `AdminAuditController`, `AdminNotificationController`, `AdminSearchController`, `AdminAuthController`, `AdminPasswordResetController`, `AdminPasswordConfirmController`.

Semua 27 halaman Vue tetap ada. Yang berubah bentuknya, bukan jumlahnya (kecuali tiga yang dipecah di §2).

---

## 4. Yang belum bisa saya putuskan sendiri

1. Apakah `panel/admin*` masih dipakai.
2. Apakah `admin/configuration/export` masih diperlukan.
3. Apakah `admin/orders/manual` masih dipakai.
4. Apakah `safe_to_failover` dihapus total atau disimpan untuk kirim ulang manual.

---

## 5. Sesudah dokumen ini disepakati

Baru masuk ke **bentuk panel**: struktur menu, tata letak tiap layar, kolom apa saja, field apa saja, tombol apa saja. Itu dokumen berikutnya, dan isinya diambil dari data yang benar-benar dikirim controller ke tiap halaman — bukan dari template.
