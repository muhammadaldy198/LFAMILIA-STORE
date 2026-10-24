# PRD — LFAMILIA STORE

**Versi:** 2.1 (penyelarasan dengan Buku Keputusan pemilik, 24 Oktober 2026 — rincian perubahan di §23)
**Tanggal:** 22 Oktober 2026, direvisi 24 Oktober 2026
**Menggantikan:** `PRD-LFAMILIA-STORE-v1.md` dan `PRD-TRACEABILITY-2026-10.md` (keduanya diarsipkan di `docs/archive/2026-10-22-pra-redesign/`)
**Sifat dokumen:** aturan produk. Tidak berisi laporan kemajuan, tidak berisi centang status, tidak berisi prosedur server.

---

## 0. Cara membaca dokumen ini

Dokumen ini menjawab satu pertanyaan: **toko ini bekerja dengan aturan apa.**

- Yang **sudah jalan** ditulis sebagai aturan berlaku.
- Yang **belum dipakai** ditulis apa adanya sebagai "belum dipakai", bukan sebagai rencana.
- Yang **tidak boleh ada** ditulis di §19 dengan tegas.
- Yang **belum diputuskan** dikumpulkan di §22, tidak diselipkan di tengah aturan.

Kalau dokumen ini berbeda dengan kode yang berjalan, **tidak ada yang otomatis menang**: perbedaan itu dicatat dan diputuskan pemilik kasus per kasus berdasarkan keputusan pemilik terakhir. Kalau kode menyimpang dari maksud pemilik, kode yang diperbaiki; kalau dokumen ini tertinggal dari keputusan yang lebih baru, dokumen ini yang diperbarui. Pengecualian: pada §19, dokumen ini yang selalu menang. (Keputusan pemilik 2026-10-24.)

---

## 1. Toko ini apa

LFAMILIA STORE adalah toko digital Indonesia yang menjual:

1. **Top up game** — diamond, UC, points, dan mata uang game lain, dikirim otomatis lewat penyedia (Digiflazz) atau manual oleh admin.
2. **Voucher & gift card** — kode digital yang disimpan sebagai stok dan dikirim setelah pembayaran lunas.
3. **Pulsa, paket data, PLN, dan entertainment** — produk digital dengan input nomor tujuan.
4. **Alat bantu gratis** — kalkulator win rate, zodiac, magic wheel. Bukan produk berbayar; berfungsi menarik pengunjung.

Pembeli bisa **tanpa akun (guest)** atau **dengan akun (member)**. Guest bisa membeli dan melacak pesanan lewat nomor pesanan + kode akses. Member mendapat saldo, riwayat, tiket, dan tingkatan member.

**Model bisnis:** jual produk pihak ketiga dengan selisih harga (margin). Toko tidak memproduksi produk; toko mengatur harga jual, menerima pembayaran, meneruskan pesanan ke penyedia, dan menanggung selisih kalau terjadi kegagalan.

---

## 2. Istilah (dipakai konsisten di seluruh sistem)

| Istilah | Arti |
|---|---|
| **Produk** | Barang yang dijual, mis. "Mobile Legends". |
| **Nominal / paket** | Pilihan di dalam produk, mis. "86 Diamond". |
| **Sumber** | Pemetaan nominal ke SKU penyedia, mis. `MLBB-86` di Digiflazz. Satu nominal boleh punya beberapa sumber. |
| **Pesanan** | Satu transaksi beli. Punya nomor pesanan, isi, dan status. |
| **Pembayaran** | Catatan uang masuk untuk satu pesanan. Satu pesanan bisa punya lebih dari satu percobaan pembayaran. |
| **Pengiriman (fulfillment)** | Tindakan mengirim produk ke tujuan pembeli. Otomatis (penyedia) atau manual (admin). |
| **Snapshot** | Salinan data saat pesanan dibuat: harga, nama produk, isi input. Tidak berubah walau katalog berubah. |
| **Saldo** | Uang pelanggan yang disimpan di toko. Hanya untuk member. |
| **Ledger** | Catatan mutasi saldo. Tidak boleh dihapus, tidak boleh diubah. |
| **Penyesuaian saldo** | Tindakan admin menambah/mengurangi saldo pelanggan dengan alasan. Bukan refund otomatis. |

---

## 3. Alur utama

```
Pilih produk → pilih nominal → isi data tujuan → (cek nama, jika tersedia)
      ↓
Checkout: server hitung harga, voucher, biaya kanal → pesanan dibuat (status: menunggu pembayaran)
      ↓
Pembayaran: Midtrans / QRIS manual / saldo
      ↓
Webhook tervalidasi → pembayaran LUNAS → pesanan masuk antrean pengiriman
      ↓
Pengiriman: otomatis ke penyedia, atau manual oleh admin
      ↓
Berhasil → produk terkirim, pesanan selesai
Gagal   → perlu pemeriksaan admin → penyelesaian (kirim ulang / refund manual)
```

Aturan yang mengikat alur ini:

1. **Harga dihitung server.** Harga yang dikirim browser tidak pernah dipercaya.
2. **Pesanan dibuat sebelum pembayaran.** Tidak ada pembayaran tanpa pesanan.
3. **Lunas hanya dari webhook yang tanda tangannya valid.** Halaman "selesai bayar" di browser bukan bukti.
4. **Pengiriman tidak pernah dimulai sebelum lunas.**
5. **Setiap langkah tercatat** di riwayat pesanan dan audit log.

---

## 4. Katalog

### 4.1 Struktur

```
Kategori → Produk → Nominal → Sumber (SKU penyedia)
```

- **Kategori** punya nama, slug, ikon, urutan, status aktif.
- **Produk** punya kategori, nama, slug, urutan, status aktif, penanda "populer", dan gambar/ikon (media produk). Produk **tidak** punya margin/markup sendiri (lihat §4.3).
- **Nominal** punya kode, nama, nilai nominal (opsional), urutan, status aktif. Nominal nonaktif tidak muncul di toko. **Nama nominal hasil impor dari penyedia tidak memuat nama game/produk** — fokus ke nominalnya (mis. "5 Diamond", bukan "Free Fire 5 Diamond"); berlaku untuk semua produk.
- **Sumber** menghubungkan nominal ke SKU penyedia, punya harga modal, status aktif, dan penanda "harga manual". Satu nominal dilayani **satu SKU penyedia terpilih**; pembeli tidak bisa memilih SKU dan tidak melihat pemetaan internal.

### 4.2 Aturan tampil

- Yang tidak aktif tidak pernah tampil ke pembeli, tapi tidak dihapus — hanya disembunyikan.
- Produk tanpa nominal aktif tidak bisa dibeli.
- Nominal tanpa sumber aktif **tidak boleh** bisa dibeli; kalau dipaksa, checkout menolak dengan alasan yang jelas.
- Nominal yang tidak tersedia — sumbernya nonaktif/hilang, harga seller melewati modal toko, atau data katalognya basi lebih dari 24 jam — **hilang total dari tampilan pembeli** (tombolnya nonaktif/hilang sendiri), bukan tampil dengan label "tidak bisa dibeli".
- Urutan tampil produk mengikuti urutan yang diatur admin, bukan abjad.
- **Urutan nominal produk** tampil dari nilai kecil ke besar.

### 4.3 Harga

Harga berjalan dengan **sistem tiga harga**: **harga seller** (harga dari penyedia) → **harga modal toko** → **harga jual**.

Aturan:

- **Harga modal toko dibentuk sekali** saat impor/mapping: harga seller + **tambahan modal (bawaan 2%)**. Sesudah dibentuk, modal toko **tidak berubah mengikuti harga seller**; sinkronisasi berikutnya hanya memperbarui harga seller sebagai referensi — tidak menimpa modal toko dan tidak menerapkan ulang tambahan modal. Modal toko boleh diedit admin, tetapi **tidak boleh di bawah harga referensi seller saat itu**.
- **Harga jual = modal toko × markup (bawaan 10%).** Hasilnya disimpan di tingkat **nominal** sebagai margin mode persen; admin dapat mengubah persennya per nominal atau menggantinya menjadi harga tetap. **Tidak ada markup di tingkat produk.**
- Impor memakai **dua kolom persen global** untuk semua SKU terpilih (modal bawaan 2% di atas harga seller, jual bawaan 10% di atas modal toko), dengan pratinjau seller → modal toko → harga jual sebelum diterapkan.
- Harga rupiah **dibulatkan ke atas**; tidak ada pembulatan "cantik".
- **Harga jual tidak boleh lebih murah dari modal toko** — server menolaknya.
- **Nominal disembunyikan otomatis** bila harga seller naik di atas modal toko, dan tampil lagi otomatis bila harga seller turun kembali ke dalam batas (lihat §4.2).
- **Batas harga kirim ke penyedia per pesanan = modal toko tertinggi di antara SKU aktif pada nominal itu — persis sama, tanpa tambahan.** Batas ini dihitung saat checkout dan menempel sebagai snapshot pesanan; pesanan yang harga penyedianya melewati batas tidak dikirim otomatis.
- **Harga yang dibayar pembeli adalah harga saat pesanan dibuat** (snapshot). Kalau harga katalog berubah setelah itu, pesanan lama tidak berubah.
- Harga modal dan harga seller **disimpan di database sendiri**; harga tidak pernah ditanyakan ke penyedia saat ada pengunjung.
- Perubahan harga dan margin hanya lewat panel admin, tercatat di audit log.

### 4.4 Sinkronisasi katalog dari penyedia

- Sinkronisasi bersifat **menarik data**, bukan mendorong. Admin yang memicu, atau penjadwal yang menjalankan **tiap 15 menit** untuk seluruh katalog (ada saklar di panel; tidak ada sinkronisasi per produk). Daftar harga penyedia disimpan di database sendiri dan diperbarui berkala — dokumentasi Digiflazz menyatakan ada limitasi pengecekan daftar harga, jadi gunakan secara bijak.
- Yang hilang dari penyedia: **pemetaan yang belum pernah dipakai pesanan dihapus**; **pemetaan yang pernah dipakai dipertahankan tetapi dinonaktifkan**. Nominal yang tidak lagi punya sumber aktif **dinonaktifkan otomatis oleh sinkronisasi** (penanda dinonaktifkan-oleh-sinkronisasi) dan aktif lagi hanya bila SKU-nya kembali. Produk dan nominal buatan admin sendiri tidak dihapus sinkronisasi.
- Sinkronisasi juga **membuat pemetaan sumber awal** untuk nominal aktif yang belum terpetakan.
- Perubahan harga seller dari sinkronisasi **tidak mengubah modal toko dan tidak mengubah harga jual** (lihat §4.3).
- Setiap sinkronisasi mencatat: kapan, oleh siapa/sistem, berapa baris berubah.

---

## 5. Input tujuan & validasi akun

### 5.1 Field input

Tiap produk menentukan field apa yang harus diisi pembeli. Contoh:

- Mobile Legends: `user_id` + `zone_id`
- Free Fire: `user_id`
- Pulsa: `phone`
- PLN: `meter_number`

Aturan:

- Field ditentukan **per produk**, bukan per game yang dikarang di kode.
- Field wajib tidak boleh kosong; validasi dilakukan di server.
- Label, placeholder, dan bantuan ditulis dalam bahasa toko, bukan bahasa teknis.
- Nomor telepon dinormalisasi ke format Indonesia sebelum dikirim ke penyedia.

### 5.2 Cek nama (nickname)

- Cek nama **opsional per produk**. Kalau produk tidak mendukung, field cek tidak muncul.
- Kalau layanan cek nama mati atau lambat, **pembelian tetap bisa jalan**. Cek nama adalah bantuan, bukan syarat.
- Hasil cek nama **tidak mengikat**. Yang menentukan adalah data yang dikirim ke penyedia.
- Toko **tidak pernah** meminta password game, PIN, atau kode OTP di kolom pesanan mana pun.

### 5.3 Kesalahan data tujuan

Kalau pembeli salah mengisi ID/server, dan penyedia sudah mengirim sesuai data itu, pesanan dianggap selesai. Ini bukan kegagalan sistem dan bukan dasar refund (lihat §10).

---

## 6. Checkout

Urutan yang mengikat:

1. Pembeli memilih produk dan nominal.
2. Mengisi data tujuan.
3. Server menghitung: harga dasar, diskon voucher, biaya kanal, total.
4. Pembeli memilih metode pembayaran yang **sedang aktif**.
5. Pesanan dibuat dengan nomor unik dan masa berlaku.
6. Pembayaran disiapkan; pembeli diarahkan ke halaman bayar.

Aturan:

- **Idempotensi wajib.** Klik ganda atau refresh tidak boleh membuat dua pesanan.
- **Masa berlaku pesanan** ditetapkan saat dibuat; lewat waktu → kedaluwarsa, tidak bisa dibayar.
- **Biaya kanal** dihitung server dan ditampilkan **sebelum** pesanan dibuat. Tidak boleh muncul tiba-tiba setelah bayar.
- Biaya kanal **ditanggung pembeli di atas harga** (dihitung gross-up agar jumlah bersih yang diterima toko utuh). **Saldo LFAMILIA dan QRIS manual tanpa biaya kanal.** Angka biaya per kanal diatur di dokumen pembayaran, bukan di dokumen ini. Top up saldo dikenai biaya yang sama seperti pesanan, tetapi yang dikreditkan ke saldo hanya nominal top up-nya.
- **Voucher divalidasi di server** saat checkout dan sekali lagi saat pesanan dibuat.
- **Stok voucher (kode) direservasi** saat pesanan dibuat, dilepas kalau pesanan gagal/kedaluwarsa.
- Guest wajib memberi kontak (email atau telepon) yang bisa dihubungi; tanpa itu, keluhan tidak bisa ditangani.
- Setelah checkout, guest menerima **kode akses** untuk melacak pesanan. Kode ini tidak boleh bisa ditebak.

---

## 7. Pembayaran

### 7.1 Kanal yang berlaku

| Kanal | Status | Catatan |
|---|---|---|
| **Midtrans** (QRIS, Virtual Account, E-Wallet) | Aktif | Gateway utama. |
| **QRIS manual** | Ada, dipakai sesuai kebutuhan | Konfirmasi oleh admin setelah dana masuk. |
| **Saldo LFAMILIA** | Aktif untuk member | Guest tidak punya saldo. |
| **DOKU** | **Belum dipakai — cadangan** | Kode tersedia. Tidak tampil di toko selama belum aktif. Diaktifkan/dipindah **admin secara manual** lewat pengaturan kanal di panel, atas keputusan pemilik (lihat §19 butir 12). |

Kanal yang tampil ke pembeli hanya kanal yang aktif. Kanal dalam perawatan tidak tampil dan tidak bisa dipilih.

**Tidak ada perpindahan gateway otomatis.** Midtrans adalah gateway utama; DOKU hanya cadangan. Di panel memang ada pilihan kanal/gateway, dan admin bebas menggantinya sendiri — yang memilih manusia, bukan sistem secara otomatis.

### 7.2 Aturan "lunas"

Sebuah pembayaran dianggap **LUNAS** hanya jika:

- Notifikasi masuk dari gateway,
- Tanda tangan notifikasi valid,
- Nominal cocok dengan total pesanan,
- Status transaksi = `settlement` (atau `capture` dengan `fraud_status = accept`).

Selain itu **bukan lunas**. `pending`, `deny`, `cancel`, `expire`, `failure` bukan lunas.

Aturan tambahan:

- **Redirect halaman setelah bayar bukan bukti.** Kalau status di browser bilang sukses tapi webhook belum masuk, sistem menunggu — tidak mengirim produk.
- **Notifikasi palsu ditolak.** Tanda tangan tidak cocok → ditolak, dicatat, tidak mengubah apa pun.
- **Notifikasi berulang aman.** Notifikasi yang sama datang dua kali tidak boleh menambah saldo atau mengirim produk dua kali.
- **Pembayaran terlambat** (dana masuk setelah pesanan kedaluwarsa) **tidak otomatis diproses**. Ditandai untuk diperiksa admin.
- **Nominal tidak cocok** → ditandai untuk diperiksa, tidak diproses.
- **Pembayaran yang hasilnya belum pasti** (permintaan terkirim tetapi jawaban gateway tidak kembali) ditandai **UNKNOWN** untuk diperiksa admin. Admin dapat mengecek statusnya langsung ke gateway dari panel — invoice dan nominal diverifikasi dulu, hasilnya tercatat di audit. Selama statusnya belum pasti, sistem **tidak membuat pembayaran kedua** untuk pesanan yang sama, dan pembeli diberi **peringatan tertulis** di halaman status/lacak pesanan agar tidak membayar ulang sebelum statusnya jelas dan diarahkan menghubungi admin. (Ini peringatan, bukan larangan membeli lagi — toko tidak menghentikan pembelian siapa pun.)

### 7.3 QRIS manual

- Pembeli membayar ke QRIS toko, lalu mengunggah/menunggu konfirmasi.
- **Admin mengonfirmasi manual** setelah memastikan dana masuk.
- Konfirmasi admin dicatat: siapa, kapan, bukti.
- Tanpa konfirmasi admin, pesanan tidak pernah dianggap lunas.

### 7.4 Saldo sebagai alat bayar

- Member bisa membayar pesanan dengan saldo.
- Saldo dipotong **atomik** — tidak mungkin terpotong dua kali untuk satu pesanan.
- Saldo tidak boleh minus.
- Setiap pemotongan punya catatan ledger dengan rujukan pesanan.

---

## 8. Pengiriman (fulfillment)

### 8.1 Otomatis — Digiflazz

Kontrak yang mengikat (sesuai dokumentasi resmi Digiflazz):

| Hal | Nilai |
|---|---|
| Endpoint | `POST https://api.digiflazz.com/v1/transaction` |
| Parameter wajib | `username`, `buyer_sku_code`, `customer_no`, `ref_id`, `sign` |
| Tanda tangan | `md5(username + apiKey + ref_id)` |
| Sifat respons | **Sinkron** — langsung sukses / gagal / pending |
| Cek status | Kirim ulang request yang sama dengan **`ref_id` yang sama** |
| Batas aman cek ulang | **Tidak lebih sering dari 1 menit** |
| Batas mutlak | **Jangan pernah cek transaksi lebih dari 90 hari** — akan membuat transaksi baru |
| Daftar harga | `cmd=prepaid`, `sign` = `md5(username + apiKey + "pricelist")`, disimpan di database sendiri |
| Webhook | `POST` JSON, header `X-Hub-Signature: sha1=HMAC-SHA1(body, secret)`, header `X-Digiflazz-Event` (`create`/`update`) |

Aturan turunan:

- **`ref_id` unik per pesanan** dan tidak pernah dipakai ulang untuk pesanan lain.
- **Pending bukan gagal.** Pesanan pending dicek ulang berkala; tidak boleh langsung dinyatakan gagal.
- **Cek ulang dibatasi** supaya tidak melanggar aturan 1 menit.
- **Webhook diverifikasi tanda tangannya.** Tanpa verifikasi, payload diabaikan.
- **Webhook hanya mempercepat**, bukan satu-satunya sumber. Cek ulang tetap dijalankan untuk pesanan yang belum pasti.
- **Saldo penyedia dipantau.** Saldo rendah harus terlihat di panel sebelum menyebabkan kegagalan.
- **Batas cek ulang otomatis: 3 kali**, dengan jeda minimal 2 menit antar percobaan. Setelah itu pesanan tidak dicek otomatis lagi dan naik ke pemeriksaan manusia. Artinya kegagalan penyedia biasanya diketahui dalam hitungan menit, bukan hari — jadi klaim "penyedia baru konfirmasi gagal berhari-hari kemudian" tidak dipakai sebagai alasan pengecualian refund.
- **Ambang peringatan saldo: 5 transaksi.** Di bawah itu panel menampilkan peringatan.

### 8.2 Manual

- Produk manual (mis. nominal khusus, voucher, layanan yang tidak otomatis) masuk **antrean manual**.
- Produk manual boleh punya **jam operasional** (buka/tutup, zona waktu). Di luar jam itu, pesanan tetap bisa masuk tetapi diberi keterangan bahwa pemrosesan mengikuti jam operasional.
- Admin wajib menyelesaikan dengan bukti: apa yang dikirim, kapan, oleh siapa.
- **Batas waktu: 1 jam** sejak pesanan masuk antrean manual, dihitung hanya selama jam layanan pelanggan aktif. Di luar jam layanan, hitungan berhenti. Lewat batas → muncul peringatan di dashboard dan daftar antrean, bukan hilang diam-diam.
- Kalau tidak ada yang berjaga, produk manual **dinonaktifkan** dari toko. Ini tindakan yang disengaja, bukan kegagalan sistem.

### 8.3 Pembatalan

- Pesanan hanya bisa dibatalkan pada status yang memungkinkan: sudah dibayar tapi belum terkirim, gagal, atau terblokir.
- **Pesanan yang sudah berhasil dikirim tidak bisa dibatalkan.**
- Pembatalan wajib beralasan dan tercatat di riwayat pesanan.
- Membatalkan pesanan tidak otomatis mengembalikan uang — penyelesaian uang mengikuti §10.

### 8.4 Larangan

- **Tidak ada pengiriman ganda.** Satu pesanan dikirim sekali. Percobaan ulang harus aman.
- **Tidak ada failover otomatis.** Kalau penyedia **mengonfirmasi gagal**, pesanan menjadi "perlu tindakan superadmin" dan berhenti. Tidak ada pengiriman otomatis ke sumber kedua. Admin boleh mengirim ulang secara **manual** ke sumber lain hanya setelah kegagalan dikonfirmasi penyedia (bukan saat hasilnya tidak pasti), dan tindakan itu tercatat.
- **Hasil tidak pasti bukan alasan kirim ulang.** Status "belum pasti"/"sedang dikirim" harus dicek ulang dulu sampai ada kepastian, karena pelanggan mungkin sudah menerima produk.
- **Tidak ada pengiriman sebelum lunas.**
- **Tidak ada perubahan data tujuan** setelah pesanan dikirim.

---

## 9. Status pesanan

### 9.1 Status yang dilihat pelanggan (6)

Ini yang tampil di halaman lacak pesanan dan email. Hanya enam, dengan bahasa toko:

| Status | Arti bagi pembeli |
|---|---|
| **Menunggu Pembayaran** | Pesanan dibuat, belum dibayar. |
| **Sedang Diproses** | Pembayaran diterima, pesanan sedang dikirim. |
| **Berhasil** | Produk sudah terkirim. |
| **Gagal** | Pesanan gagal final; ditangani sesuai kebijakan pengembalian dana. |
| **Kedaluwarsa** | Waktu bayar habis. |
| **Dibatalkan** | Pesanan dibatalkan. Kalau sudah bayar, hubungi bantuan. |

### 9.2 Status internal

Sistem memakai status teknis yang lebih banyak (menyiapkan pembayaran, menunggu kepastian, sedang dikirim, perlu pemeriksaan, perlu tindakan superadmin, dan seterusnya). **Status internal tidak pernah ditampilkan ke pelanggan sebagai istilah teknis.** Pemetaan internal → pelanggan wajib satu arah dan konsisten.

Aturan:

- Satu status pelanggan bisa berasal dari beberapa status internal.
- Status pelanggan **tidak pernah mundur** (Berhasil tidak bisa kembali ke Sedang Diproses).
- Setiap perubahan status dicatat dengan waktu dan alasan.
- Status internal **BLOCKED** (pengiriman gagal terkonfirmasi, perlu tindakan superadmin) dipetakan ke **Gagal** untuk pelanggan. Istilah BLOCKED **tidak pernah tampil** ke pelanggan — daftar di §9.1 adalah satu-satunya status yang dilihat pelanggan.

---

## 10. Penyelesaian & pengembalian dana

### 10.1 Prinsip

Pengembalian dana **tidak pernah otomatis**. Tidak ada tombol yang memindahkan uang tanpa keputusan manusia. Sistem mencatat, admin memutuskan.

Kebijakan lengkap ada di halaman publik `/refund` dan **dokumen itu yang berlaku untuk pembeli**. Ringkasan aturan internal:

### 10.2 Kondisi yang dapat diajukan

1. Pembayaran terverifikasi, tetapi pesanan dinyatakan gagal oleh sistem/penyedia dan tidak bisa diproses ulang.
2. Pembayaran ganda untuk invoice yang sama.
3. Produk tidak tersedia dan tidak bisa dipenuhi dalam waktu wajar.
4. Nominal terkirim lebih kecil dari yang dibayar dan selisih tidak bisa dipenuhi.
5. Pembayaran dilaporkan tidak sah sebelum produk dikirim (setelah verifikasi kepemilikan).

### 10.3 Kondisi yang tidak dapat diajukan

1. Produk sudah berhasil terkirim.
2. Salah pilih game, produk, nominal, User ID, server, region, email, atau nomor tujuan.
3. Kode voucher/lisensi sudah ditampilkan atau dipakai.
4. Akun game diblokir/dibatasi publisher.
5. Bonus/event publisher tidak diterima padahal produk utama terkirim.
6. Berubah pikiran, menemukan harga lain, atau keterlambatan singkat.

### 10.4 Batas waktu

- Pengajuan: **maksimal 7 hari kalender** sejak transaksi, lewat Hubungi Kami, dengan nomor invoice.
- **Pengecualian:** kalau catatan sistem membuktikan kesalahan ada di sisi LFAMILIA (mis. pengiriman tidak pernah dijalankan, notifikasi pembayaran hilang, pesanan macet karena gangguan internal), pengajuan tetap dilayani walau lewat 7 hari. Pengecualian harus merujuk bukti di riwayat pesanan, bukan penilaian lisan.
- **Bukan pengecualian:** produk ditarik publisher setelah terkirim, akun game diblokir, atau bonus publisher tidak diterima. Itu bukan kesalahan sistem dan tetap ditolak.
- Pemeriksaan: umumnya **1–7 hari kerja**.
- Dana diterima pembeli setelah refund diterbitkan: **hingga 14 hari kerja** (tergantung bank/dompet/gateway).

### 10.5 Dua jalur penyelesaian

**Member (punya akun):**
- Pengembalian dapat dilakukan sebagai **penyesuaian saldo** oleh admin.
- Wajib: jumlah, alasan, rujukan pesanan, dan pencatatan audit.
- Nama fiturnya **"penyesuaian saldo"**, bukan "refund". Sistem tidak memutuskan apa pun; admin yang menyatakan.

**Guest (tanpa akun):**
- Guest **tidak punya saldo**, jadi tidak ada tempat menaruh saldo.
- Penyelesaian dilakukan dengan **transfer manual di luar sistem**.
- Di panel, admin hanya menandai: sudah diselesaikan + catatan + bukti.

### 10.6 Pengaman penyesuaian saldo

- **Hanya Super Admin.** Admin biasa tidak punya akses, walau diberi izin lain.
- Alasan wajib diisi, tidak boleh kosong.
- Tidak ada batas nominal per tindakan; pengamannya adalah peran, alasan wajib, dan jejak audit.
- Saldo tidak boleh minus.
- Tercatat di ledger dan audit log, tidak bisa dihapus.

---

## 11. Akun pelanggan

### 11.1 Guest

- Bisa belanja tanpa mendaftar.
- Melacak pesanan dengan **nomor pesanan + kode akses**.
- Bisa menghubungi bantuan (tiket guest didukung).
- **Tidak punya saldo, tidak punya riwayat permanen, tidak punya tingkatan member.**
- **Data guest disimpan 30 hari** sejak aktivitas terakhir, lalu dibersihkan otomatis. Pembersihan tidak menghapus catatan transaksi yang wajib disimpan untuk pembukuan.

### 11.2 Member

- Daftar dengan email atau Google.
- **Verifikasi email memakai link (standar).** Selesai mendaftar, pengguna **tidak otomatis masuk** ke akun — dikembalikan ke halaman login, dan **belum verifikasi berarti tidak bisa login**. Akun Google otomatis terverifikasi.
- **Sesudah login, member wajib melengkapi nomor WhatsApp dulu** sebelum memakai toko — termasuk yang masuk lewat Google.
- **Satu nomor WhatsApp hanya untuk satu akun**; nomor minimal 10 digit dan dinormalisasi ke format Indonesia. (Nomor WhatsApp adalah data akun, bukan kredensial masuk; mengganti nomor tidak memakai OTP.)
- Punya: profil, riwayat pesanan, saldo, tiket, akun game tersimpan, notifikasi.
- Bisa menghapus akun. Penghapusan tidak menghapus catatan transaksi yang wajib disimpan untuk pembukuan.
- **Ganti email dan ganti kata sandi wajib memasukkan kata sandi saat ini** (bagi akun yang punya kata sandi). Ganti email mereset status verifikasi dan mengirim link verifikasi baru ke email baru. Akun Google tidak punya kata sandi: ganti email tidak dimintai kata sandi, dan pemilik akun dapat membuat kata sandi pertama kali dari halaman akun.

### 11.3 Tingkatan member

Tingkat naik berdasarkan **total belanja lunas** (pesanan berstatus dibayar/diproses/berhasil):

| Tingkat | Syarat total belanja | Tampil ke pelanggan |
|---|---|---|
| **BASIC** | akun baru, Rp 0 | ya |
| **SILVER** | Rp 500.000 | ya |
| **GOLD** | Rp 5.000.000 | ya |
| **DIAMOND** | Rp 20.000.000 | ya |
| **PLATINUM** | Rp 50.000.000 | ya |
| **MAFIA** | tidak dijual | **tidak** |

Aturan:

- **MAFIA adalah tingkat internal.** Hanya untuk akun uji/akun internal pemilik toko di sisi pelanggan. Tidak pernah tampil di halaman tingkatan, tidak pernah muncul sebagai target berikutnya, dan tidak bisa dicapai lewat belanja.
- Angka di atas dapat diubah admin per tingkat (ambang belanja dan besar diskon), tetapi **urutan tingkat tidak berubah**.
- Tingkat bisa dipaksa manual oleh admin untuk satu pelanggan; pemaksaan itu tercatat di audit log.
- **Besar diskon per tingkat tidak ditetapkan di dokumen ini** — diatur dan diisi admin lewat panel; bawaannya kosong (tanpa diskon) sampai admin mengisi.
- **Tingkat bukan hak akses admin.** Akun admin di panel dan akun pelanggan adalah dua sistem terpisah dan tidak boleh dicampur, walau memakai email yang sama.

### 11.4 Saldo

- Hanya untuk member.
- Semua mutasi lewat ledger: sebelum, sesudah, sumber, rujukan, pelaku.
- Ledger **tidak bisa diubah atau dihapus**.
- Top up saldo punya nominal minimum yang dapat diatur admin.
- Saldo tidak bisa dipakai untuk membayar pesanan yang sudah lunas.

### 11.5 Leaderboard

- Berisi alias anonim, bukan nama asli/email/telepon.
- Hanya member yang **memilih ikut** (opt-in).
- Bisa bulan ini atau sepanjang waktu.

---

## 12. Promo & voucher

- Voucher punya: kode, tipe diskon (nominal/persen), nilai, minimum belanja, kuota total, batas per pelanggan, masa berlaku, cakupan produk/kategori.
- Voucher **divalidasi di server**; kode yang tidak valid tidak pernah diterapkan.
- Satu pesanan memakai satu voucher.
- Kuota berkurang saat pesanan dibuat; kembali kalau pesanan gagal/kedaluwarsa.
- Voucher tidak bisa dipakai bersamaan dengan harga yang sudah didiskon manual, kecuali aturannya mengizinkan.
- Promo di halaman publik (`/promo`) menampilkan voucher yang sedang aktif — bukan arsip.

### 12.1 Stok kode voucher (gift card)

- Kode disimpan **terenkripsi**, dengan hash unik untuk mencegah duplikat.
- Kode **direservasi** saat pesanan dibuat, **dikirim** setelah lunas, dan **tidak bisa dikirim dua kali**.
- Kode yang sudah terkirim tidak bisa ditarik kembali.
- Kalau stok habis, nominal otomatis tidak bisa dibeli.

### 12.2 Ulasan pembeli

- Ulasan hanya bisa dibuat dari **pesanan yang memenuhi syarat** (kelayakan berasal dari pesanan), bukan dibuat oleh sistem.
- Ulasan **terbit langsung** tanpa moderasi di depan.
- Admin dapat **menghapus permanen** ulasan dari panel.

---

## 13. Halaman publik

Daftar halaman yang harus ada dan berfungsi:

| Halaman | Alamat | Isi |
|---|---|---|
| Beranda | `/` | Produk populer, kategori, cara top up, promo |
| Katalog | `/catalog` | Semua produk, filter kategori, pencarian |
| Detail produk | `/catalog/{slug}` | Nominal, input tujuan, deskripsi, catatan, ulasan pembeli |
| Checkout | `/checkout` | Ringkasan, data tujuan, pembayaran |
| Lacak pesanan | `/track` | Nomor pesanan + kode akses |
| Berita | `/news` | Artikel dan pengumuman |
| FAQ | `/faq` | Pertanyaan umum |
| Hubungi kami | `/contact` | Kanal resmi |
| Bantuan/tiket | `/support` | Tiket guest & member |
| Promo | `/promo` | Voucher aktif |
| Leaderboard | `/leaderboard` | Peringkat anonim |
| Alat bantu | `/tools` | Win rate, zodiac, magic wheel |
| Status | `/status` | Status layanan |
| Syarat & ketentuan | `/terms` | Dokumen hukum |
| Kebijakan privasi | `/privacy` | Dokumen hukum |
| Kebijakan pengembalian dana | `/refund` | Dokumen hukum — **wajib ada** |

Aturan:

- **Banner dan pop-up adalah konten yang dikelola dari panel**, di samping berita, FAQ, dan halaman hukum.
- **Footer wajib memuat empat kanal resmi:** Instagram, WhatsApp, Email, Discord. Keempatnya sudah terpasang dan tidak boleh hilang saat desain ulang.
- Halaman hukum **tidak boleh hilang** saat desain ulang.
- Halaman publik harus bisa dibuka tanpa login.
- Halaman publik harus punya metadata SEO (judul, deskripsi, sitemap, robots).

---

## 14. Bantuan pelanggan

- Tiket bisa dibuat oleh guest maupun member.
- Setiap tiket punya nomor, status, dan riwayat percakapan.
- Admin membalas dari panel; balasan tercatat.
- **Balasan cepat** (template) boleh dipakai, tapi tidak boleh mengirim data pribadi pelanggan lain.
- Tiket yang tidak dijawab dalam batas waktu harus muncul sebagai peringatan di dashboard.
- Komunikasi resmi hanya lewat kanal yang terdaftar. Toko tidak pernah meminta password/PIN/OTP.

---

## 15. Notifikasi

Kanal yang tersedia: **email (Resend), Telegram, Discord**, plus notifikasi di dalam panel.

Aturan:

- Notifikasi **tidak boleh** memuat data sensitif (password, PIN, kode OTP, kredensial).
- Notifikasi penting: pesanan baru, pembayaran diterima, pengiriman gagal, tiket baru, saldo penyedia rendah, integrasi mati.
- Notifikasi kejadian penting untuk admin dikirim ke **Telegram dan Discord (keduanya)**; peringatan kegagalan pemeriksaan kesehatan juga masuk ke email admin.
- Notifikasi yang gagal kirim tidak boleh menggagalkan pesanan.
- Admin bisa mengatur ke mana notifikasi diarahkan per jenis kejadian.

---

## 16. Laporan

- Laporan penjualan: omzet, jumlah pesanan, per produk, per kanal pembayaran, per periode.
- **Omzet dihitung hanya dari pesanan yang benar-benar lunas** dan memakai waktu WIB.
- **Batas hari: 00:00–23:59 WIB**, untuk semua laporan dan dashboard. Tidak mengikuti jam operasional, supaya angka dashboard dan laporan selalu sama.
- Laporan finansial hanya untuk yang berhak (Super Admin atau izin laporan finansial).
- Ekspor laporan dicatat di audit log (siapa, kapan, apa).
- Angka di dashboard dan laporan harus berasal dari sumber yang sama — tidak boleh beda.

---

## 17. Panel admin (ringkas)

Detail ada di dokumen terpisah (`ADMIN-PANEL.md`). Yang mengikat di PRD:

- Dua peran: **Super Admin** (semua) dan **Admin** (sesuai izin yang diberikan).
- Izin yang ada: dashboard, pesanan, katalog, konten, penanganan manual, penyedia, pembayaran, pelanggan, promo, layanan pelanggan, laporan, laporan finansial, pengaturan, notifikasi.
- **Tindakan sensitif wajib konfirmasi ulang kata sandi.**
- **Semua tindakan yang mengubah data tercatat di audit log**: siapa, kapan, apa, sebelum/sesudah.
- Panel tidak pernah menampilkan kredensial integrasi dalam bentuk terbuka.
- Panel harus bisa dipakai di HP.
- Pengaturan kanal/gateway yang aktif ada di panel dan diganti **admin secara manual**; perpindahan gateway tidak pernah dilakukan sistem secara otomatis (lihat §7.1).

---

## 18. Aturan teknis yang mengikat

1. **Stack:** Laravel 12, Inertia, Vue 3, Tailwind, MySQL, di VPS dengan CloudPanel.
2. **Uang selalu bilangan bulat rupiah.** Tidak ada floating point untuk uang.
3. **Semua perubahan uang lewat ledger** dengan kunci idempotensi.
4. **Semua endpoint yang mengubah data punya proteksi CSRF** dan otorisasi server-side.
5. **Rahasia (API key, server key) disimpan terenkripsi**, tidak pernah dikirim ke browser.
6. **Webhook diverifikasi tanda tangan** sebelum diproses.
7. **Perubahan skema database lewat migrasi**, tidak manual.
8. **Tidak ada data produksi di lingkungan pengembangan.**
9. **Zona waktu tampilan: WIB.** Penyimpanan: UTC.

---

## 19. Yang tidak boleh ada (tidak bisa ditawar)

1. **Refund otomatis.** Tidak ada kode yang memindahkan uang tanpa keputusan admin.
2. **Pengiriman sebelum lunas.**
3. **Pengiriman ganda** untuk satu pesanan.
4. **Failover otomatis ke sumber kedua** saat hasil tidak pasti.
5. **Saldo minus.**
6. **Saldo untuk guest.**
7. **Menyimpan password game, PIN, atau OTP pelanggan.**
8. **Menampilkan kredensial integrasi** di layar atau log.
9. **Menghapus audit log atau ledger.**
10. **Mengubah harga pesanan yang sudah dibuat.**
11. **Menampilkan status internal teknis ke pelanggan.**
12. **Mengaktifkan DOKU** tanpa keputusan pemilik yang tertulis. Perpindahan gateway juga tidak pernah otomatis — selalu tindakan manual admin atas keputusan pemilik.

---

## 20. Definisi selesai

Sebuah fitur dianggap selesai jika:

1. Perilakunya sesuai dokumen ini.
2. Ada test yang membuktikan perilaku itu, termasuk kasus gagal.
3. Tidak melanggar satu pun butir di §19.
4. Sudah diuji di layar HP dan desktop.
5. Tidak ada data contoh atau tombol palsu yang tertinggal.

---

## 21. Status pemakaian nyata

Situs sudah punya alamat publik, tetapi **belum beroperasi produksi**: belum ada pelanggan nyata, belum ada transaksi nyata, saldo pelanggan belum pernah dipakai. Ini penting untuk membaca dokumen ini: semua aturan di atas berlaku, tetapi tidak satu pun boleh dianggap "sudah terbukti di lapangan".

Konsekuensi yang mengikat:

1. **Panel admin tidak boleh menampilkan angka palsu.** Kalau belum ada data, tampilkan kosong dengan keterangan, bukan contoh.
2. **Fitur yang belum pernah dipakai ditandai jelas** di panel sebagai "belum dipakai", bukan disembunyikan dan bukan dipamerkan seolah aktif.
3. **Uji sebelum produksi wajib** memakai data uji, bukan data pelanggan.

## 22. Keputusan yang masih terbuka

Tidak ada saat ini. Dua butir terakhir ditutup pada 2026-10-24:

1. **Kanal notifikasi admin** — diputuskan **Telegram dan Discord** (lihat §15). Ini berbeda dari tautan Discord di footer yang sudah terpasang.
2. **Diskon per tingkatan member** — bukan angka tetap; **diatur dan diisi admin lewat panel** (lihat §11.3).

---

## 23. Riwayat keputusan

| Tanggal | Keputusan |
|---|---|
| 2026-10-24 | Penyelarasan dengan Buku Keputusan pemilik (PRD v2.1). Harga: sistem tiga harga (seller → modal toko → harga jual); modal dibentuk sekali saat impor (+2%), jual bawaan +10% disimpan sebagai margin nominal; **markup tingkat produk dihapus**; aturan khusus ML ×1,06 (2026-10-12) **dicabut** — semua produk ikut bawaan impor, persen bisa diubah per nominal sesudahnya. Pembulatan ke atas. Batas harga kirim = **persis modal toko tertinggi** SKU aktif (tambahan +1% dihapus). Sinkronisasi: tiap **15 menit**, seluruh katalog; nominal tanpa sumber aktif dinonaktifkan sinkronisasi; pemetaan belum pernah dipakai dihapus, yang pernah dipakai dinonaktifkan; nominal tidak tersedia **disembunyikan total** dari pembeli (termasuk data basi > 24 jam). Nama nominal impor **tanpa nama game** untuk semua produk. DOKU = **cadangan**, diaktifkan/dipindah admin manual lewat panel; tidak ada perpindahan gateway otomatis. Biaya kanal: prinsip gross-up ditanggung pembeli; saldo & QRIS manual nol; angka per kanal di dokumen pembayaran. Pembayaran belum pasti: ditandai UNKNOWN, dicek admin ke gateway, pembeli diberi peringatan tertulis (bukan larangan). Status BLOCKED **tidak tampil** ke pelanggan (dipetakan ke Gagal). Verifikasi email: **link ketat** — belum verifikasi tidak bisa login; sesudah login wajib nomor WhatsApp; satu nomor satu akun. Diskon tingkatan member diatur admin. Notifikasi admin: Telegram **dan** Discord. Ulasan pembeli: terbit langsung, admin bisa hapus permanen. Perbedaan PRD vs kode diputuskan **kasus per kasus** oleh pemilik (§0); §19 tetap mutlak. |
| 2026-10-22 | PRD ditulis ulang. Status pelanggan dikunci 6. DOKU ditandai belum dipakai. Pengembalian dana: manual, member lewat penyesuaian saldo, guest lewat transfer. |
| 2026-10-22 | Tingkatan member: BASIC/SILVER 500rb/GOLD 5jt/DIAMOND 20jt/PLATINUM 50jt; MAFIA internal, tidak tampil. Data guest disimpan 30 hari. DOKU dibiarkan ada (akan dipasang). Footer 4 kanal termasuk Discord. |
| 2026-10-22 | Penyesuaian saldo: Super Admin saja, tanpa batas nominal. Pesanan manual: peringatan setelah 1 jam jam-layanan. Refund lewat 7 hari hanya bila kesalahan sistem terbukti. Batas hari laporan 00:00–23:59 WIB. Ambang saldo penyedia tetap 5 transaksi. Situs belum produksi. |
| 2026-10-17 | Pengembalian dana dilayani manual lewat CS; sistem tidak mengotomatiskan. |
| 2026-10-12 | Harga produk dihitung dari modal + margin; pembulatan mengikuti aturan yang berlaku saat itu. |
