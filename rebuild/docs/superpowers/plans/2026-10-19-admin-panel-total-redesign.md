# Rencana Desain Ulang Total Panel Admin LFAMILIA Store

**Status:** Rancangan awal/arsip. Implementasi UI dan perubahan backend yang terkait katalog/harga telah dicatat dalam commit lokal `9c9ce4e6` di branch `feat/admin-total-redesign`; bagian ini bukan laporan lengkap seluruh pekerjaan. Pernyataan batas “UI saja” di bawah menjelaskan ruang lingkup saat rancangan ditulis dan tidak menggambarkan seluruh perubahan yang akhirnya dibuat. Tidak ada push, PR, merge, atau deployment yang diotorisasi oleh dokumen ini.
**Tanggal:** 19 Oktober 2026

## 1. Tujuan

Membangun ulang pengalaman UI seluruh panel Admin LFAMILIA Store dari awal—struktur halaman, navigasi, hierarki informasi, dan perilaku responsif—bukan sekadar merapikan komponen lama. Tahap ini membahas UI saja. Backend, endpoint, data, aturan bisnis, dan izin yang sekarang tetap dipertahankan; perubahan backend dibahas dan disetujui terpisah.

Cakupan meliputi login Admin dan seluruh menu/halaman Admin, pada desktop dan ponsel. Area akun pelanggan bukan bagian dari perombakan ini.

## 2. Arah visual dan prinsip bersama

- Tema gelap dengan aksen biru, ungu, dan lime mengikuti warna frontend customer LFAMILIA.
- Sentry diterima sebagai referensi visual desktop untuk kepadatan informasi; bukan untuk disalin mentah. Panel Admin tetap punya susunan operasional sendiri.
- Hindari tampilan yang penuh kartu/kotak dan panel-panel dekoratif. Gunakan tabel, baris, pembatas, judul bagian, ruang kosong, dan hirarki tipografi. Kartu dipakai hanya bila membantu: ringkasan angka dashboard, koneksi/saldo Digiflazz, dan konteks lain yang disepakati.
- Desktop boleh padat dan efisien; ponsel harus mudah dibaca dan digunakan, tanpa menumpuk semua kontrol secara sempit.
- Tabel tertentu boleh digeser horizontal di ponsel jika kolom perlu dipertahankan (Pesanan, Fulfillment, SKU Digiflazz, dan tabel lain sesuai kebutuhan).
- Tindakan berisiko ditempatkan dalam konteks detail dan diberi konfirmasi yang jelas.
- Pertahankan kemampuan bisnis, alur data, serta visibilitas berbasis izin yang sekarang kecuali perubahan disetujui secara terpisah.

## 3. Navigasi dan struktur

Sidebar dikelompokkan berdasarkan area kerja, bukan daftar panjang tautan tanpa pengelompokan. Semua menu yang ada tetap tersedia dan mengikuti izin Admin yang berlaku. Desain ulang mencakup shell global, navigasi desktop/mobile, header halaman, keadaan aktif, serta akses ke notifikasi. Nama akhir dan urutan kelompok menu ditetapkan dalam rencana implementasi setelah inventarisasi semua route dan permission.

## 4. Rancangan halaman

### 4.1 Login Admin

Termasuk dalam cakupan desain ulang total. Rancangan visualnya mengikuti tema gelap dan identitas warna frontend customer, tetapi tetap fokus pada proses masuk dan kejelasan validasi. Detail field dan mekanisme login tetap mengikuti perilaku autentikasi yang sudah ada; tidak ada perubahan backend yang diasumsikan.

### 4.2 Dashboard

Urutan informasi:
1. Kartu ringkasan angka yang relevan dengan izin Admin yang sedang masuk.
2. Grafik yang dapat memilih metrik pendapatan atau jumlah pesanan, rentang 7/30/90 hari, default 7 hari.
3. Pratinjau maksimal lima pesanan terbaru, dengan tautan menuju Pesanan.
4. Aktivitas, status integrasi, dan notifikasi belum dibaca secara ringkas—tidak diubah menjadi kumpulan kartu.

Produk terlaris dipindahkan dari dashboard ke Produk. Di ponsel, kartu angka tersusun dua kolom.

### 4.3 Pesanan

- Dahulukan pesanan bermasalah/tertunda yang memerlukan perhatian, lalu urutkan pesanan terbaru.
- Pencarian utama: nomor invoice atau pelanggan. Filter tambahan: status, tanggal, metode pembayaran, dan provider.
- Kolom inti: invoice, pelanggan, produk/target, total, status pembayaran, dan status proses.
- Pertahankan pembuatan pesanan manual dan unduh data.
- Tampilkan ringkasan jumlah “Menunggu pembayaran”, “Perlu diproses”, dan “Perlu perhatian”.
- Di ponsel gunakan tabel geser horizontal, bukan kartu per pesanan.
- Tindakan berisiko dilakukan dari detail pesanan.

### 4.4 Detail Pesanan

Fokus pertama pada status saat ini dan langkah berikut yang perlu dilakukan Admin; setelah itu tampilkan pembayaran dan riwayat. Kelompokkan perubahan status/tindakan dalam area yang jelas dengan peringatan/konfirmasi sebelum perubahan penting.

### 4.5 Produk

Pisahkan daftar Produk dari ruang kerja/edit satu produk; Kategori menjadi menu tersendiri.

**Daftar produk:** nama, kategori, jenis penanganan, jumlah denominasi, status; pencarian dan filter di atas daftar.

**Ruang kerja produk:** informasi dasar dan status di awal, lalu denominasi dan harga. Harga jual, modal, dan sumber ditampilkan berdekatan. Pengaturan markup khusus dibuka hanya saat diperlukan. Tidak menambahkan sumber provider cadangan. Stok voucher hanya berlaku pada produk voucher manual yang memang menggunakannya.

Konfigurasi field input pelanggan (nama, urutan, wajib/tidak) tersedia dalam alur edit. Format teknis provider ditempatkan di pengaturan lanjutan. Pengaturan tampilan storefront memiliki pratinjau untuk gambar/banner/pemberitahuan. Setiap editor denominasi mendukung gambar denominasi tersendiri, bukan hanya gambar produk.

Pengaturan produk populer ditempatkan pada bagian Tampilan Toko di ruang kerja Produk.

### 4.6 Kategori

Menu daftar kategori menampilkan nama, ikon, urutan, dan jumlah produk. Pengeditan bisa dilakukan inline; kemampuan gambar serta status aktif/nonaktif tetap tersedia.

### 4.7 Manual / Fulfillment

Antrean utama mengutamakan item yang membutuhkan tindakan staf. Pekerjaan lain yang hanya dipantau tetap dapat ditemukan melalui filter. Tabel di ponsel dapat digeser horizontal. Membuka item menampilkan detail dan tindakan dalam konteks yang sama; tindakan menyesuaikan status item.

### 4.8 Banner & Konten

Dipecah ke area kerja yang jelas, bukan satu halaman dengan banyak tab yang mencampur tugas:

**Tampilan Toko**
- Daftar banner ringkas dalam bentuk baris.
- Memilih banner membuka editor terpisah dengan pratinjau desktop dan ponsel.
- Pop-up memiliki editor dan pratinjau sendiri.
- Logo, favicon, dan aset toko lain disusun sebagai daftar ringkas, bukan banyak kotak besar.
- Pengaturan produk populer berada di Produk, bukan di Promo.

**Bantuan & Kebijakan**
- FAQ sebagai daftar pertanyaan; pilih/buka item untuk mengedit.
- Setiap kebijakan memiliki editor dan pratinjau tersendiri.

**Berita & Ulasan**
- Berita berupa daftar ringkas dengan editor terpisah.
- Ulasan fokus pada moderasi: informasi rating/produk dan kontrol untuk menyetujui/menampilkan atau menyembunyikan ulasan. Isi ulasan tidak dianggap dapat diedit Admin bila perilaku saat ini memang melarangnya.

### 4.9 Digiflazz

Fokus pada kesehatan SKU dan sinkronisasi harga/stok. Pengaturan harga jual berada di Produk. Fitur tempel “Harga Max” dari Buyer Area tidak dimasukkan ke desain ulang.

Daftar default berisi SKU yang terhubung ke produk; filter memungkinkan pencarian seluruh daftar harga SKU. Baris SKU memperlihatkan produk/denominasi, harga supplier, ketersediaan/stok, dan kesehatan. Aksi sinkronisasi ditempatkan di atas daftar. Daftar SKU berupa tabel yang dapat digeser horizontal di ponsel—bukan kartu. Koneksi dan saldo boleh memakai kartu ringkas.

Penghapusan fitur “Harga Max” pada tahap ini adalah keputusan rancangan UI; tidak dengan sendirinya mengizinkan penghapusan endpoint, data, atau backend.

### 4.10 Provider

Tampilkan satu baris per provider, dengan kesehatan/status koneksi, jenis layanan, jumlah denominasi tertaut, status aktif, dan aksi. Hindari kartu per provider.

### 4.11 Pembayaran

Dua menu: **Pengaturan Pembayaran** dan **Transaksi Pembayaran**.

Pengaturan Pembayaran tetap satu halaman dengan bagian berurutan dan judul tegas:
- Metode pembayaran, termasuk QRIS manual.
- Pengaturan gateway dan biaya (pemilihan/urutan gateway per metode pembayaran).
- Pengaturan top-up: aktif/nonaktif, minimum, dan metode.
- Tampilan halaman checkout/pembayaran.

QRIS manual dibedakan dari QRIS yang disediakan gateway. Riwayat transaksi ada di menu Transaksi Pembayaran.

### 4.12 Pelanggan dan Membership

Daftar Pelanggan berfokus pada pencarian dan informasi pelanggan utama. Pengaturan/penyesuaian membership dan saldo untuk satu pelanggan dilakukan setelah membuka detail; izin Super Admin tetap mengikuti aturan sekarang.

Detail pelanggan adalah satu halaman berurutan berisi profil, pesanan, saldo/top-up, tiket, dan akun game tersimpan—bukan banyak kartu.

Aturan tier membership umum dipindahkan ke bagian Membership dalam area/menu Pelanggan. Detail keanggotaan per individu tetap di detail pelanggan.

### 4.13 Layanan Pelanggan

Panel Admin berfokus pada daftar tiket masuk dan detail tiket untuk ditinjau/dibalas. Jangan menggambarkannya sebagai chat langsung jika fungsi saat ini tidak membuktikan adanya chat langsung.

Perbaikan alur guest order access code dicatat sebagai pekerjaan terpisah setelah panel Admin siap: kode dibuat otomatis sistem saat checkout dan perlu mudah ditemukan pelanggan untuk melihat pesanan/mengakses tiket. Screenshot yang dibahas memperlihatkan teks validasi mentah `validation.required`; tampilnya pesan validasi yang layak juga perlu ditangani dalam pekerjaan lanjutan itu. Tidak ada perubahan kode untuk hal ini dalam tahap desain.

### 4.14 Promo

Menu Promo khusus untuk membuat dan memantau voucher. Pengaturan produk populer ditempatkan di Produk > Tampilan Toko.

### 4.15 Laporan

Satu ruang analisis dengan bagian/pilihan **Penjualan**, **Produk**, dan **Operasional**. Rentang tanggal dan unduh laporan mengikuti bagian yang dipilih. Informasi keuangan tetap hanya terlihat oleh Super Admin sesuai izin yang berlaku.

### 4.16 Admin & Akses

Daftar akun Admin menjadi fokus utama. Memilih akun membuka editor terpisah untuk profil dan izin, bukan formulir panjang di bawah daftar. Peran, izin, dan status akun dipertahankan sesuai perilaku sekarang.

### 4.17 Pengaturan

Pengaturan umum dipecah berdasarkan tujuan:
- **Toko & Kontak** untuk identitas/kontak toko dan informasi merchant yang saat ini tersedia.
- **Ekspor Pengaturan** untuk ekspor konfigurasi.

Aturan tier membership dipindahkan ke Pelanggan > Membership, bukan dikelola ulang di Pengaturan. Pengaturan ini tetap bisa berupa satu menu dengan bagian jelas, bukan harus menjadi banyak menu baru.

### 4.18 Integrasi

Daftar layanan di satu sisi; memilih layanan membuka pengaturan koneksi, lingkungan, tes koneksi, dan URL callback yang relevan. Rahasia yang tersimpan tidak ditampilkan kembali. Perilaku integrasi dan kredensial tidak diubah sebagai bagian desain UI.

### 4.19 Kondisi Sistem

Komponen bermasalah/perlu perhatian tampil paling atas, diikuti daftar status lengkap: aplikasi, database, antrean, job gagal, rekonsiliasi tertunda, integrasi, dan gateway. Halaman hanya membaca status tersimpan; tidak otomatis memicu transaksi atau tes koneksi.

### 4.20 Audit Log

Pencarian dan filter riwayat berdasarkan pelaku, aksi, target, dan tanggal. Perubahan sebelum/sesudah dibuka per baris. Informasi sensitif harus tetap disanitasi.

### 4.21 Notifikasi Admin

Daftar membedakan jelas notifikasi belum dibaca dan sudah dibaca. Memilih notifikasi membuka halaman tujuan terkait jika tersedia.

### 4.22 Validasi Akun

Alat pemeriksaan nickname game, region, dan nomor pelanggan PLN menjadi fungsi utama. Hasil pemeriksaan tampil jelas dekat formulir. Pengaturan/daftar kode game berada di bagian terpisah, mencakup aturan dukungan game serta kebutuhan server/zone, region, status, dan urutan.

## 5. Batasan dan hal yang tidak termasuk

- Tidak mengubah backend, endpoint, model/data, izin, perilaku bisnis, pembayaran, maupun alur transaksi pada tahap desain/perencanaan UI ini.
- Tidak menambah alur refund.
- Tidak menghapus kapabilitas backend hanya karena fitur tertentu tidak ditampilkan di rancangan UI; keputusan fitur UI dan perubahan perilaku backend harus dipisahkan.
- Tidak menggunakan kredensial yang dikirim lewat chat.
- Belum ada keputusan implementasi rinci mengenai susunan final sidebar, komponen bersama, pola modal/drawer, isi persis tiap kolom di layar kecil, dan cakupan uji visual. Ini perlu dijabarkan saat rencana implementasi setelah inventarisasi route/izin dan batas aman pengujian.

## 6. Tahap setelah dokumen ini

1. Tinjau dokumen ini dan koreksi jika ada keputusan yang tidak tepat.
2. Setelah desain disetujui, buat rencana implementasi teknis terpisah: inventaris halaman/route/permission, struktur file dan komponen, urutan pengerjaan bertahap, pengujian, serta validasi tampilan desktop/ponsel.
3. Implementasi baru dimulai setelah rencana tersebut ditinjau dan disetujui secara eksplisit.
4. Perbaikan alur kode akses pesanan guest dikerjakan setelah Admin siap, sebagai pekerjaan tersendiri.
