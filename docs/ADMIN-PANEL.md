# ADMIN-PANEL.md — Spesifikasi panel admin LFAMILIA STORE

**Versi:** 1.0
**Tanggal:** 22 Oktober 2026
**Status:** spesifikasi untuk pembangunan ulang. Panel yang berjalan sekarang masih versi lama (gelap).
**Acuan tampilan:** `template-panel-admin-lfamilia.html` (prototipe 23 halaman). Template adalah **acuan rasa dan tata letak**, bukan acuan fitur — semua tombol di template dummy.
**Aturan yang menang:** `docs/PRD.md`. Kalau dokumen ini bertentangan dengan PRD, PRD menang.

---

## 1. Prinsip

1. **Panel adalah alat kerja, bukan pajangan.** Yang penting: cepat, jelas, tidak salah klik.
2. **Terang.** Putih dan abu, sidebar hitam, satu aksen hijau. Tema gelap tidak dipakai.
3. **Padat tapi kebaca.** Desktop 13px, HP 14px.
4. **Jujur.** Belum ada data → tampilkan kosong dengan keterangan. Tidak ada angka contoh, tidak ada tombol palsu.
5. **Satu bahasa.** Semua tulisan Indonesia. Tidak ada campuran "System Health", "Audit Log", "Manual".
6. **Aman secara bawaan.** Aksi yang mengubah uang atau data penting selalu minta konfirmasi.

---

## 2. Aturan tampilan

### 2.1 Warna

| Peran | Terang |
|---|---|
| Latar halaman | `#f4f5f3` |
| Kartu / panel | `#ffffff` |
| Panel sekunder | `#fafbfa` |
| Garis | `#e2e5e0` |
| Teks utama | `#171a17` |
| Teks kedua | `#5c665c` |
| Teks samar | `#8a948a` |
| Aksen (utama) | `#65a30d` |
| Aksen kuat | `#4d7c0f` |
| Aksen lembut | `#eef6dd` |
| Sidebar | `#101410` |
| Teks sidebar | `#c9d1c5` |
| Teks sidebar samar | `#7d887a` |
| Merah (gagal) | `#dc2626` |
| Kuning (perlu diperiksa) | `#b45309` |
| Biru (sedang proses) | `#2563eb` |
| Hijau (berhasil) | `#15803d` |

Aturan: **satu aksen saja**. Hijau hanya untuk tindakan utama, menu aktif, dan status berhasil. Jangan menambah warna dekoratif.

### 2.2 Bentuk & ukuran

- Sudut: **8px** untuk kartu, tombol, input.
- Garis: **1px** tipis. **Tanpa bayangan** — pemisah memakai garis dan latar abu.
- Huruf: 13px desktop, 14px HP. Label huruf kecil **KAPITAL** dengan jarak huruf.
- Angka uang rata kanan, memakai pemisah ribuan titik (`Rp 4.812.500`).
- Baris tabel: **44px** desktop, 52px HP.
- Tombol: tinggi 32px desktop, 40px HP. Sasaran sentuh minimal 40×40 di HP.

### 2.3 Tabel

- Kolom yang tidak muat → **geser ke samping di dalam tabel**, jangan memotong halaman.
- Kode panjang (nomor invoice) **tidak boleh membungkus jadi 3 baris**. Pakai satu baris dengan elipsis + tombol salin.
- Baris bisa diklik untuk membuka detail.
- Kosong → satu baris "Belum ada data" di tengah, bukan tabel kosong tanpa keterangan.

### 2.4 Grafik

- Grafik penjualan **wajib ada angkanya** (di atas batang atau saat disentuh). Template tidak punya angka; itu tidak ditiru.
- Omzet hanya dari pesanan lunas, waktu WIB.
- Rentang tanggal ditampilkan jelas di bawah grafik.

---

## 3. Struktur & navigasi

Sidebar kiri tetap (224px), gelap, berkelompok. Di HP jadi laci yang dibuka tombol.

```
Dashboard

TRANSAKSI
  Pesanan                 (jumlah yang perlu perhatian)
  Antrean Manual          (jumlah menunggu)
  Promo & Voucher

KATALOG
  Produk
  Kategori

KONTEN
  Banner & Konten
  Tampilan Toko

INTEGRASI
  Digiflazz
  Validasi Akun
  Provider

KEUANGAN
  Pembayaran
  Laporan

PELANGGAN
  Pelanggan

LAYANAN PELANGGAN
  Bantuan               (jumlah tiket terbuka)

SISTEM
  Integrasi
  Kesehatan Sistem
  Catatan Audit
  Notifikasi

PENGATURAN
  Admin & Akses
  Pengaturan
```

Aturan navigasi:

- **Nama menu memakai bahasa Indonesia.** "System Health" → **Kesehatan Sistem**. "Audit Log" → **Catatan Audit**. "Manual" → **Antrean Manual**. "Validasi Akun" tetap.
- **Menu Integrasi (Sistem) dan grup Integrasi berbeda.** Grup = kategori; menu = halaman status sambungan. Beri keterangan di judul halaman supaya tidak tertukar.
- Menu yang tidak boleh diakses peran tetap tidak muncul (bukan muncul lalu ditolak).
- **Angka kecil di menu** (jumlah pesanan/tiket) hanya muncul kalau lebih dari nol.
- Pencarian global di topbar, jalan dengan `Ctrl+K`.

---

## 4. Halaman — 23 halaman

Untuk tiap halaman: **untuk apa**, **isi**, **aksi**, dan **catatan teknis** (apakah controller sekarang cukup).

### 4.1 Dashboard

**Untuk apa:** tahu keadaan toko dalam 10 detik.

**Isi:**
- 8 kartu angka: omzet hari ini, pesanan hari ini, produk aktif, saldo Digiflazz, pembayaran berhasil, saldo wallet pelanggan, tiket terbuka, antrean manual.
- Grafik penjualan (7/30 hari), bisa ganti antara omzet dan jumlah pesanan. **Dengan angka.**
- Tabel 5 pesanan terbaru.
- Tiga panel bawah: aktivitas terbaru, status integrasi, notifikasi terbaru.
- Tombol muat ulang + waktu terakhir diperbarui.

**Aksi:** pindah ke pesanan, buka detail, buka audit, buka integrasi.

**Catatan:** controller sekarang sudah menyediakan angka. Yang perlu ditambah: **keadaan kosong** yang jelas saat toko belum punya transaksi, dan angka di grafik.

---

### 4.2 Pesanan

**Untuk apa:** mencari pesanan dan memeriksa keadaannya.

**Isi:**
- Filter: kata kunci (invoice/pelanggan), status, tanggal, kanal pembayaran.
- Tabel: nomor pesanan, pelanggan, produk & tujuan, pembayaran, status bayar, status proses, tindakan.
- Pilihan baris (untuk aksi massal kalau ada).
- Ekspor hasil filter.
- Tombol **Catat pesanan manual**.

**Aksi:** buka detail, salin nomor, catat pesanan manual.

**Catatan:** `AdminOrdersController` sudah menangani semua ini. Yang perlu diperiksa: kolom status sekarang mencampur status bayar dan status proses — di layar dipisah dua kolom, dan hanya status pelanggan (6 status) yang dipakai untuk label.

---

### 4.3 Detail Pesanan

**Untuk apa:** menyelesaikan satu pesanan.

**Isi (urut atas ke bawah):**
1. **Status saat ini** — besar, jelas, dengan alasan.
2. **Ringkasan pesanan** — nomor, waktu, produk, nominal, total.
3. **Pelanggan dan tujuan** — siapa, kontak, data tujuan yang dipakai.
4. **Hasil pengiriman** — serial number / isi kiriman, sumber, waktu.
5. **Riwayat pesanan** — semua kejadian, urut waktu.
6. **Pembayaran** — percobaan bayar, status, bukti.
7. **Proses pesanan** — tombol tindakan.

**Aksi:** periksa pembayaran, konfirmasi pembayaran manual, kirim ulang, batalkan, nyatakan gagal, hubungi pelanggan, salin nomor.

**Aturan:**
- **Pesanan yang sudah berhasil tidak bisa dibatalkan.**
- Aksi yang mengubah uang **wajib konfirmasi** dan wajib alasan.
- Tombol "selesaikan pesanan" hanya muncul kalau memang ada yang bisa diselesaikan.

**Catatan:** `AdminOrderDetailController` + `AdminOrderCancellationController` sudah ada. Perlu ditambah: alasan wajib pada pembatalan, dan pemisahan tegas antara "gagal" dan "perlu diperiksa".

---

### 4.4 Antrean Manual

**Untuk apa:** memproses pesanan yang tidak otomatis.

**Isi:**
- Filter status dan tanggal.
- Tabel: pesanan, pelanggan, produk & tujuan, sumber, status, aksi.
- Penanda **lewat 1 jam** (jam layanan aktif) berwarna kuning.
- Jam operasional produk ditampilkan di baris kalau di luar jam.

**Aksi:** tangani (buka lembar kerja), lihat pesanan.

**Catatan:** `AdminFulfillmentController` sudah ada. Perlu ditambah: hitungan 1 jam berbasis jam layanan, bukan jam biasa.

---

### 4.5 Promo & Voucher

**Untuk apa:** mengatur kode diskon dan stok kode voucher.

**Isi:**
- Filter kata kunci dan status.
- Tabel: voucher, diskon, minimum, pemakaian, periode, cakupan, status, tindakan.

**Aksi:** tambah, ubah, nonaktifkan, hapus.

**Catatan:** `AdminPromotionController` sudah ada. Stok kode voucher (gift card) dikelola dari halaman Produk, bukan di sini.

---

### 4.6 Produk

**Untuk apa:** mengatur katalog dan harga.

**Isi:**
- Pencarian (nama, merek, nominal).
- Tab: Produk Otomatis / Produk Manual.
- Tabel: produk, kategori, jenis, jumlah nominal, urutan, status, aksi.
- Panel **Impor nominal Digiflazz** (pratinjau → setujui).
- Pengaturan harga: margin global, per game, per nominal.

**Aksi:** tambah produk, ubah, atur nominal, impor, sinkron, atur urutan, aktif/nonaktif.

**Aturan:**
- Impor **selalu lewat pratinjau**. Tidak ada impor langsung tanpa melihat perubahan.
- Harga jual tidak boleh di bawah modal. Kalau terjadi, tampilkan peringatan dan tolak simpan.
- Perubahan harga tercatat di audit.

**Catatan:** `AdminCatalogController` + `AdminCatalogMediaController` sudah ada dan lengkap. Ini halaman paling berat; jangan disederhanakan sampai kehilangan fungsi.

---

### 4.7 Kategori

**Isi:** daftar kategori dengan ikon, urutan, status. Aksi: tambah, ubah, hapus, atur urutan.

**Catatan:** bagian dari `AdminCatalogController`.

---

### 4.8 Banner & Konten

**Untuk apa:** mengatur semua yang dilihat pengunjung.

**Isi (dalam tab):**
- Banner utama
- Pop-up pengumuman
- Logo & gambar cadangan
- Teks footer & bantuan
- Berita & ulasan
- Bantuan & kebijakan (FAQ, halaman hukum)

**Aksi:** tambah, ubah, hapus, atur urutan, unggah gambar.

**Aturan:**
- **Halaman hukum (`/terms`, `/privacy`, `/refund`) tidak boleh dihapus**, hanya diedit isinya.
- Gambar wajib punya teks alternatif.

**Catatan:** `AdminContentController` + `AdminPresentationController` sudah ada.

---

### 4.9 Tampilan Toko

**Untuk apa:** mengatur rupa toko di sisi pelanggan.

**Isi:** teks halaman, font & panel, bagian homepage, warna website.

**Aturan penting:** ini **bukan** halaman untuk mengubah panel admin. Ini mengubah **toko yang dilihat pembeli**. Beri peringatan jelas di atas halaman supaya admin tidak bingung.

**Catatan:** `AdminPresentationController` sudah ada.

---

### 4.10 Digiflazz

**Untuk apa:** memantau pemasok utama.

**Isi:**
- Saldo, status sambungan, waktu sinkron terakhir.
- Pengaturan: ambang saldo rendah (5 transaksi), interval sinkron (minimal 5 menit).
- Tabel nominal tertaut: produk & nominal, SKU/seller, harga modal, stok & jam layanan, kondisi, aksi.
- Tabel transaksi Digiflazz terbaru.

**Aksi:** sinkron daftar harga, impor ke produk, simpan pengaturan.

**Aturan:**
- **Kredensial tidak pernah tampil.** Hanya status "tersambung / belum".
- Sinkron tidak boleh menghapus produk yang sudah ada.
- Tampilkan peringatan keras kalau saldo di bawah ambang.

**Catatan:** `AdminDigiflazzController` + `AdminDigiflazzMonitorService` sudah ada.

---

### 4.11 Validasi Akun

**Untuk apa:** mengecek nama pemain sebelum pembeli bayar.

**Isi:**
- Alat cek: nama game, server, PLN.
- Tabel kode game: urutan, game, kode, dukungan cek nama, server/zona, region, status, produk tertaut.

**Aksi:** cek data, bersihkan hasil, tambah/ubah kode game.

**Aturan:** kalau layanan cek mati, **pembelian tetap jalan**. Halaman ini tidak boleh jadi penghalang.

**Catatan:** `AdminNicknameController` sudah ada.

---

### 4.12 Provider

**Isi:** daftar penyedia (Digiflazz, dan lainnya), jenis layanan, status koneksi, jumlah nominal tertaut, performa 24 jam, urutan.

**Aksi:** ubah, buka integrasi, kelola di produk.

**Catatan:** `AdminProviderController` sudah ada.

---

### 4.13 Pembayaran

**Untuk apa:** mengatur uang masuk.

**Isi (dalam tab):**
- **Metode pembayaran:** metode, jenis, biaya ditagih, kesiapan, status.
- **Pengaturan Top Up:** aktif/tidak, nominal minimum.
- **QRIS manual:** gambar QRIS, status, daftar menunggu konfirmasi.
- **URL Notifikasi Gateway:** alamat webhook yang harus dipasang di Midtrans (dengan tombol salin).
- **Tampilan Checkout.**
- **Transaksi Pembayaran:** referensi, pelanggan, metode, status, waktu.

**Aksi:** tambah/ubah/hapus metode, pulihkan bawaan, konfirmasi QRIS manual, atur top up.

**Aturan:**
- **Tidak ada tombol refund.** Tidak ada.
- Konfirmasi QRIS manual **wajib** menampilkan jumlah dan nama pembayar sebelum disetujui.
- **Blok "Refund Top Up Perlu Rekonsiliasi" di template TIDAK dibuat.** Yang ada hanya "Top up perlu diperiksa" — tanpa aksi memindahkan uang.

**Catatan:** `AdminPaymentController` sudah ada dan cukup besar. Perlu disesuaikan: buang semua jalur yang berujung pada pemindahan uang otomatis.

---

### 4.14 Laporan

**Isi:** tab Penjualan / Produk / Operasional. Tabel omzet, pesanan, berhasil, laba kotor. Ekspor.

**Aturan:** batas hari **00:00–23:59 WIB**. Angka harus sama dengan dashboard.

**Catatan:** `AdminReportController` sudah ada.

---

### 4.15 Pelanggan

**Isi:** pencarian, filter, tabel pelanggan. Tab **Tingkat member** untuk mengatur ambang dan diskon per tingkat.

**Aksi:** buka detail, ubah tingkatan, atur pembersihan akun kosong.

**Catatan:** `AdminCustomerController` sudah ada.

---

### 4.16 Detail Pelanggan

**Isi:** profil, tingkat member, riwayat pesanan, saldo & top up, tiket bantuan, akun game tersimpan.

**Aksi:** atur pelanggan, **penyesuaian saldo** (Super Admin saja), buka bantuan, buka pesanan.

**Aturan penyesuaian saldo:**
- Hanya Super Admin. Tombol tidak muncul untuk Admin biasa.
- Wajib: jumlah, alasan, rujukan (kalau ada).
- Konfirmasi kata sandi sebelum menyimpan.
- Masuk audit log, tidak bisa dihapus.
- **Namanya "Penyesuaian saldo", bukan "Refund".**

**Catatan:** `AdminCustomerController::updateWallet` sudah ada. Perlu diperketat: khusus Super Admin, alasan wajib.

---

### 4.17 Bantuan Pelanggan

**Isi:** daftar tiket, detail tiket, balasan cepat.

**Aksi:** balas, tutup, buka data pelanggan, buka pesanan, atur balasan cepat.

**Aturan:** balasan cepat tidak boleh memuat data pelanggan lain.

**Catatan:** `AdminSupportController` sudah ada.

---

### 4.18 Admin & Akses

**Isi:** daftar admin, peran, status, hak akses, login terakhir. Aktivitas admin terbaru.

**Aksi:** tambah admin, ubah, nonaktifkan, hapus, atur izin.

**Aturan:** hanya Super Admin. Admin tidak bisa menaikkan izinnya sendiri.

**Catatan:** `AdminAccessController` sudah ada.

---

### 4.19 Pengaturan

**Isi:** identitas toko (nama, tagline, legal, registrasi), kontak (WhatsApp, Instagram, email, Discord), alamat, jam operasional, ekspor pengaturan.

**Aturan:** **Discord di sini untuk tautan footer toko**, bukan untuk notifikasi admin. Dua hal berbeda, jangan digabung.

**Catatan:** `AdminSettingsController` sudah ada.

---

### 4.20 Integrasi

**Untuk apa:** memasang kunci sambungan.

**Isi:** kartu per integrasi — Digiflazz, Midtrans, DOKU, Resend, Google, Telegram, Discord, Validasi Akun, Turnstile. Tiap kartu: status, tes sambungan, kolom kredensial.

**Aksi:** simpan kredensial, tes sambungan, salin URL webhook.

**Aturan:**
- Kredensial **tidak pernah ditampilkan** setelah disimpan. Kosong = pertahankan, isi = ganti.
- **DOKU tampil sebagai "belum dipakai"** dan tidak bisa diaktifkan tanpa keputusan tertulis.
- Tes sambungan tidak boleh memakai transaksi nyata.

**Catatan:** `AdminIntegrationController` + `IntegrationRegistry` sudah ada. DOKU perlu ditandai.

---

### 4.21 Kesehatan Sistem

**Isi:** panel "perlu perhatian", semua komponen, kesehatan integrasi, gateway pembayaran, lingkungan aplikasi.

**Aksi:** muat ulang, buka integrasi.

**Catatan:** `AdminHealthController` sudah ada.

---

### 4.22 Catatan Audit

**Isi:** filter (waktu, admin, jenis aktivitas), tabel waktu/admin/aktivitas/sasaran/IP/detail.

**Aturan:** **tidak bisa dihapus, tidak bisa diubah.** Hanya dibaca.

**Catatan:** `AdminAuditController` + `AuditLabels` sudah ada.

---

### 4.23 Notifikasi

**Isi:** daftar notifikasi, belum dibaca/dibaca, tandai semua dibaca.

**Catatan:** `AdminNotificationController` sudah ada.

---

## 5. Peran & izin

Dua peran: **Super Admin** dan **Admin**.

| Izin | Arti |
|---|---|
| `dashboard.view` | Dashboard |
| `orders.view` | Pesanan |
| `catalog.manage` | Produk & katalog |
| `content.manage` | Banner & konten |
| `fulfillment.manage` | Antrean manual |
| `providers.manage` | Digiflazz & provider |
| `payments.manage` | Pembayaran operasional |
| `customers.view` | Pelanggan |
| `vouchers.manage` | Promo & voucher |
| `support.manage` | Layanan pelanggan |
| `reports.view` | Laporan |
| `reports.finance` | Laporan finansial |
| `settings.manage` | Pengaturan non-rahasia |
| `notifications.view` | Notifikasi |

**Hanya Super Admin, tanpa izin khusus:** Admin & Akses, Integrasi, Kesehatan Sistem, Catatan Audit, Validasi Akun, **penyesuaian saldo**.

Aturan:

- Izin diperiksa **di server**, bukan hanya menyembunyikan tombol.
- Menu yang tidak boleh diakses tidak muncul.
- Admin tidak bisa menaikkan izinnya sendiri, dan tidak bisa mengubah Super Admin lain.

---

## 6. Aksi yang wajib konfirmasi

| Aksi | Konfirmasi |
|---|---|
| Penyesuaian saldo | Kata sandi + alasan wajib |
| Batalkan pesanan | Alasan wajib |
| Hapus produk/kategori/voucher | Ketik nama untuk konfirmasi |
| Nonaktifkan admin | Kata sandi |
| Ubah kredensial integrasi | Kata sandi |
| Ekspor laporan keuangan | Kata sandi |
| Impor katalog | Pratinjau + setujui |

Semua aksi di atas masuk audit log.

---

## 7. Keadaan kosong & peringatan

Panel harus jujur saat belum ada data:

- Belum ada pesanan → "Belum ada pesanan. Pesanan akan muncul di sini setelah ada pembeli."
- Saldo Digiflazz belum diatur → "Belum tersambung" + tombol ke Integrasi.
- Toko belum pernah transaksi → kartu omzet menampilkan `Rp 0` dengan keterangan "Belum ada transaksi", bukan angka contoh.
- Integrasi mati → peringatan kuning di atas halaman terkait, bukan halaman kosong.

---

## 8. Yang dibuang dari template

Template dipakai sebagai acuan tampilan, **bukan** acuan fitur. Ini yang **tidak** dibuat:

| Di template | Kenapa tidak dibuat |
|---|---|
| Blok "Refund Top Up Perlu Rekonsiliasi" + tombol "Selesaikan refund" | Refund tidak pernah otomatis (PRD §19) |
| Tombol "Selesaikan pesanan" yang mengirim ulang tanpa bukti | Harus ada bukti dan alasan |
| Menu "Manual" sebagai nama | Diganti "Antrean Manual" |
| Istilah "System Health", "Audit Log" | Diganti "Kesehatan Sistem", "Catatan Audit" |
| Tombol dummy tanpa fungsi | Tidak ada tombol yang tidak melakukan apa pun |
| Grafik tanpa angka | Wajib ada angka |

---

## 9. Yang belum diputuskan

1. **Controller: dipertahankan atau ditulis ulang.** Saran: **dipertahankan**. Alamat URL, controller, dan service tidak berubah; yang diganti CSS, layout, dan 27 file Vue. Controller hanya disentuh kalau memang ada yang harus disesuaikan (mis. alasan wajib, penyesuaian saldo khusus Super Admin).
2. **Halaman yang tidak ada di template** (Kategori, Tampilan Toko, Notifikasi, Detail Pesanan, Detail Pelanggan) — dibuat mengikuti gaya baru sekalian, atau dibiarkan dulu.
3. **Urutan kerja:** satu halaman selesai → dilihat → lanjut, atau semua sekaligus.

---

## 10. Definisi selesai (panel)

1. Semua 23 halaman terbuka tanpa error, di desktop dan HP.
2. Warna, huruf, sudut, dan jarak mengikuti §2 — bukan kompromi "mirip".
3. Tidak ada teks dummy, tombol palsu, atau angka contoh.
4. Semua aksi di §6 meminta konfirmasi.
5. Tidak ada tombol refund otomatis di mana pun.
6. Peran diuji: Admin biasa tidak bisa membuka halaman Super Admin, dan tidak bisa menyesuaikan saldo.
7. Tangkapan layar desktop dan HP untuk tiap halaman yang berubah.
