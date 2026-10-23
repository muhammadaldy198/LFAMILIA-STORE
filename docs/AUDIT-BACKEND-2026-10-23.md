# Audit Backend Panel Admin — 23 Oktober 2026

Dokumen ini dibuat supaya kamu (pemilik toko, bukan programmer) bisa menilai sendiri
apa yang saya temukan dan memutuskan apa yang dibuang atau dibiarkan.

Sampai sekarang **belum ada satu pun yang dihapus**. Ini daftar periksa, bukan laporan kerja.

---

## 1. Arti istilah di dokumen ini

| Istilah | Arti awam |
|---|---|
| Alamat (route) | Alamat di browser, misal `/admin/pelanggan`. Tiap alamat punya satu pintu. |
| Petugas (controller) | "Koki" yang mengerjakan permintaan alamat itu. |
| Pintu (endpoint) | Alamat yang menerima data kiriman dari halaman, misal menyimpan perubahan. |
| Data kiriman (prop) | Barang yang dikirim server ke halaman: daftar pesanan, ringkasan angka, dll. |
| Halaman (Vue) | Tampilan yang kamu lihat di panel. |
| Komponen | Potongan tampilan yang dipakai ulang banyak halaman (misal jendela pop-up). |

Jadi alurnya: **kamu buka alamat → petugas menyiapkan data → halaman menampilkan.**

---

## 2. Cara saya memeriksa (supaya bisa dipercaya)

1. Saya minta aplikasi menuliskan sendiri seluruh alamat yang benar-benar hidup
   (perintah `route:list` dari Laravel), bukan membaca file dengan mata. Hasilnya:
   **140 alamat admin**.
2. Tiap alamat saya cocokkan dengan petugasnya. Hasil: **27 petugas admin, semuanya masih dipakai.**
3. Saya cocokkan data yang dikirim server dengan data yang diminta tiap halaman.
   Hasil: **tidak ada satu pun data yang diminta halaman tapi belum dikirim server.**
4. Saya cari file tampilan dan komponen yang tidak lagi diimpor halaman mana pun.
5. Saya cari pintu (endpoint) yang tidak dipanggil halaman mana pun.

Catatan penting: pengecekan nomor 5 pernah hampir salah. Empat petugas seperti
"Dashboard" dan "Detail Pesanan" alamatnya tidak menyebut nama fungsi, jadi alat
pencari saya sempat menganggapnya mati. Setelah diperiksa ulang, keempatnya hidup.
Saya tulis ini supaya kamu tahu batas ketelitiannya, bukan supaya kamu percaya buta.

---

## 3. Ringkasan: apa hasilnya

| Keranjang | Isi |
|---|---|
| **A. Aman dibuang** | 7 komponen tampilan lama, 1 berkas bantu, 70 dari 72 gaya tampilan lama, 3 pintu, 1 data kiriman |
| **B. Kamu yang putuskan** | 9 pintu soal harga, 1 pintu sunting gateway, 1 pintu tandai produk populer, 1 pintu ekspor duplikat |
| **C. Dibiarkan apa adanya** | Semua sisanya: petugas, halaman, izin, berkas gaya baru |

---

## 4. Bagian A — aman dibuang (tidak ada pemakainya)

### A1. Tujuh komponen tampilan lama

**Apa ini:** potongan tampilan peninggalan panel lama, misalnya kerangka halaman
(`AdminShell`), alat unggah gambar (`AdminMediaControl`), tabel pintar
(`AdminResponsiveTable`), kolom data pelanggan (`AdminCustomerFields`), jendela
konfirmasi (`AdminConfirmDialog`), ikon (`AdminIcon`), dan sakelar (`AdminSwitch`).

**Gunanya dulu:** menyusun halaman-halaman panel lama.

**Kalau dihapus:** tidak ada yang rusak. Panel baru punya kerangka dan komponen
sendiri (`AdminLayout`, `AdminModal`, `AdminMedia`, `AdminMultiSelect`). Ketujuhnya
saling memanggil satu sama lain, jadi begitu yang paling atas dibuang, sisanya
otomatis tidak ada yang memakai.

**Cara kamu cek sendiri:** sulit dari layar, karena ini bagian dalam kode. Patokan
praktisnya: seluruh 22 halaman panel sekarang tampil normal tanpa ketujuh ini —
sudah saya buktikan dengan membuka semuanya setelah penulisan ulang.

### A2. Satu berkas bantu: `ordering.js`

**Apa ini:** kode bantu pengurutan data yang dulu dipakai panel lama.

**Gunanya dulu:** mengurutkan baris di dalam tabel.

**Kalau dihapus:** tidak ada yang rusak — halaman baru mengurutkan di server
(misalnya "Urutan lebih kecil tampil lebih dulu" di menu Kategori).

**Cara kamu cek sendiri:** tidak bisa dari layar; ini murni isi kode.

### A3. Gaya tampilan lama (`.lf-admin-*`) — 70 dari 72

**Apa ini:** aturan warna, jarak, dan bentuk untuk panel lama.

**Gunanya dulu:** mempercantik halaman panel lama.

**Kalau dihapus:** 22 halaman kerja panel baru tidak terpengaruh sama sekali, karena
semuanya sudah memakai gaya baru (diawali `.adm-`). Yang **harus tetap tinggal** hanya
2 gaya yang masih dipakai 4 halaman pintu masuk: `lf-admin-auth` dan `lf-admin-logo`
(lihat bagian 7 keputusan a).

**Cara kamu cek sendiri:** buka panel dan bandingkan — kalau ada yang tiba-tiba
terlihat "telanjang" (tanpa kotak/jarak), berarti gaya yang dibuang masih dipakai.
Angka di atas sudah saya hitung dari daftar kelas yang benar-benar muncul di file.

### A4. Tiga pintu dan satu data kiriman yang tidak dipakai halaman mana pun

Semuanya masih **menerima** kiriman kalau alamatnya dipanggil langsung, tapi tidak
ada tombol/aksi di panel yang memanggilnya.

| Pintu | Apa itu | Gunanya | Kalau dibuang |
|---|---|---|---|
| `PUT /admin/payments/gateways/{id}` | Menyimpan nama, urutan, status aktif, dan mode perawatan sebuah gateway pembayaran | Menonaktifkan gateway yang sedang bermasalah tanpa mengubah kode | Hilang. Tab Pembayaran sekarang hanya **melihat** daftar gateway, belum bisa menyuntingnya |
| `GET /admin/reports/export` | Unduh laporan CSV yang dibuat server | Dulu tombol "Ekspor" di menu Laporan | Hilang, tapi tidak ada yang terasa: menu Laporan sekarang membuat CSV-nya sendiri di browser dan langsung terunduh |
| `PUT /admin/vouchers/popular/{productId}` | Menandai produk sebagai "populer" dari panel | Menyusun bagian "Produk Populer" di halaman depan toko | Tombolnya hilang; **fitur tokonya masih hidup** (lihat bagian B5) |
| Data kiriman `popularProducts` | Daftar produk populer yang dikirim server ke halaman Promo | Mengisi tabel "produk populer" di panel lama | Tidak ada yang hilang, halaman Promo baru tidak memakainya |

> Saran saya: `reports/export` dan `popularProducts` bisa dibuang. Dua yang lain
> (sunting gateway, tandai populer) sebaiknya jangan dibuang dulu — lihat bagian B.

---

## 5. Bagian B — fitur yang kehilangan layar: kamu putuskan

Seluruhnya ada 13 pintu yang **tidak ada tombolnya** di panel baru. Isinya: 9 pintu soal
harga, 1 pintu sunting gateway, 1 pintu tandai produk populer, dan 1 pintu ekspor yang
sudah digantikan cara baru, plus 1 data kiriman yang menggantung.

Beberapa di antaranya adalah fitur dagang yang nyata, jadi ini bukan soal "kode mati" —
soalnya panel baru belum punya tempatnya. Kamu yang menentukan mau dibuang atau
dibuatkan layarnya.

### B1. Ubah harga massal (3 pintu)
- `PUT /admin/catalog/margin`, `POST /admin/catalog/margin/preview`, `PUT /admin/catalog/pricing`

**Apa ini:** alat untuk mengubah harga semua produk sekaligus dalam satu kali jalan.
Dua angka yang diatur: **kenaikan modal** dan **markup jual** (persen). Ada
"pratinjau" dulu: sebelum diterapkan, ditampilkan dulu hasil perubahannya untuk
dikonfirmasi.

**Gunanya untuk toko:** waktu kamu ingin menaikkan/menurunkan margin seluruh katalog
tanpa mengedit satu per satu produk.

**Sekarang bagaimana:** aturan harga per game (2% jadi modal, 10% jadi harga jual)
sudah ada di alur impor di menu Digiflazz, dan bisa diubah per game/per nominal di
menu Produk. Yang belum ada: satu tombol untuk mengubah **semuanya sekaligus**.

**Pilihanmu:** (a) buang, karena sudah cukup per game; (b) saya buatkan layar
"Ubah harga massal" di menu Produk.

### B2. Kunci harga / klaim harga maksimum (4 pintu + 2 data kiriman)
- `POST /admin/catalog/digiflazz/lock-prices`, `PUT /admin/digiflazz/baseline/{id}`,
  `POST /admin/digiflazz/max-prices/preview`, `POST /admin/digiflazz/max-prices/apply`,
  ditambah data `fixedPriceAlerts` + `fixedPriceProposals`

**Apa ini:** dua hal yang berhubungan dengan harga modal yang kamu beli dari Digiflazz:
1. **Kunci harga:** menandai harga modal sekarang sebagai patokan, supaya kenaikan
   harga dari Digiflazz tidak otomatis menaikkan harga jualmu.
2. **Harga Max Buyer Area:** menaikkan harga jual **hanya** untuk SKU tertentu yang
   kamu pilih, berbasis teks SKU, juga dengan pratinjau sebelum diterapkan.

Data `fixedPriceAlerts` dan `fixedPriceProposals` adalah daftar "harga sudah
melenceng dari patokan" dan usulan penyesuaiannya.

**Gunanya untuk toko:** ini pengaman untung. Kalau Digiflazz naikkan harga modal dan
harga jualmu ikut naik otomatis, pelanggan bisa kabur; kalau tidak dipantau sama
sekali, untungmu bisa habis.

**Pilihanmu:** (a) buang semuanya; (b) saya buatkan layarnya — paling masuk akal di
menu Digiflazz, karena di situ kamu sudah memantau kesehatan SKU dan harga modal.

### B3. Prioritas sumber per nominal (2 pintu)
- `PUT /admin/catalog/mappings/{mapping}`, `POST /admin/catalog/mappings/{mapping}/sync`

**Apa ini:** mengatur urutan dan status sumber (penyedia) untuk satu nominal produk,
termasuk harga modalnya, dan menyegarkan data SKU dari Digiflazz.

**Gunanya untuk toko:** menentukan penyedia mana yang dicoba lebih dulu saat pelanggan
membeli sebuah nominal.

**Sekarang bagaimana:** halaman Produk baru sudah bisa memasang sumber ke nominal,
tapi pengaturan urutan/harga per sumber ini belum ada layarnya.

**Pilihanmu:** (a) buang; (b) pindahkan ke layar nominal di menu Produk.

### B4. Sunting gateway pembayaran (1 pintu)
- `PUT /admin/payments/gateways/{id}`

**Apa ini:** mengubah nama tampilan, urutan, status aktif, dan mode perawatan gateway
(Midtrans, DOKU, QRIS manual, dst).

**Gunanya untuk toko:** kalau gateway sedang gangguan, kamu bisa mematikan metodenya
dari panel, dan pilihan metode pelanggan langsung menyesuaikan (server memang sudah
menyaring gateway yang berstatus perawatan).

**Sekarang bagaimana:** tab "Pengaturan Pembayaran" menampilkan daftar gateway dan
biayanya, tapi belum ada tombol untuk menyunting gateway-nya sendiri.

**Pilihanmu:** (a) buang; (b) tambahkan tombol "Ubah gateway" di tab itu.

### B5. Tandai produk populer (1 pintu)
- `PUT /admin/vouchers/popular/{productId}`

**Apa ini:** menandai produk agar muncul di bagian "Produk Populer" halaman depan toko.

**Sekarang bagaimana:** halaman Promo baru tidak punya kolom itu. **Fitur tokonya
tetap hidup** — halaman katalog toko masih mengurutkan produk populer dan masih punya
bagian "Produk Populer". Hanya tombol penandanya yang hilang dari panel.

**Pilihanmu:** (a) buang, dan produk populer hanya berubah lewat database;
(b) tambahkan kembali penandanya — paling pas di menu Produk (kolom "Populer").

### B6. Ekspor CSV versi server (1 pintu)
- `GET /admin/reports/export`

Duplikat: menu Laporan sekarang membuat CSV-nya sendiri di browser, jadi pintu ini
tidak menambah apa pun. **Saran: dibuang.**

---

## 6. Bagian C — dibiarkan (masih hidup, jangan disentuh)

- **27 petugas admin** — semuanya masih dipakai alamatnya. Termasuk 4 petugas yang
  alamatnya tidak menyebut nama fungsi (Dashboard, Detail Pesanan, Batal Pesanan,
  Pencarian Admin) — keempatnya hidup.
- **27 halaman panel** (23 halaman kerja + 4 halaman pintu masuk) — semuanya masih
  dirender oleh petugasnya. Tidak ada halaman yatim.
- **14 izin admin** — 13 dipakai sebagai penjaga alamat, dan 1 (`reports.finance`)
  dipakai untuk menyembunyikan angka uang dari admin biasa. Semuanya terpakai;
  daftar ini yang muncul di pop-up "Ubah akun" di menu Admin & Akses.
- **Komponen baru**: kerangka panel, pop-up, pemilih ganda, alat unggah gambar.
- **Berkas bantu yang hidup**: pemformat angka/tanggal (`adminFormat.js`),
  penyesuaian saldo pelanggan (`adminWalletAdjustment.js`), dan pengingat kata sandi
  (`sensitivePrompt.js`).
- **6 pengalihan (redirect) lama** di `routes/web.php`: `/admin`, `/panel`,
  `/panel/login`, `/panel/admin`, `/panel/admin/login`.
  Artinya: alamat lama otomatis dilempar ke alamat baru. Ini murah dan mencegah
  tautan lama mati, jadi saya sarankan dibiarkan. Kalau kamu mau bersih total, ini
  tempatnya.
- **Berkas dan komponen sisi pelanggan** (tampilan katalog, checkout, halaman
  pelanggan, pemilih kode telepon) — itu milik toko, bukan panel. Jangan dihapus.

---

## 7. Dua keputusan lain

**a. Empat halaman pintu masuk masih bergaya panel lama**
Halaman Masuk, Lupa Kata Sandi, Reset Kata Sandi, dan Konfirmasi Kata Sandi belum
ikut didesain ulang. Kamu bisa lihat sendiri bedanya: buka `/admin/login` — tampilannya
beda dari halaman panel yang lain. Mau saya rapikan sekalian, atau dibiarkan?

**b. Notifikasi dan daftar menu**
Halaman Notifikasi ada dan hidup, tapi tidak tercantum di daftar menu samping (kamu
membukanya dari ikon lonceng). Di sisi server, daftar menu yang dipakai untuk mengatur
hak akses belum memuat Notifikasi. Fungsinya tidak terganggu. Mau diselaraskan
(Notifikasi ikut jadi baris di daftar menu) atau dibiarkan seperti sekarang?

---

## 8. Langkah selanjutnya

Setelah kamu menandai pilihan di bagian 4, 5, dan 7, urutannya jadi begini:

1. Saya buang yang kamu setujui (komponen lama, gaya lama, pintu yang tidak dipakai).
2. Saya cocokkan backend dengan layar baru: halaman yang butuh data tambahan
   dilengkapi, dan data yang tidak dipakai lagi dibuang dari petugasnya.
3. Baru kemudian: hapus total lapisan tampilan lama, tulis ulang dokumen panel,
   lalu siapkan cabang `admin-panel-v2` untuk push + PR.

Catatan: dokumen ini akan saya simpan di `docs/` supaya jadi catatan tetap. Dokumen
`docs/ADMIN-PANEL.md` yang sekarang masih disusun dari daftar halaman template dan
perlu ditulis ulang dari alamat/petugas yang nyata — itu pekerjaan terpisah.