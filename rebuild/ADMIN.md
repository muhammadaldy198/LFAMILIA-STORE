# Admin panel, RBAC & integrations

> Aturan produk ada di [`docs/PRD.md`](../docs/PRD.md). Dokumen ini menjelaskan **cara kode bekerja**, bukan aturan produk. Kalau berbeda, PRD yang menang.

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
4. Kategori
5. Manual
6. Banner & Konten
7. Digiflazz
8. Validasi Akun
9. Provider
10. Pembayaran
11. Pelanggan
12. Promo
13. Layanan Pelanggan
14. Laporan
15. Admin & Akses
16. Pengaturan
17. Integrasi
18. System Health
19. Audit Log

`Validasi Akun`, `Admin & Akses`, `Integrasi`, `System Health`, dan `Audit Log` bersifat super-only pada menu service. Menu lain tunduk pada permission granular.

## Area dan boundary

- **Dashboard** — operational metrics/search/notifications sesuai permission; finance disaring berdasarkan role.
- **Pesanan** — list/detail, payment/fulfillment history, aksi operasional yang diizinkan.
- **Produk** — produk, nominal, input field, media, pricing, provider mapping/import.
- **Kategori** — daftar kategori toko beserta ikon, urutan tampil, dan status aktifnya.
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

Design system Admin berada di `resources/css/admin.css` dan dibatasi pada shell Admin. Kontrol mobile 36 px, desktop 40 px; sidebar desktop 240 px, topbar 56 px. `AdminResponsiveTable` memakai slot/handler tabel yang sama. Workspace lain memakai kolom prioritas eksplisit di mobile dengan informasi sekunder di Detail. Katalog produk, hasil impor, dan daftar nominal memakai prop `scroll`: tabel aslinya tetap dirender di semua lebar dengan `min-w` dan menggulir horizontal di dalam container tabel, bukan berubah menjadi kartu bertumpuk. Halaman bisa digulir horizontal ke kanan untuk kolom teknis (modal toko, harga seller, markup). Checkbox tetap untuk pilihan jamak/konfirmasi; boolean memakai `AdminSwitch`.

Produk menggunakan workspace penuh dengan URL `/admin/catalog?edit=<id>`. Daftar ditutup selama editor aktif. Lima tab: Informasi, Nominal & Harga, Sumber, Tampilan Produk, Data Pelanggan. Tab Sumber memuat daftar mapping (modal toko yang bisa diedit, aktif/nonaktif, prioritas, sinkron per mapping) dan saklar sinkron otomatis seluruh katalog. Nominal dirender 25 per halaman dan form detail hanya untuk nominal terpilih. Reorder desktop dan tombol naik/turun tetap tersedia. Daftar produk dipaginasi di server (25 per halaman); yang dikirim ke peramban hanya metadata paginasi, bukan seluruh baris katalog.

Pembayaran memisahkan Channel, Gateway, Routing, QRIS Manual, Top Up Saldo, Tampilan Halaman, dan Transaksi. Detail pelanggan memisahkan Profil, Tier, Pesanan, Saldo & Top Up, Tiket, serta Akun Game. Semua request, authorization, konfirmasi saldo, ledger, status integrasi dan credential preservation tetap melalui backend existing.

Media menggunakan endpoint Spatie existing. Gambar terpilih dapat dipratinjau sebelum unggah, error unggahan tampil di tempat, dan hapus meminta konfirmasi. Upload favicon otomatis mengaktifkan asset dan memperbarui link favicon Admin/storefront melalui shared props existing.

Audit source/history dan rencana komponen dicatat di `docs/archive/2026-10-22-pra-redesign/2026-10-04-audit-ui-admin-lama.md` (arsip — panel admin akan dibangun ulang, lihat `../docs/ADMIN-PANEL.md`).

## Digiflazz: sinkronisasi dan kesegaran katalog

Panel memakai daftar harga buyer dari API Digiflazz. Sinkron lengkap yang berhasil memperbarui harga/status. SKU yang hilang ditangani menurut riwayat: mapping yang direferensikan order atau fulfillment dipertahankan tetapi dinonaktifkan oleh sync (`disabled_by_sync=true`), katalog historis ditandai tidak hadir, dan mapping tanpa riwayat order/fulfillment dihapus. Snapshot/order dan fulfillment yang sudah tersimpan tetap dipertahankan. Nominal yang tidak lagi punya satu pun sumber aktif ikut dinonaktifkan oleh sync (`product_packages.disabled_by_sync=true`) supaya panel dan toko sama-sama tidak menawarkannya. Jika SKU historis kembali, hanya mapping dan nominal yang dinonaktifkan oleh sync yang dapat aktif kembali otomatis; penonaktifan manual admin tidak diubah.

Respons gagal, format tidak valid, dan daftar lengkap kosong tidak menghapus data tersimpan. Sinkron satu SKU tidak menghapus SKU lain; respons kosong yang valid hanya memperbarui katalog dan mapping untuk SKU tersebut memakai aturan riwayat yang sama. Pesan hasil sync menampilkan jumlah mapping historis yang dibuat tidak tersedia dan jumlah mapping tanpa riwayat yang dibersihkan.

Interval API diatur pada Pengaturan monitor & sinkron otomatis (minimal 5 menit). Halaman menampilkan waktu sinkron lengkap dan otomatis memuat ulang data tersimpan setiap 30 detik saat terlihat. Muat ulang layar tidak memanggil daftar harga API. Gunakan Sinkron daftar harga untuk pembaruan API manual. Katalog mengikuti respons API akun buyer, bukan scraping marketplace publik. Dokumentasi Digiflazz: https://developer.digiflazz.com/api/buyer/daftar-harga/.

Filter Normal/Peringatan/Kritis memakai aritmetika desimal pada kolom harga unsigned agar harga turun tidak menyebabkan error MySQL.

## Harga Digiflazz: harga seller → harga jual per nominal

Harga Digiflazz mengikuti harga seller SKU yang dipilih, tanpa harga modal toko terpisah untuk perhitungan checkout. Setiap **nominal** memiliki satu aturan tambah harga sendiri: **persentase (%)** atau **tambahan rupiah (Rp)**. Bawaan nominal baru hasil impor adalah 12%; pengaturan ini tidak ada di tingkat produk/game.

Harga jual = harga seller SKU terkini + tambahan harga nominal. Persen dibulatkan ke atas ke satu rupiah. Sinkronisasi seller tidak mengubah aturan markup nominal, nama nominal, maupun snapshot harga pesanan yang sudah dibuat. SKU terpilih tetap satu per nominal, tanpa fallback.

Produk manual dan voucher lokal menggunakan perhitungan modal/harga terpisah yang sudah ada. Pengaturan markup global hanya berlaku untuk jalur manual/voucher, bukan Digiflazz. Kolom akuntansi lama yang disimpan pada mapping tetap digunakan untuk kompatibilitas dan audit, bukan sebagai tambahan biaya Digiflazz.

## Impor nominal langsung untuk dijual

Pemilih SKU menyediakan **Langsung jual setelah impor** (aktif secara default). Satu submit mengimpor SKU yang tersedia dan masih segar, menyimpan margin serta format ID tujuan, dan mengaktifkan nominal, sumber Digiflazz, serta produk. Kategori dan integrasi Digiflazz harus sudah aktif; pengaturan tersebut tidak diaktifkan diam-diam. Nonaktifkan pilihan ini untuk menyimpan draf.

Impor tidak mengubah markup nominal yang sudah ada. Nominal baru mendapatkan markup awal 12% atas harga seller; admin dapat mengubah setiap nominal menjadi persentase lain atau tambahan Rp. Pratinjau impor menampilkan harga seller → harga jual dengan aturan efektif nominal tersebut. Tidak ada pengaturan markup Digiflazz di formulir produk dan tidak ada harga jual tetap Digiflazz.

Format tujuan diisi dari konfigurasi sumber existing, satu kolom pelanggan, atau format Mobile Legends yang dikenal (User ID diikuti Zone ID). Produk lain dengan beberapa kolom harus menetapkan format di pemilih SKU. Backend menolak placeholder asing, format kosong, dan kolom wajib yang terlewat. Tidak ada request pembayaran atau topup provider saat impor.

## Produk manual dengan banyak nominal

Tab **Produk Manual** menyediakan satu formulir yang bisa membuat produk sekaligus **banyak baris nominal**: tiap baris punya nama, nilai nominal (opsional), modal toko sendiri, dan markup baris opsional dengan pratinjau harga jual (modal toko × markup yang berlaku). Modal toko yang diketik admin dipakai apa adanya — uplift hanya berlaku pada impor provider, bukan pada input manual.

Nominal hasil formulir dibuat **nonaktif** dulu bersama pencatatan modal pada provider `MANUAL` (tanpa external SKU), sehingga tidak menyentuh Digiflazz dan alur fulfillment manual tetap sama; admin mengaktifkan nominal dan sumbernya seperti biasa. Kode nominal dibuat otomatis dari nama bila tidak diisi dan dijamin unik dalam produk. Baris nominal satu formulir hanya untuk produk manual: produk otomatis mengambil nominal dari impor. Baris cacat (nama kosong, modal negatif) dan nominal kembar dalam satu formulir ditolak seluruhnya tanpa menyisakan produk setengah jadi.

Field input pelanggan tetap ditetapkan sekali di tingkat game/produk dan dipakai bersama semua nominal.

## Urutan nominal dan kebijakan SKU Digiflazz

Impor mengelompokkan SKU dengan identitas kategori, merek, jenis, dan nama produk yang sama. Nama dinormalisasi huruf besar/kecil dan spasi; SKU, harga, serta seller bukan identitas nominal. Varian, bonus, dan wilayah dengan nama/jenis berbeda tetap terpisah. Pilih satu nominal untuk mengimpor sumber yang tersedia dan segar dalam kelompoknya; nominal yang sudah ada digunakan kembali. SKU yang dimiliki produk lain tidak dipindahkan.

**Kebijakan produk (PRD v1.1 §5–6):** katalog Digiflazz yang dijual menggunakan satu SKU terpilih per nominal; seller selection dan perubahan SKU dikelola admin, bukan otomatis dipilih ulang dari sisi customer. Fulfillment tidak boleh mengirim ulang atau berpindah ke SKU lain ketika hasil transaksi provider masih `pending`, `processing`, `timeout`, atau `unknown`; tunggu rekonsiliasi untuk memastikan status transaksi pertama sebelum tindakan lanjutan. Kegagalan definitif ditangani melalui alur operasional Admin/CS yang tersedia. Sinkronisasi dapat mengaktifkan kembali mapping yang sebelumnya dinonaktifkan oleh sync bila SKU muncul lagi; ini berbeda dari penonaktifan manual Admin. Perubahan dan pengaktifan mapping tetap mengikuti kontrol Admin yang berlaku. Format ID tujuan tetap disimpan saat pengaturan mapping diedit.

Nilai nominal angka diisi dari nama katalog pada impor. Urutan awal impor dan halaman customer memakai angka dari kecil ke besar (7, 10, 20, 100, 200, 2.000). Nama tanpa nilai angka memakai natural sort. Urutan manual editor tetap dapat disesuaikan, tetapi impor/pelengkapan ulang menyusun urutan awal menurut nominal. Halaman customer selalu memakai urutan nominal angka.

## Status integrasi dan alur mulai jualan

Hasil Tes Koneksi terakhir tidak kedaluwarsa setelah 15 menit. Waktu tes tetap ditampilkan; hasil tersimpan bukan pemantauan koneksi live. Perubahan kredensial/environment tetap membatalkan hasil sebelumnya. Kegagalan, konfigurasi tidak lengkap, maintenance, dan timestamp tidak valid tetap ditampilkan sesuai kondisi.

Integrasi tanpa probe aman (SAFE_PROBE_UNAVAILABLE) atau dengan izin baca terbatas (PERMISSION_LIMITED) ditampilkan sebagai tersimpan dan belum terverifikasi, bukan kegagalan koneksi. Kondisi ini tidak masuk hitungan masalah System Health. Tidak ada klaim tes transaksi sudah lulus; teks milestone E2E statis dihapus dari panel.

Panduan mulai jualan: hubungkan Digiflazz dan satu metode pembayaran, impor nominal, lalu atur **markup harga jual** (dan tambahan modal bila perlu) di tingkat global, game, atau nominal. Telegram, Discord, Google OAuth dan validasi nama akun merupakan fitur tambahan. Status panel tidak mengubah eligibility checkout, callback, pembayaran, atau fulfillment.
