# Admin panel, RBAC & integrations

Admin panel menggunakan Laravel + Vue/Inertia. Authorization selalu server-side; menu visibility hanya presentasi.

## Role

- **SUPER_ADMIN** — seluruh permission dan operasi super-only.
- **ADMIN** — hanya permission granular yang diberikan.

Role Staff tidak aktif. Customer membership BASIC–MAFIA bukan Admin role.

Permission yang didefinisikan service mencakup dashboard, orders, catalog, content, fulfillment, providers, payments, customers, vouchers, support, reports, settings, dan notifications sesuai kebutuhan route.

## Menu aktual

Menu dari `AdminPermissionService`:

1. Dashboard
2. Pesanan
3. Produk
4. Manual
5. Banner & Konten
6. Digiflazz
7. Validasi Akun
8. Provider
9. Pembayaran
10. Pelanggan
11. Promo
12. Layanan Pelanggan
13. Laporan
14. Admin & Akses
15. Pengaturan
16. Integrasi
17. System Health
18. Audit Log

`Validasi Akun`, `Admin & Akses`, `Integrasi`, `System Health`, dan `Audit Log` bersifat super-only pada menu service. Menu lain tunduk pada permission granular.

## Area dan boundary

- **Dashboard** — operational metrics/search/notifications sesuai permission; finance disaring berdasarkan role.
- **Pesanan** — list/detail, payment/fulfillment history, aksi operasional yang diizinkan.
- **Produk** — kategori, produk, nominal, input field, media, pricing, provider mapping/import.
- **Manual** — manual fulfillment dan recovery action yang tetap memakai safety service.
- **Banner & Konten** — storefront assets/content; bukan tempat secret.
- **Digiflazz** — catalog/operational monitor/sync; credential tetap di Integrasi.
- **Validasi Akun** — nickname/game code/checker tooling.
- **Provider** — registry dan mapping/health operasional; tidak menggandakan secret editor.
- **Pembayaran** — channel, gateway state, maintenance, routing, Manual QRIS, top-up control; credential tidak boleh dimasukkan ke route.
- **Pelanggan** — profile/history/tier/wallet operations sesuai role; adjustment saldo hanya flow yang diotorisasi dan memakai ledger.
- **Promo** — voucher dan storefront priority yang memang diimplementasikan.
- **Layanan Pelanggan** — tiket, conversation, quick reply/operational actions.
- **Laporan** — operational metrics; finance sensitif dibatasi Super Admin.
- **Admin & Akses** — akun Admin, role/permission, self-lockout/last-owner protection.
- **Pengaturan** — store/business setting non-secret.
- **Integrasi** — encrypted provider/application credential dan test connection; Super Admin.
- **System Health** — internal runtime/integration state tanpa membocorkan secret.
- **Audit Log** — security/operational audit; tidak menyediakan edit/delete log normal.

## Integrasi

Profil kode/panel yang ditemukan mencakup Digiflazz, KokinPay/nickname, Midtrans, DOKU, Resend, Google OAuth, Telegram, Discord, dan Turnstile.

Credential disimpan pada `integration_credentials.config_ciphertext` dengan encrypted array cast. Normal Inertia props hanya boleh menerima status/config non-secret. Reveal secret memerlukan kontrol Super Admin yang diterapkan dan diaudit.

**Tes koneksi atau keberadaan profil bukan bukti live transaction provider telah lolos.**

## Data sensitif

Jangan menaruh password, API credential, private token, payment signature, raw saved-game secret, atau ciphertext di audit payload/UI biasa. Export configuration normal harus mengecualikan secret.

## UI Admin dan workspace produk

Design system Admin berada di `resources/css/admin.css` dan dibatasi pada shell Admin. Kontrol mobile 36 px, desktop 40 px; sidebar desktop 240 px, topbar 56 px. `AdminResponsiveTable` memakai slot/handler tabel yang sama, dengan kolom prioritas eksplisit di mobile dan informasi sekunder dalam Detail. Checkbox tetap untuk pilihan jamak/konfirmasi; boolean memakai `AdminSwitch`.

Produk menggunakan workspace penuh dengan URL `/admin/catalog?edit=<id>`. Daftar ditutup selama editor aktif. Lima tab: Informasi, Nominal & Harga, Tampilan Produk, Data Pelanggan, Penanganan. Nominal dirender 25 per halaman dan form detail hanya untuk nominal terpilih. Reorder desktop dan tombol naik/turun tetap tersedia. Backend catalog masih mengirim seluruh catalog sebagaimana sebelumnya; pagination server catalog adalah pekerjaan terpisah.

Pembayaran memisahkan Channel, Gateway, Routing, QRIS Manual, Top Up Saldo, Tampilan Halaman, dan Transaksi. Detail pelanggan memisahkan Profil, Tier, Pesanan, Saldo & Top Up, Tiket, serta Akun Game. Semua request, authorization, konfirmasi saldo, ledger, status integrasi dan credential preservation tetap melalui backend existing.

Media menggunakan endpoint Spatie existing. Gambar terpilih dapat dipratinjau sebelum unggah, error unggahan tampil di tempat, dan hapus meminta konfirmasi. Upload favicon otomatis mengaktifkan asset dan memperbarui link favicon Admin/storefront melalui shared props existing.

Audit source/history dan rencana komponen dicatat di `ADMIN-UI-AUDIT.md`.

## Digiflazz: sinkronisasi dan kesegaran katalog

Panel memakai daftar harga buyer dari API Digiflazz. Sinkron lengkap yang berhasil memperbarui harga/status dan otomatis menghapus baris katalog untuk SKU yang tidak ada dalam respons. Mapping SKU yang hilang dinonaktifkan; mapping, nominal lokal, dan snapshot pesanan tetap disimpan untuk menjaga riwayat transaksi. SKU yang kembali muncul tidak otomatis mengaktifkan mapping lama.

Respons gagal, format tidak valid, dan daftar lengkap kosong tidak menghapus data tersimpan. Sinkron satu SKU tidak menghapus SKU lain; respons kosong yang valid untuk SKU tersebut menghapus hanya SKU itu dan menonaktifkan mapping-nya.

Interval API diatur pada Pengaturan monitor & sinkron otomatis (minimal 5 menit). Halaman menampilkan waktu sinkron lengkap dan otomatis memuat ulang data tersimpan setiap 30 detik saat terlihat. Muat ulang layar tidak memanggil daftar harga API. Gunakan Sinkron daftar harga untuk pembaruan API manual. Katalog mengikuti respons API akun buyer, bukan scraping marketplace publik. Dokumentasi Digiflazz: https://developer.digiflazz.com/api/buyer/daftar-harga/.

Filter Normal/Peringatan/Kritis memakai aritmetika desimal pada kolom harga unsigned agar harga turun tidak menyebabkan error MySQL.

## Impor nominal langsung untuk dijual

Pemilih SKU menyediakan **Langsung jual setelah impor** (aktif secara default). Satu submit mengimpor SKU yang tersedia dan masih segar, menyimpan margin serta format ID tujuan, dan mengaktifkan nominal, sumber Digiflazz, serta produk. Kategori dan integrasi Digiflazz harus sudah aktif; pengaturan tersebut tidak diaktifkan diam-diam. Nonaktifkan pilihan ini untuk menyimpan draf.

Format tujuan diisi dari konfigurasi sumber existing, satu kolom pelanggan, atau format Mobile Legends yang dikenal (User ID diikuti Zone ID). Produk lain dengan beberapa kolom harus menetapkan format di pemilih SKU. Backend menolak placeholder asing, format kosong, dan kolom wajib yang terlewat. Tidak ada request pembayaran atau topup provider saat impor.

## Urutan nominal dan cadangan Digiflazz otomatis

Impor mengelompokkan SKU dengan identitas kategori, merek, jenis, dan nama produk yang sama. Nama dinormalisasi huruf besar/kecil dan spasi; SKU, harga, serta seller bukan identitas nominal. Varian, bonus, dan wilayah dengan nama/jenis berbeda tetap terpisah. Pilih satu nominal untuk mengimpor seluruh sumber yang tersedia dan segar dalam kelompoknya; nominal yang sudah ada digunakan kembali. SKU yang dimiliki produk lain tidak dipindahkan.

Cadangan otomatis aktif secara default pada impor. Tombol **Pilih semua hasil filter** mencakup seluruh hasil, bukan hanya halaman terlihat; satu request menerima hingga 2.000 pilihan. Tombol **Lengkapi cadangan otomatis** melengkapi semua nominal produk existing sekaligus. Sinkron lengkap berikutnya menambahkan sumber baru pada kelompok yang telah diaktifkan. Mapping lama yang dinonaktifkan atau SKU yang hilang tidak otomatis diaktifkan kembali oleh sinkron. Format ID tujuan tetap disimpan saat pengaturan mapping diedit.

Sumber otomatis berprioritas sama; resolver existing memilih biaya terendah yang tersedia, lalu ID. Cadangan tetap tunduk pada batas harga snapshot dan aturan pending/unknown/failure; fitur ini tidak mengirim transaksi atau mengubah aturan failover.

Nilai nominal angka diisi dari nama katalog pada impor. Urutan awal impor dan halaman customer memakai angka dari kecil ke besar (7, 10, 20, 100, 200, 2.000). Nama tanpa nilai angka memakai natural sort. Urutan manual editor tetap dapat disesuaikan, tetapi impor/pelengkapan ulang menyusun urutan awal menurut nominal. Halaman customer selalu memakai urutan nominal angka.

## Status integrasi dan alur mulai jualan

Hasil Tes Koneksi terakhir tidak kedaluwarsa setelah 15 menit. Waktu tes tetap ditampilkan; hasil tersimpan bukan pemantauan koneksi live. Perubahan kredensial/environment tetap membatalkan hasil sebelumnya. Kegagalan, konfigurasi tidak lengkap, maintenance, dan timestamp tidak valid tetap ditampilkan sesuai kondisi.

Integrasi tanpa probe aman (SAFE_PROBE_UNAVAILABLE) atau dengan izin baca terbatas (PERMISSION_LIMITED) ditampilkan sebagai tersimpan dan belum terverifikasi, bukan kegagalan koneksi. Kondisi ini tidak masuk hitungan masalah System Health. Tidak ada klaim tes transaksi sudah lulus; teks milestone E2E statis dihapus dari panel.

Panduan mulai jualan: hubungkan Digiflazz dan satu metode pembayaran, impor nominal, atur margin. Telegram, Discord, Google OAuth dan validasi nama akun merupakan fitur tambahan. Status panel tidak mengubah eligibility checkout, callback, pembayaran, atau fulfillment.
