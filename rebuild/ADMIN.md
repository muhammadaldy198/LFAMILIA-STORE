# Panel admin, peran, dan integrasi

> Aturan produk ada di [`docs/PRD.md`](../docs/PRD.md). Dokumen ini menjelaskan **cara kode bekerja**, bukan aturan produk. Kalau berbeda, PRD yang menang.

Panel admin memakai Laravel + Vue/Inertia. Hak akses selalu diperiksa di server; menu yang tidak tampil bukan satu-satunya pengaman — route-nya sendiri yang menolak.

## Peran

- **SUPER_ADMIN** — semua izin, termasuk operasi khusus super.
- **ADMIN** — hanya izin granular yang diberikan kepadanya.

Tidak ada peran Staff yang aktif. Tingkat membership pelanggan (BASIC sampai MAFIA) bukan peran admin.

Izin granular didefinisikan di `AdminPermissionService` dan mencakup dashboard, pesanan, pengiriman, katalog, konten, provider, pembayaran, pelanggan, voucher, dukungan, laporan, pengaturan, dan notifikasi.

## Menu aktual

Satu-satunya sumber menu adalah `AdminPermissionService::menu()`. Urutan dan kelompoknya:

**Pertama, tanpa kelompok** — Dashboard
**Transaksi** — Pesanan · Pengiriman · Promo
**Katalog** — Produk · Kategori
**Konten** — Banner & Konten · Tampilan Toko
**Integrasi** — Digiflazz · Kode Game *(khusus super)* · Provider
**Keuangan** — Pembayaran · Laporan
**Pelanggan** — Pelanggan · Layanan Pelanggan
**Sistem** — Notifikasi · Template Email & Notifikasi · Integrasi *(khusus super)* · Kesehatan Sistem *(khusus super)* · Catatan Audit *(khusus super)*
**Pengaturan** — Admin & Akses *(khusus super)* · Pengaturan

## Tanggung jawab tiap area

- **Dashboard** — angka operasional, pencarian, dan notifikasi sesuai izin; angka keuangan disaring menurut peran.
- **Pesanan** — daftar dan detail pesanan, riwayat pembayaran dan pengiriman, aksi operasional yang diizinkan.
- **Pengiriman** — pengiriman manual dan aksi pemulihan, tetap lewat service pengaman yang sama.
- **Promo** — voucher dan prioritas tampil di etalase.
- **Produk** — produk, nominal, kolom input pelanggan, media, harga, mapping provider, dan impor.
- **Kategori** — daftar kategori toko beserta ikon, urutan tampil, dan status aktif.
- **Banner & Konten** — aset dan konten etalase; bukan tempat menyimpan secret.
- **Tampilan Toko** — pengaturan tampilan etalase.
- **Digiflazz** — monitor katalog dan sinkronisasi; kredensialnya tetap di Integrasi, bukan di sini.
- **Kode Game** — daftar kode game untuk cek nickname produk lewat H2H.
- **Provider** — registry provider dan kesehatan mapping; bukan editor secret.
- **Pembayaran** — channel, keadaan gateway, maintenance, routing, QRIS Manual, dan kontrol top-up. Kredensial tidak dimasukkan lewat rute pembayaran.
- **Laporan** — angka operasional; keuangan sensitif dibatasi untuk Super Admin.
- **Pelanggan** — profil, riwayat, tingkat membership, dan operasi wallet sesuai peran. Penyesuaian saldo hanya lewat alur resmi yang berledger.
- **Layanan Pelanggan** — tiket, percakapan, dan balasan cepat.
- **Notifikasi** — notifikasi operasional panel.
- **Template Email & Notifikasi** — templat pesan keluar.
- **Integrasi** — kredensial provider/aplikasi terenkripsi dan tes koneksi. Khusus Super Admin.
- **Kesehatan Sistem** — keadaan runtime dan integrasi internal, tanpa membocorkan secret.
- **Catatan Audit** — catatan audit keamanan dan operasional; tidak ada edit/hapus biasa.
- **Admin & Akses** — akun admin dan izinnya, dengan pengaman anti-terkunci (self-lockout dan pemilik terakhir).
- **Pengaturan** — pengaturan toko dan bisnis yang bukan secret.

## Integrasi dan data sensitif

Profil yang didukung kode dan panel: Digiflazz, validasi nickname, Midtrans, DOKU, Resend, Google OAuth, Telegram, Discord, dan Turnstile.

Kredensial disimpan di `integration_credentials.config_ciphertext` dengan encrypted array cast. Props Inertia biasa hanya boleh membawa status dan konfigurasi non-secret. Menampilkan secret mentah memerlukan kontrol Super Admin dan tercatat di audit.

**Tes koneksi yang berhasil atau profil yang tersimpan bukan bukti transaksi live ke provider sudah lolos.**

Jangan menaruh kata sandi, kredensial API, token privat, tanda tangan pembayaran, secret game tersimpan, atau ciphertext di muatan audit atau UI biasa. Ekspor konfigurasi normal wajib mengecualikan secret.

## Tampilan panel

Sistem desain admin hidup di `resources/css/admin.css` dan hanya berlaku di shell Admin. Sidebar desktop selebar 216 px, topbar 52 px. Tabel memakai komponen `AdminResponsiveTable` dengan slot dan handler yang sama di semua lebar. Tabel katalog, hasil impor, dan daftar nominal dirender utuh dengan lebar minimum dan menggulir horizontal di dalam wadahnya — bukan berubah jadi kartu bertumpuk — supaya kolom teknis (modal toko, harga seller, markup) tetap terbaca. Pilihan jamak dan konfirmasi memakai checkbox; nilai boolean memakai `AdminSwitch`.

Halaman kerja produk memakai URL `/admin/catalog?edit=<id>`; daftar produk tertutup selama editor terbuka. Lima tab editor: Informasi, Nominal & Harga, Sumber, Tampilan Produk, Data Pelanggan. Tab Sumber berisi daftar mapping (modal toko yang bisa diedit, aktif/nonaktif, prioritas, sinkron per mapping) dan saklar sinkron otomatis seluruh katalog. Nominal dirender 25 per halaman dan form detail hanya untuk nominal terpilih. Daftar produk dipaginasi di server, 25 per halaman — peramban hanya menerima metadata paginasi, bukan seluruh katalog.

Halaman Pembayaran memisahkan Channel, Gateway, Routing, QRIS Manual, Top Up Saldo, Tampilan Halaman, dan Transaksi. Detail pelanggan memisahkan Profil, Tier, Pesanan, Saldo & Top Up, Tiket, dan Akun Game. Semua permintaan, pemeriksaan hak, konfirmasi saldo, ledger, status integrasi, dan pelestarian kredensial tetap lewat backend yang ada.

Media memakai endpoint Spatie yang ada. Gambar terpilih bisa dipratinjau sebelum diunggah, galat unggahan tampil di tempat, dan penghapusan meminta konfirmasi. Mengunggah favicon otomatis mengaktifkan aset itu dan memperbarui favicon Admin dan etalase.

Riwayat audit UI lama tersimpan di `docs/archive/2026-10-22-pra-redesign/2026-10-04-audit-ui-admin-lama.md` (arsip, bukan aturan). Spek panel ada di `../docs/ADMIN-PANEL.md`.

## Digiflazz: sinkronisasi katalog

Panel memakai daftar harga buyer dari API Digiflazz (bukan scraping marketplace publik): https://developer.digiflazz.com/api/buyer/daftar-harga/

- Sinkron lengkap yang berhasil memperbarui harga dan status.
- SKU yang hilang dari daftar ditangani menurut riwayatnya: mapping yang pernah dirujuk pesanan atau pengiriman **dipertahankan tetapi dinonaktifkan** oleh sync (`disabled_by_sync=true`); mapping tanpa riwayat order/pengiriman **dihapus**. Snapshot pesanan dan pengiriman lama tidak pernah diubah.
- Nominal yang kehilangan semua sumber aktifnya ikut dinonaktifkan oleh sync (`product_packages.disabled_by_sync=true`) supaya panel dan toko sama-sama berhenti menawarkannya.
- Bila SKU historis muncul lagi, hanya mapping dan nominal yang dinonaktifkan **oleh sync** yang otomatis aktif kembali. Penonaktifan manual oleh admin tidak diubah sync.
- Respons gagal, format tidak valid, atau daftar lengkap yang kosong tidak menghapus data tersimpan. Sinkron satu SKU tidak menyentuh SKU lain. Pesan hasil sync menyebutkan berapa mapping historis yang dibuat tidak tersedia dan berapa mapping tanpa riwayat yang dibersihkan.
- Interval sinkron otomatis diatur di Pengaturan (minimal 5 menit). Halaman menampilkan waktu sinkron lengkap terakhir dan memuat ulang data tersimpan tiap 30 detik saat terlihat — muat ulang ini tidak memanggil API Digiflazz. Pembaruan API manual memakai tombol Sinkron daftar harga.
- Filter Normal/Peringatan/Kritis memakai aritmetika desimal pada kolom harga unsigned supaya harga yang turun tidak menimbulkan galat MySQL.

## Digiflazz: harga jual per nominal

Harga Digiflazz mengikuti harga seller dari SKU yang dipilih. Tidak ada harga modal toko terpisah untuk perhitungan checkout Digiflazz.

- Setiap **nominal** punya satu aturan tambah harga sendiri: **persentase (%)** atau **tambahan rupiah (Rp)**. Aturan ini hidup di tingkat nominal saja — tidak ada markup Digiflazz di tingkat produk/game, dan tidak ada harga jual tetap Digiflazz.
- Harga jual = harga seller SKU terkini + tambahan harga nominal. Persen dibulatkan ke atas ke rupiah terdekat.
- Nominal baru hasil impor mulai dari 10%. Sinkronisasi seller tidak mengubah aturan markup, nama nominal, atau snapshot harga pesanan yang sudah dibuat.
- Produk manual dan voucher lokal memakai perhitungan modal/harga sendiri yang terpisah. Pengaturan markup global hanya berlaku untuk jalur manual/voucher, bukan Digiflazz. Kolom akuntansi lama pada mapping tetap disimpan untuk kompatibilitas dan audit, bukan sebagai tambahan biaya Digiflazz.

## Impor nominal

Pemilih SKU menyediakan pilihan **Langsung jual setelah impor** (bawaan: aktif). Satu kali submit mengimpor SKU yang tersedia dan segar, menyimpan markup dan format ID tujuan, lalu mengaktifkan nominal, sumber Digiflazz, dan produk. Kategori dan integrasi Digiflazz harus sudah aktif lebih dulu — impor tidak mengaktifkannya diam-diam. Matikan pilihan ini untuk menyimpan sebagai draf.

- Impor tidak mengubah markup nominal yang sudah ada. Pratinjau impor menampilkan harga seller → harga jual dengan aturan efektif nominal itu.
- Impor mengelompokkan SKU beridentitas sama (kategori, merek, jenis, nama produk — dinormalisasi huruf dan spasi). SKU, harga, dan seller bukan identitas nominal. Varian, bonus, dan wilayah yang namanya berbeda tetap terpisah. SKU milik produk lain tidak dipindahkan.
- Nilai nominal angka dibaca dari nama katalog. Urutan awal impor dan halaman pelanggan memakai urutan angka menaik (7, 10, 20, 100, 200, 2.000); nama tanpa angka memakai urutan natural. Urutan manual di editor tetap bisa disesuaikan admin.
- Format tujuan diisi dari konfigurasi sumber yang ada, satu kolom pelanggan, atau format Mobile Legends yang dikenal (User ID diikuti Zone ID). Produk multi-kolom lain wajib menetapkan format di pemilih SKU. Backend menolak placeholder asing, format kosong, dan kolom wajib yang terlewat. Tidak ada permintaan pembayaran atau top-up provider saat impor.

## Produk manual dengan banyak nominal

Tab **Produk Manual** membuat satu produk beserta banyak baris nominal sekaligus. Tiap baris punya nama, nilai nominal (opsional), modal toko sendiri, dan markup baris opsional dengan pratinjau harga jual (modal toko × markup yang berlaku). Modal yang diketik admin dipakai apa adanya — kenaikan harga impor provider tidak berlaku untuk input manual.

Nominal hasil formulir dibuat **nonaktif** dulu, dengan modal tercatat pada provider `MANUAL` tanpa SKU eksternal, jadi tidak menyentuh Digiflazz dan alur pengiriman manual tetap sama; admin mengaktifkannya seperti biasa. Kode nominal dibuat otomatis dari nama bila kosong dan dijamin unik dalam produk. Baris cacat (nama kosong, modal negatif) atau nominal kembar dalam satu formulir menolak seluruh formulir tanpa menyisakan produk setengah jadi. Produk otomatis tidak memakai formulir ini — nominalnya datang dari impor. Kolom input pelanggan ditetapkan sekali di tingkat produk dan dipakai bersama semua nominal.

## Kebijakan SKU dan status pengiriman

- Katalog Digiflazz yang dijual memakai satu SKU terpilih per nominal (PRD v1.1 §5–6). Pilihan seller dan perubahan SKU dikelola admin; tidak ada pemilihan ulang otomatis dari sisi pelanggan.
- Pengiriman tidak boleh mengirim ulang atau berpindah SKU selama hasil transaksi provider masih `pending`, `processing`, `timeout`, atau `unknown`. Tunggu rekonsiliasi memastikan status transaksi pertama sebelum tindakan lanjutan. Kegagalan definitif ditangani lewat alur operasional Admin/CS.
- Format ID tujuan tetap tersimpan saat pengaturan mapping diedit.

## Status integrasi dan panduan mulai jualan

Hasil Tes Koneksi terakhir tidak kedaluwarsa sesudah 15 menit; waktunya tetap ditampilkan, tetapi hasil tersimpan bukan pemantauan live. Mengubah kredensial atau environment membatalkan hasil sebelumnya. Kegagalan, konfigurasi tidak lengkap, maintenance, dan timestamp tidak valid ditampilkan apa adanya.

Integrasi tanpa probe aman (`SAFE_PROBE_UNAVAILABLE`) atau dengan izin baca terbatas (`PERMISSION_LIMITED`) ditampilkan sebagai tersimpan dan belum terverifikasi — bukan kegagalan koneksi — dan tidak masuk hitungan masalah Kesehatan Sistem. Tidak ada klaim tes transaksi sudah lulus di panel.

Panduan mulai jualan: hubungkan Digiflazz dan satu metode pembayaran, impor nominal, lalu atur **markup harga jual per nominal**. Telegram, Discord, Google OAuth, dan validasi nama akun adalah tambahan. Status panel tidak mengubah kelayakan checkout, callback, pembayaran, atau pengiriman.
