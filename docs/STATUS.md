# STATUS — LFAMILIA STORE

Catatan apa yang **sudah jalan** dan apa yang **belum**. Aturan produk ada di [`PRD.md`](PRD.md), spesifikasi panel ada di [`ADMIN-PANEL.md`](ADMIN-PANEL.md).

**Terakhir diperbarui:** 24 Oktober 2026
**Keadaan situs:** ada di alamat publik `lfamiliastore.my.id`. **Produksi menjalankan PR #295** (panel admin hasil desain ulang sudah termasuk). Toko **belum grand opening**: belum ada pelanggan nyata, belum ada transaksi nyata. Digiflazz masih mode test dan Midtrans masih sandbox sampai pemilik memerintahkan sebaliknya.

---

## Di `main`, belum deploy

| Isi | Keterangan |
|---|---|
| PR #297 — isi panel admin | merge 2026-10-24 (66bfb15a) |
| PR #302 — katalog tiga harga + sinkronisasi otomatis | merge 2026-10-24 |
| PR #303 — perbaikan tes browser harga impor | merge 2026-10-24 |
| `main` terakhir | e00b5458 — deploy adalah perintah terpisah dari pemilik, belum diberikan |

## Antrean perubahan kode (keputusan pemilik 2026-10-24)

1. Batas harga kirim Digiflazz: persis modal toko tertinggi (buang tambahan +1%).
2. Jadwal sinkronisasi Digiflazz: 5 menit → 15 menit.
3. Login ketat: belum verifikasi email = tidak bisa login; selesai daftar tidak otomatis masuk.
4. Status BLOCKED tidak tampil ke pelanggan (dipetakan ke "Gagal").
5. Buang blok rekonsiliasi refund top up + route-nya (method peninjauan internal `resolveTopupRefundReview` TIDAK ikut dibuang).
6. Nama nominal hasil impor: buang nama game untuk semua produk + bersihkan nama nominal yang sudah ada.

---

## Sudah ada dan berjalan di kode

Bagian ini menyatakan **kode tersedia**, bukan "sudah dipakai pelanggan".

**Katalog & produk**
- Kategori → produk → nominal → sumber penyedia (satu SKU terpilih per nominal)
- Harga sistem tiga harga: harga seller → modal toko → harga jual (margin di tingkat nominal); snapshot per pesanan
- Impor & sinkron katalog Digiflazz (manual dan terjadwal; interval berjalan 5 menit, keputusan pemilik 2026-10-24: menjadi 15 menit — antrean kode nomor 2)
- Sinkronisasi otomatis menonaktifkan nominal tanpa sumber aktif
- Media produk (gambar, ikon)

**Checkout & pembayaran**
- Checkout guest dan member, harga dihitung server, idempotensi
- Voucher: diskon nominal/persen, kuota, batas per pelanggan, cakupan produk
- Stok kode voucher (gift card) terenkripsi, reservasi, kirim sekali
- Midtrans Snap: pembuatan pembayaran, webhook `sha512` terverifikasi
- QRIS manual: konfirmasi oleh admin
- Saldo LFAMILIA: ledger, top up, bayar pesanan (fitur aktif, **belum pernah dipakai pelanggan**)
- DOKU: kode ada, **tidak aktif** (cadangan; pengaktifan manual oleh admin atas keputusan pemilik)

**Pengiriman**
- Digiflazz: `ref_id` unik, `sign` md5, cek ulang otomatis 3× jeda 2 menit, webhook `sha1`
- Antrean manual + jam operasional produk manual
- Failover otomatis dimatikan; gagal terkonfirmasi → pesanan `BLOCKED` (status internal; di sisi pelanggan tampil sebagai "Gagal")
- Peringatan saldo penyedia (ambang 5 transaksi)

**Pelanggan**
- Guest: belanja, lacak pesanan dengan kode akses, tiket bantuan
- Member: profil, nomor WhatsApp, riwayat, saldo, tiket, akun game tersimpan, notifikasi
- Ulasan pembeli: terbit langsung, admin dapat menghapus permanen
- Tingkatan member 6 (BASIC…MAFIA), naik dari total belanja; diskon per tingkat diatur admin
- Leaderboard anonim (opt-in)
- Login email (verifikasi link) + Google

**Konten & komunikasi**
- Banner, pop-up, berita, FAQ, halaman hukum (`/terms`, `/privacy`, `/refund`)
- Alat bantu: win rate, zodiac, magic wheel
- Notifikasi: email (Resend), Telegram, Discord, dalam panel — notifikasi admin berjalan di Telegram **dan** Discord (routing per jenis kejadian)
- Tiket bantuan + balasan cepat

**Panel admin**
- Panel hasil desain ulang (PR #295) **sudah produksi**; tema terang, dua peran (Super Admin dan Admin) dengan izin granular
- Audit log, kesehatan sistem, laporan + ekspor CSV, notifikasi panel
- Hitungan menu final panel diselesaikan pada audit `ADMIN-PANEL.md` (ditunda sampai pekerjaan panel dimulai — keputusan pemilik 2026-10-24)

**Pengujian**
- Suite penuh berjalan di CI dengan MySQL 8 + Redis asli; PR tidak digabung bila pemeriksaan wajib CI gagal
- Database tes terpisah dari database pengembangan; CI memakai database sendiri

---

## Belum ada / belum dipakai

| Hal | Keadaan |
|---|---|
| Pelanggan nyata | belum ada |
| Transaksi nyata | belum ada |
| Top up saldo oleh pelanggan | belum pernah |
| DOKU | belum dipasang (cadangan) |
| Deploy isi `main` terbaru ke produksi | belum — menunggu perintah pemilik |
| Sebagian aksi backend tanpa tombol di panel | 9 endpoint (lihat `ADMIN-PANEL.md` §10) |
| Blok rekonsiliasi refund top up di Pembayaran | disembunyikan dari panel; route masih ada — **diputuskan dibuang** (antrean kode nomor 5) |

---

## Catatan penting

- **Data di lingkungan lokal adalah data uji**, bukan data produksi.
- **Refund tidak otomatis.** Member lewat penyesuaian saldo (Super Admin), guest lewat transfer manual.
- Semua status di atas berarti "kode tersedia", bukan "sudah terbukti dipakai orang".
- Keputusan produk terakhir: PRD §23 (penyelarasan Buku Keputusan pemilik, 2026-10-24).
