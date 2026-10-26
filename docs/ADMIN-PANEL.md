# ADMIN-PANEL.md — Panel admin LFAMILIA STORE

**Versi:** 2.0
**Tanggal:** 23 Oktober 2026
**Status:** **sudah dibangun.** 21 menu + 2 halaman detail berjalan di branch `admin-panel-redesign`, belum digabung ke `main`, belum di-deploy.
**Sumber isi:** `rebuild/routes/web.php`, controller `Admin*`, props Inertia, dan halaman Vue yang benar-benar dipakai. Template `docs/referensi/template-panel-admin-lfamilia.html` hanya menyumbang rasa visual — daftar halaman, kolom, dan tombol di dokumen ini berasal dari kode.
**Aturan yang menang:** `docs/PRD.md`. Kalau dokumen ini bertentangan dengan PRD, PRD menang.

---

## 1. Prinsip

1. **Panel adalah alat kerja, bukan pajangan.** Cepat, jelas, tidak salah klik.
2. **Terang.** Latar abu-putih, sidebar hitam, satu aksen hijau. Tema gelap tidak dipakai.
3. **Padat tapi kebaca.** Isi 13px, tombol 12px, jarak rapat tapi tidak berdempet.
4. **Jujur.** Belum ada data → tulis apa adanya. Tidak ada angka contoh, tidak ada tombol palsu.
5. **Satu bahasa.** Semua tulisan Indonesia.
6. **Aman secara bawaan.** Aksi yang mengubah uang atau data penting minta konfirmasi; izin diperiksa server.

---

## 2. Aturan tampilan

### 2.1 Warna (token nyata di `resources/css/admin.css`)

| Peran | Nilai |
|---|---|
| Latar halaman | `--a-bg` `#f4f5f3` |
| Panel / kartu | `--a-panel` `#ffffff` |
| Teks utama | `--a-ink` `#101410` |
| Teks kedua | `--a-ink-2` `#3d443c` |
| Teks samar | `--a-muted` `#6b736a` |
| Garis | `--a-line` `#e2e5df`, garis tipis `--a-line-2` `#eef0eb` |
| Sidebar | `--a-side` `#101410`, hover `--a-side-2` `#1a1f19` |
| Teks sidebar | `--a-side-ink` `#e8ebe6`, samar `--a-side-muted` `#8f9a8c` |
| Aksen | `--a-accent` `#65a30d`, terang `--a-accent-2` `#a3e635`, lembut `--a-accent-soft` `#f3f9e6` |
| Gagal | `--a-danger` `#b42318`, latar `#fef3f2` |
| Perlu diperiksa | `--a-warn` `#b54708`, latar `#fffaeb` |
| Berhasil | `--a-ok` `#067647`, latar `#ecfdf3` |

Satu aksen saja. Hijau hanya untuk tindakan utama, menu aktif, dan status berhasil.

### 2.2 Bentuk & ukuran

- Sudut: **8px** (`--a-radius`), kontrol tinggi **34px** (`--a-control`).
- Tanpa bayangan; pemisah memakai garis dan latar.
- Huruf: 13px untuk isi panel, 12px untuk tombol; label kecil KAPITAL dengan jarak huruf.
- Uang rata kanan, pemisah ribuan titik (`Rp 4.812.500`).
- Kontrol (tombol/input) tinggi 34px; di HP semua tombol tetap bisa diklik dan halaman tidak melebar.

### 2.3 Tabel

- Tabel lebar **digeser mendatar** di dalam kartunya (`min-width: 760px`), halaman tidak ikut melebar.
- Nomor invoice satu baris + tombol salin.
- Kosong → satu baris keterangan di tengah.

### 2.4 Grafik

- Grafik penjualan **selalu punya angka**; sumbu dan rentang tanggal ditulis jelas.
- Omzet hanya dari pesanan lunas, waktu WIB.

### 2.5 Pop-up

Semua penyuntingan (tambah/ubah) memakai **pop-up tengah** (`AdminModal`). Pengecualian: detail tiket di Layanan Pelanggan tampil sebagai panel di halaman, bukan pop-up.

---

## 3. Struktur & navigasi

Sidebar 216px, gelap, berkelompok; di HP jadi laci. Urutan, label, kelompok, dan izin menu berasal dari satu sumber: `AdminPermissionService::menu()`; `AdminLayout.vue` hanya menampilkannya.

| Grup | Menu | Alamat | Izin |
|---|---|---|---|
| — | Dashboard | `/admin/panel` | `dashboard.view` |
| Transaksi | Pesanan | `/admin/orders` | `orders.view` |
| Transaksi | Pengiriman | `/admin/fulfillment` | `fulfillment.manage` |
| Transaksi | Promo | `/admin/vouchers` | `vouchers.manage` |
| Katalog | Produk | `/admin/catalog` | `catalog.manage` |
| Katalog | Kategori | `/admin/catalog/categories` | `catalog.manage` |
| Konten | Banner & Konten | `/admin/content` | `content.manage` |
| Konten | Tampilan Toko | `/admin/content/presentation` | `content.manage` |
| Integrasi | Digiflazz | `/admin/digiflazz` | `providers.manage` |
| Integrasi | Validasi Akun | `/admin/nickname-tools` | Super Admin |
| Integrasi | Provider | `/admin/providers` | `providers.manage` |
| Keuangan | Pembayaran | `/admin/payments` | `payments.manage` |
| Keuangan | Laporan | `/admin/reports` | `reports.view` |
| Pelanggan | Pelanggan | `/admin/customers` | `customers.view` |
| Pelanggan | Layanan Pelanggan | `/admin/support` | `support.manage` |
| Sistem | Notifikasi | `/admin/notifications` | `notifications.view` |
| Sistem | Integrasi | `/admin/integrations` | Super Admin |
| Sistem | Kesehatan Sistem | `/admin/health` | Super Admin |
| Sistem | Catatan Audit | `/admin/audit` | Super Admin |
| Pengaturan | Admin & Akses | `/admin/access` | Super Admin |
| Pengaturan | Pengaturan | `/admin/settings` | `settings.manage` |

Aturan:

- **Server tetap penentu.** Sidebar hanya menampilkan menu yang dikirim backend; menyembunyikan menu bukan pengaman.
- Label yang dipakai apa adanya: **Pengiriman**, **Promo**, **Layanan Pelanggan** (grup Pelanggan). Istilah Inggris lama sudah diganti: `System Health` → **Kesehatan Sistem**, `Audit Log` → **Catatan Audit**.
- Pencarian global di kepala panel (`/admin/search`) mencari pesanan, pelanggan, dan produk.

---

## 4. Halaman

Format tiap halaman: **untuk apa · isi · aksi · catatan**. Nama berkas Vue ada di `rebuild/resources/js/Pages/Admin/`.

### 4.1 Dashboard — `/admin/panel`
`AdminDashboardController` · izin `dashboard.view` · `Dashboard.vue`

**Untuk apa.** Melihat keadaan toko hari ini dalam satu layar.
**Isi.** Pemilih rentang; kotak angka (pesanan, omzet, laba bila berizin, pesanan perlu perhatian); grafik pesanan & omzet berangka; pesanan terbaru; aktivitas terbaru; status integrasi; notifikasi terbaru; saldo Digiflazz dan waktu sinkron terakhir.
**Aksi.** Muat ulang, tandai notifikasi dibaca, buka pesanan/integrasi.
**Catatan.** Omzet, laba, dan saldo penyedia hanya dikirim bila izinnya ada (`canViewFinance`, `canSeeBalance`).

### 4.2 Pesanan — `/admin/orders`
`AdminOrdersController` · `orders.view` · `Orders.vue`

**Untuk apa.** Mencari pesanan dan menindak yang macet.
**Isi.** Kotak angka; filter (status, metode bayar, penyedia, tanggal, pencarian); tabel pesanan dengan nomor, pelanggan, produk, nominal, status, waktu; aktivitas terbaru.
**Aksi.** Catat pesanan manual (`POST /admin/orders/manual`), terapkan/hapus filter, buka detail, salin nomor, unduh CSV (`GET /admin/orders/export`).
**Catatan.** Urutan mendahulukan pesanan yang perlu perhatian, lalu yang menunggu.

### 4.3 Detail Pesanan — `/admin/orders/{id}`
`AdminOrderDetailController` · `orders.view` · `OrderDetail.vue`

**Untuk apa.** Menyelesaikan satu pesanan.
**Isi.** Status saat ini; ringkasan pesanan; pelanggan & tujuan; hasil pengiriman (percobaan otomatis/manual); pembayaran; riwayat pesanan; panel proses.
**Aksi.** Salin nomor, cek ulang pembayaran (bila `can_check`), kirim ulang pengiriman, batalkan pesanan, selesaikan/gagalkan percobaan manual.
**Catatan.** Pembatalan memakai pop-up + konfirmasi kata sandi server. Tombol muncul menurut props (`canCancel`, `canResendDelivery`, `canCheckFulfillment`), bukan disembunyikan di klien saja.

### 4.4 Pengiriman — `/admin/fulfillment`
`AdminFulfillmentController` · `fulfillment.manage` · `Fulfillment.vue`

**Untuk apa.** Menangani pesanan yang tidak selesai otomatis.
**Isi.** Kotak angka; filter status; tabel percobaan pengiriman (pesanan, produk, tujuan, percobaan ke-, status, waktu); waktu pembaruan.
**Aksi.** Tangani (pop-up): ulangi, rekonsiliasi, nyatakan selesai, nyatakan gagal — `POST /admin/fulfillment/{attemptId}/{retry|reconcile|complete|fail}`.
**Catatan.** Gagal terkonfirmasi menaikkan pesanan ke `BLOCKED`; tidak ada pengiriman ulang tanpa bukti.

### 4.5 Promo — `/admin/vouchers`
`AdminPromotionController` · `vouchers.manage` · `Promotions.vue`

**Untuk apa.** Mengatur voucher dan diskon.
**Isi.** Ringkasan voucher; filter (kode, jenis diskon, status); tabel voucher (kode, nama, jenis, nilai, kuota terpakai, masa berlaku, status).
**Aksi.** Tambah/ubah voucher (pop-up, cakupan produk & kategori lewat pemilih berganda), aktifkan/nonaktifkan, hapus.
**Catatan.** Produk populer **tidak** lagi diatur di sini — pindah ke menu Produk.

### 4.6 Produk — `/admin/catalog`
`AdminCatalogController` · `catalog.manage` · `Catalog.vue`

**Untuk apa.** Mengelola katalog yang dijual.
**Isi.** Dua tab: **Produk Otomatis** dan **Produk Manual**. Filter (kategori, status, pencarian), paginasi server; tabel produk (gambar, nama, kategori, jumlah nominal, status, penanda populer); pratinjau harga di kasir.
**Aksi.** Tambah produk, ubah, hapus, atur tambahan modal + markup jual (dua angka bawaan impor), kelola nominal (tambah/ubah/salin/hapus/urutkan), kelola stok kode pada nominal produk voucher, kelola pemberitahuan produk, unggah/hapus media, atur kode kolom & field.
**Harga.** Hanya tiga angka: **harga seller** (dari penyedia) → **modal toko** (seller + tambahan modal, bawaan 2%) → **harga jual** (modal + markup, bawaan 10%). Tidak ada margin global.
**Catatan.** Editor produk 5 tab: Informasi, Nominal & Harga, Sumber, Tampilan Produk, Data Pelanggan. Produk yang sudah punya riwayat pesanan tidak bisa dihapus — nonaktifkan saja. Tombol "Impor ke produk" ada di menu Digiflazz dan mengarah ke halaman ini (`/admin/catalog?import=digiflazz`); layar pemilihan nominalnya belum dibuat — lihat §10.

### 4.7 Kategori — `/admin/catalog/categories`
`AdminCatalogController@categories` · `catalog.manage` · `Categories.vue`

**Untuk apa.** Menyusun kelompok produk.
**Isi.** Tabel kategori: nama, jumlah produk, status, urutan, gambar.
**Aksi.** Tambah/ubah/hapus kategori (pop-up).
**Catatan.** Kategori yang masih dipakai produk tidak bisa dihapus.

### 4.8 Banner & Konten — `/admin/content`
`AdminContentController` · `content.manage` · `Content.vue`

**Untuk apa.** Mengatur isi toko yang dibaca pelanggan.
**Isi.** Tiga bagian, masing-masing berisi sub-tab:
- **Tampilan Toko** — Banner Utama, Pop-up Pengumuman, Logo & Gambar Cadangan, Teks Footer & Bantuan.
- **Bantuan & Kebijakan** — Pertanyaan Umum (FAQ), Kebijakan Toko.
- **Berita & Ulasan** — Berita, Ulasan Pelanggan.
**Aksi.** Tambah/ubah/hapus banner, pop-up, berita, FAQ, halaman kebijakan; ubah/aktifkan aset; setujui/hapus ulasan; simpan teks footer.
**Catatan.** Gambar diunggah lewat `AdminMedia` (JPG/PNG/WebP).

### 4.9 Tampilan Toko — `/admin/content/presentation`
`AdminPresentationController` · `content.manage` · `Presentation.vue`

**Untuk apa.** Mengubah wajah toko tanpa menyentuh kode.
**Isi.** Empat tab: **Teks halaman**, **Font & Panel**, **Bagian Homepage**, **Warna Website**. Daftar bagian diambil dari `registry`, nilai tersimpan dari `presentation`/`theme`.
**Aksi.** Ubah tiap bagian (pop-up), simpan teks (`PUT /admin/content/presentation`), simpan warna (`PUT /admin/content/theme`).
**Catatan.** Semua warna toko diatur di sini — bukan di CSS.

### 4.10 Digiflazz — `/admin/digiflazz`
`AdminDigiflazzController` · `providers.manage` · `Digiflazz.vue`

**Untuk apa.** Memantau harga dan sambungan Digiflazz.
**Isi.** Ringkasan (normal / peringatan / kritis, bisa diklik sebagai filter); daftar harga (kategori, merek, produk, harga modal, status); pengaturan pemantauan; transaksi Digiflazz terbaru; status sambungan dan waktu sinkron.
**Aksi.** Sinkron daftar harga (`POST /admin/digiflazz/sync` — memengaruhi **seluruh katalog**), impor ke produk (tautan ke Produk), simpan pengaturan pemantauan, terapkan/hapus filter.
**Catatan.** Sinkron otomatis berjalan tiap 5 menit lewat penjadwal. Sinkron manual dan otomatis memakai jalur yang sama.

### 4.11 Validasi Akun — `/admin/nickname-tools`
`AdminNicknameController` · Super Admin · `NicknameTools.vue`

**Untuk apa.** Menguji layanan cek nama akun game.
**Isi.** Empat tab: **Cek nama game**, **Cek server**, **Cek PLN**, **Daftar kode game**. Hasil pemeriksaan dan alamat layanan yang dipakai.
**Aksi.** Cek data (`POST /admin/nickname-tools/check`), tambah/ubah/hapus/urutkan kode game.
**Catatan.** Kode game di sini yang dipakai `customer_no_template` di Produk.

### 4.12 Provider — `/admin/providers`
`AdminProviderController` · `providers.manage` · `Providers.vue`

**Untuk apa.** Melihat penyedia dan nominal yang dilayaninya.
**Isi.** Ringkasan; daftar penyedia (status, prioritas); tabel pemetaan nominal per produk beserta status rekonsiliasi.
**Aksi.** Ubah penyedia (status/prioritas) lewat pop-up; terapkan filter.
**Catatan.** Failover otomatis dimatikan; penyedia bermasalah ditandai, bukan dipindah diam-diam.

### 4.13 Pembayaran — `/admin/payments`
`AdminPaymentController` · `payments.manage` · `Payments.vue`

**Untuk apa.** Mengatur cara pelanggan membayar.
**Isi.** Tiga tab: **Metode Pembayaran**, **Transaksi Pembayaran**, **Halaman Pembayaran**. Bagian: gateway & kanal, QRIS manual, top up LFAMILIA Coin, alamat notifikasi gateway, pengaturan halaman pembayaran (warna aksen, banner, teks, sakelar).
**Aksi.** Tambah/ubah/hapus kanal, sinkron kanal, unggah logo & gambar QRIS, aktifkan QRIS manual, konfirmasi pembayaran QRIS manual, simpan top up, ubah route gateway, simpan pengaturan halaman + unggah banner, pulihkan metode bawaan.
**Catatan.** Konfirmasi QRIS manual memakai konfirmasi kata sandi. DOKU Checkout dan Midtrans berdiri sendiri; pemilihan gateway ditentukan manual per metode dan tujuan, bukan utama/cadangan.

### 4.14 Laporan — `/admin/reports`
`AdminReportController` · `reports.view` (+ `reports.finance`) · `Reports.vue`

**Untuk apa.** Membaca angka penjualan dan operasi.
**Isi.** Tiga tab: **Penjualan**, **Produk**, **Operasional**. Kotak angka, grafik pesanan per hari, rincian harian, produk terlaris, kategori teratas, kinerja penyedia, status pesanan/pembayaran/pengiriman, saldo pelanggan saat ini.
**Aksi.** Pilih rentang, tampilkan, unduh CSV (dihitung di peramban, lalu dicatat ke audit lewat `POST /admin/reports/export-audit`).
**Catatan.** Baris omzet/laba/diskon/biaya hanya dikirim bila `reports.finance`; batas hari 00:00–23:59 WIB.

### 4.15 Pelanggan — `/admin/customers`
`AdminCustomerController` · `customers.view` · `Customers.vue`

**Untuk apa.** Melihat dan mengatur pelanggan.
**Isi.** Dua tab: **Daftar pelanggan** (ringkasan, filter, tabel: nama, kontak, tingkat, total belanja, pesanan berhasil, saldo, tiket terbuka, status) dan **Tingkat member** (ambang belanja + diskon per tingkat, catatan manfaat).
**Aksi.** Buka detail, atur ambang & diskon tiap tingkatan (`PUT /admin/settings/membership/{code}`, bila `canManageMembershipRules`), atur pembersihan akun kosong (`PUT /admin/customers/cleanup/settings`, `POST /admin/customers/cleanup/run`), terapkan filter.
**Catatan.** MAFIA adalah tingkat internal; tidak pernah ditampilkan sebagai tingkatan ke pelanggan.

### 4.16 Detail Pelanggan — `/admin/customers/{userId}`
`AdminCustomerController@show` · `customers.view` · `CustomerDetail.vue`

**Untuk apa.** Melihat satu pelanggan dari profil sampai uangnya.
**Isi.** Tujuh bagian berurutan: Profil, Tingkat member, Riwayat pesanan, Perubahan saldo, Riwayat top up, Tiket bantuan, Akun game tersimpan. Ringkasan total belanja, pesanan berhasil, saldo.
**Aksi.** Atur pelanggan (pop-up), **penyesuaian saldo** (Super Admin, pop-up + konfirmasi kata sandi, alasan wajib, arah tambah/kurang), hapus pelanggan bila `deletable`, buka tiket/pesanan.
**Catatan.** Namanya "Penyesuaian saldo", bukan "Refund". Batas jumlah dan alasan divalidasi di klien dan server.

### 4.17 Layanan Pelanggan — `/admin/support`
`AdminSupportController` · `support.manage` · `Support.vue`

**Untuk apa.** Menjawab tiket pelanggan dan guest.
**Isi.** Kotak angka (terbuka, dikerjakan, selesai, ditutup); filter jenis/status/pencarian; tabel tiket; panel detail tiket di samping (bukan pop-up) berisi percakapan dan data pelanggan/pesanan.
**Aksi.** Buka tiket, balas/tutup (`PUT /admin/support/{id}`), atur balasan cepat (`PUT /admin/support/quick-replies`).
**Catatan.** Balasan cepat tidak boleh memuat data pelanggan lain.

### 4.18 Notifikasi — `/admin/notifications`
`AdminNotificationController` · `notifications.view` · `Notifications.vue`

**Untuk apa.** Membaca pemberitahuan panel.
**Isi.** Daftar notifikasi (belum dibaca ditandai), waktu WIB, jenis, tautan sasaran, paginasi.
**Aksi.** Tandai dibaca (`POST /admin/notifications/{id}/read`), tandai semua dibaca (`POST /admin/notifications/read-all`).
**Catatan.** Waktu kosong/rusak tidak boleh membuat halaman error.

### 4.19 Integrasi — `/admin/integrations`
`AdminIntegrationController` · Super Admin · `Integrations.vue`

**Untuk apa.** Memasang kunci sambungan layanan.
**Isi.** Sembilan tab: Digiflazz, Validasi Akun, Midtrans Snap, DOKU Checkout, Resend Email, Google OAuth, Notifikasi Telegram, Notifikasi Discord, Cloudflare Turnstile. Tiap tab: status, tiga kotak keadaan, kolom kredensial, alamat callback untuk disalin, petunjuk lipatan alamat otomatis.
**Aksi.** Simpan integrasi (`PUT /admin/integrations/{code}`), tes koneksi (`POST /admin/integrations/{code}/test`), kirim email uji (Resend), salin alamat callback, hapus data akses.
**Catatan.** Kredensial tidak pernah ditampilkan; kosong = pertahankan, isi = ganti. Tes koneksi tidak memakai transaksi nyata. DOKU tidak bisa diaktifkan tanpa keputusan tertulis.

### 4.20 Kesehatan Sistem — `/admin/health`
`AdminHealthController` · Super Admin · `Health.vue`

**Untuk apa.** Mengetahui apa yang rusak sebelum pelanggan tahu.
**Isi.** Lima bagian: Perlu perhatian (di urutan pertama), Pemeriksaan komponen, Kesehatan integrasi, Gateway pembayaran, Lingkungan aplikasi; waktu pemeriksaan; pesanan dengan pengiriman basi (`stale_fulfillment`); pekerjaan gagal.
**Aksi.** Muat ulang, buka integrasi terkait.
**Catatan.** Pemeriksaan yang belum pernah jalan ditulis **"Belum diperiksa"**, bukan hijau.

### 4.21 Catatan Audit — `/admin/audit`
`AdminAuditController` · Super Admin · `Audit.vue`

**Untuk apa.** Melacak siapa melakukan apa.
**Isi.** Ringkasan; filter waktu, admin, jenis aktivitas, jenis sasaran; tabel waktu/admin/aktivitas/sasaran/IP/detail; paginasi.
**Aksi.** Terapkan/hapus filter, muat ulang.
**Catatan.** Hanya baca — tidak bisa diubah atau dihapus. IP disamarkan sebagian.

### 4.22 Admin & Akses — `/admin/access`
`AdminAccessController` · Super Admin · `Access.vue`

**Untuk apa.** Mengatur siapa boleh masuk panel.
**Isi.** Ringkasan; daftar admin (nama, email, peran, status, login terakhir); editor izin 14 butir; aktivitas admin terbaru.
**Aksi.** Tambah/ubah admin (pop-up), atur izin, nonaktifkan, hapus — dengan konfirmasi kata sandi.
**Catatan.** Admin tidak bisa menaikkan izinnya sendiri atau mengubah Super Admin lain.

### 4.23 Pengaturan — `/admin/settings`
`AdminSettingsController` · `settings.manage` · `Settings.vue`

**Untuk apa.** Identitas toko dan ekspor konfigurasi.
**Isi.** Toko & kontak (nama, tagline, legal, registrasi, WhatsApp, Instagram, email, Discord, alamat, jam operasional); ringkasan kelengkapan; ekspor pengaturan (Super Admin).
**Aksi.** Simpan pengaturan toko (`PUT /admin/settings`), unduh konfigurasi (`GET /admin/configuration/export`).
**Catatan.** Discord di sini untuk tautan footer toko, bukan notifikasi admin. Tingkatan member diatur di menu Pelanggan.

---

## 5. Halaman masuk

| Halaman | Alamat | Berkas |
|---|---|---|
| Masuk | `/admin/login` | `Login.vue` |
| Lupa kata sandi | `/admin/forgot-password` | `ForgotPassword.vue` |
| Atur ulang kata sandi | `/admin/reset-password/{token}` | `ResetPassword.vue` |
| Konfirmasi kata sandi | `/admin/password/confirm` | `PasswordConfirm.vue` |

**Catatan:** keempatnya memakai kerangka terang yang sama (`.auth-page` / `.auth-card`): latar `#f4f5f3`, kartu putih, aksen hijau, lebar maksimal 400px, tanpa gaya gelap. Tidak ada lagi kelas `lf-admin-*` di seluruh panel.

---

## 6. Peran & izin

Dua peran: **Super Admin** dan **Admin**. 14 izin di `AdminPermissionService::DEFINITIONS`.

| Izin | Arti |
|---|---|
| `dashboard.view` | Dashboard |
| `orders.view` | Pesanan |
| `catalog.manage` | Produk & katalog |
| `content.manage` | Banner & konten, Tampilan Toko |
| `fulfillment.manage` | Penanganan manual |
| `providers.manage` | Digiflazz & provider |
| `payments.manage` | Pembayaran operasional |
| `customers.view` | Pelanggan |
| `vouchers.manage` | Promo / voucher |
| `support.manage` | Layanan pelanggan |
| `reports.view` | Laporan |
| `reports.finance` | Laporan finansial |
| `settings.manage` | Pengaturan non-secret |
| `notifications.view` | Notifikasi |

**Super Admin saja (tanpa izin terpisah):** Admin & Akses, Integrasi, Kesehatan Sistem, Catatan Audit, Validasi Akun, penyesuaian saldo pelanggan.

Aturan:

- Izin diperiksa di server (`EnsureAdminPermission`, `EnsureSuperAdmin`), bukan hanya menyembunyikan tombol.
- Menu yang tidak diizinkan tidak dikirim backend, jadi tidak muncul di sidebar.
- Admin tidak bisa menaikkan izinnya sendiri dan tidak bisa mengubah Super Admin lain.

---

## 7. Aksi yang minta konfirmasi

| Aksi | Konfirmasi | Bukti di kode |
|---|---|---|
| Penyesuaian saldo pelanggan | Pop-up + kata sandi + alasan wajib | `POST /admin/customers/{id}/wallet` + `RequireAdminPasswordConfirm` |
| Batalkan pesanan | Pop-up + kata sandi server | `POST /admin/orders/{id}/cancel` + `RequireAdminPasswordConfirm` |
| Konfirmasi QRIS manual | Konfirmasi + kata sandi server | `POST /admin/payments/manual/{id}/confirm` + `RequireAdminPasswordConfirm` |
| Tambah/ubah/nonaktifkan admin | Kata sandi (diperiksa `/admin/password/confirm`) | `Access.vue` |
| Hapus produk/kategori/voucher/pelanggan | Pop-up konfirmasi | `AdminModal` |
| Impor katalog | Pratinjau sebelum setuju | `Catalog.vue` |

Semua aksi di atas masuk audit log.

---

## 8. Keadaan kosong & peringatan

- Belum ada pesanan → "Belum ada pesanan" + keterangan, bukan tabel kosong tanpa teks.
- Belum ada transaksi → `Rp 0` dengan keterangan "Belum ada transaksi".
- Integrasi belum diisi → status "Belum tersambung" + tombol ke Integrasi.
- Pemeriksaan kesehatan belum jalan → "Belum diperiksa".
- Data kosong/null tidak boleh membuat halaman putih: props dijaga `Array.isArray`, `?? []`, dan nilai tanggal kosong ditulis "—".

---

## 9. Yang sengaja tidak dibuat (dari template)

| Di template | Kenapa tidak dibuat |
|---|---|
| Blok "Refund Top Up Perlu Rekonsiliasi" | Refund tidak otomatis (PRD §19) — lihat catatan temuan di §10 |
| Tombol "Selesaikan pesanan" tanpa bukti | Harus lewat percobaan pengiriman yang tercatat |
| Tombol dummy tanpa fungsi | Tidak ada tombol yang tidak melakukan apa pun |
| Grafik tanpa angka | Wajib ada angka |
| Tema gelap | Panel terang |
| Inisial/placeholder saat gambar tidak ada | Biarkan kosong |

---

## 10. Batas yang diketahui

1. **Dibuang karena tidak ada di rancangan panel:** margin global (`catalog/margin` + pratinjau), batas harga atas (`max-prices/*`, `digiflazz/baseline/{id}`), kunci harga massal (`lock-prices`), dan `orders/{id}/check-process` (digantikan tombol "Periksa status" di Antrean Manual). Pagar modal+1% tetap berjalan otomatis di server; yang dibuang hanya layar pengaturannya.
2. **Kelola sumber per nominal ada di tab Sumber** editor produk: daftar mapping, modal toko yang bisa diedit, aktif/nonaktif, prioritas (tukar naik/turun), dan sinkron per mapping lewat `mappings/{id}` dan `mappings/{id}/sync`. Sinkron per produk tidak ada: sinkron berarti seluruh katalog lewat menu Digiflazz. Yang belum punya tombol: `packages/{id}/sources/digiflazz`.
3. **Impor Digiflazz punya layar, tapi hanya dari tautan.** Menu Digiflazz → "Impor ke produk" membuka `/admin/catalog?import=digiflazz` dan pop-up impor langsung terbuka; tombol "Impor dari Digiflazz" juga ada di kepala halaman Produk. Yang belum: memilih SKU dari halaman detail Digiflazz satu per satu (`?import_sku=`).
4. **Blok rekonsiliasi refund top up disembunyikan** dari Pembayaran (23 Okt 2026). Tombolnya tidak lagi dikirim ke peramban, tetapi `POST /admin/payments/refund-reviews/{topupId}/resolve` masih ada di server sebagai jalur pemeliharaan; pesanan dengan top up berstatus `REFUND_REVIEW` tetap ditahan mesin pembayaran sampai rekonsiliasi manual lewat basis data.
5. **DOKU** belum dipakai; tampil sebagai belum aktif.
6. **Data lokal adalah data uji** — angka di layar lokal bukan angka produksi.
7. **Konfirmasi kata sandi di Admin & Akses ditegakkan server.** Route `POST/PUT/DELETE admin/access` memakai middleware `RequireAdminPasswordConfirm`, jadi permintaan langsung tanpa konfirmasi dialihkan ke `/admin/password/confirm` dan data tidak berubah. Panel menanyakan sandi setiap kali akun admin disimpan.

---

## 11. Gerbang verifikasi

Panel dianggap sehat bila semua ini lulus:

| Gerbang | Perintah | Hasil terakhir (23 Okt 2026) |
|---|---|---|
| Suite PHPUnit penuh | `php vendor/bin/phpunit` | 530 tes, 5.296 asersi, 2 dilewati, 0 gagal |
| Penjaga panel (Node) | `node --test tests/Browser/customer-ui-guards.test.mjs tests/Browser/admin-ui-guards.test.mjs` | 46 lulus, 0 gagal |
| Gaya PHP | `php-pint --test` pada berkas berubah | lulus |
| Lint Vue | `npx eslint resources/js/Pages/Admin/*.vue resources/js/Components/Admin/*.vue` | 0 error |
| Build | `npx vite build` | sukses |
| Lebar 375px | buka Dashboard, Produk, Laporan | luapan mendatar 0 px |
| Semua halaman | 21 menu + 2 detail | terbuka, tidak ada 404 |

Database tes lokal: `lfamilia_admin_redesign_test` (di `phpunit.xml` tanpa `force`, jadi CI tetap memakai `DB_DATABASE=lfamilia_ci`).
