# STATUS — LFAMILIA STORE

Catatan apa yang **sudah jalan** dan apa yang **belum**. Aturan produk ada di [`PRD.md`](PRD.md), spesifikasi panel ada di [`ADMIN-PANEL.md`](ADMIN-PANEL.md).

**Terakhir diperbarui:** 23 Oktober 2026
**Keadaan situs:** ada di alamat publik `lfamiliastore.my.id`, **belum beroperasi produksi**. Belum ada pelanggan nyata, belum ada transaksi nyata.

---

## Sedang dikerjakan

| Pekerjaan | Status |
|---|---|
| Panel admin baru (21 menu + 2 halaman detail, tema terang) | selesai di branch lokal `admin-panel-redesign`, belum digabung, belum deploy |
| Pembuangan lapisan tampilan admin lama | selesai (7 komponen, 1 berkas bantu, 338 aturan CSS) |
| Isolasi database tes | selesai — lokal `lfamilia_admin_redesign_test`, CI tetap `lfamilia_ci` |
| Spesifikasi panel (`docs/ADMIN-PANEL.md`) | selesai, disusun ulang dari route/controller/props |
| Penggabungan ke branch rilis `admin-panel-v2` | belum dimulai |

---

## Sudah ada dan berjalan di kode

Bagian ini menyatakan **kode tersedia**, bukan "sudah dipakai pelanggan".

**Katalog & produk**
- Kategori → produk → nominal → sumber penyedia
- Harga dari modal + margin, tersimpan di database, snapshot per pesanan
- Impor & sinkron katalog Digiflazz (manual dan terjadwal tiap 5 menit)
- Media produk (gambar, ikon)

**Checkout & pembayaran**
- Checkout guest dan member, harga dihitung server, idempotensi
- Voucher: diskon nominal/persen, kuota, batas per pelanggan, cakupan produk
- Stok kode voucher (gift card) terenkripsi, reservasi, kirim sekali
- Midtrans Snap: pembuatan pembayaran, webhook `sha512` terverifikasi
- QRIS manual: konfirmasi oleh admin
- Saldo LFAMILIA: ledger, top up, bayar pesanan (fitur aktif, **belum pernah dipakai pelanggan**)
- DOKU: kode ada, **tidak aktif**

**Pengiriman**
- Digiflazz: `ref_id` unik, `sign` md5, cek ulang otomatis 3× jeda 2 menit, webhook `sha1`
- Antrean manual + jam operasional produk manual
- Failover otomatis dimatikan; gagal terkonfirmasi → pesanan `BLOCKED`
- Peringatan saldo penyedia (ambang 5 transaksi)

**Pelanggan**
- Guest: belanja, lacak pesanan dengan kode akses, tiket bantuan
- Member: profil, nomor telepon, riwayat, saldo, tiket, akun game tersimpan, notifikasi
- Tingkatan member 6 (BASIC…MAFIA), naik dari total belanja
- Leaderboard anonim (opt-in)
- Login email + Google

**Konten & komunikasi**
- Banner, pop-up, berita, FAQ, halaman hukum (`/terms`, `/privacy`, `/refund`)
- Alat bantu: win rate, zodiac, magic wheel
- Notifikasi: email (Resend), Telegram, Discord, dalam panel
- Tiket bantuan + balasan cepat

**Panel admin**
- **21 menu + 2 halaman detail** (23 halaman) dengan tema terang, sidebar hitam, aksen hijau
- 141 route admin, 27 controller, 14 izin, dua peran (Super Admin dan Admin)
- Semua penyuntingan lewat pop-up tengah; detail tiket tampil sebagai panel di halaman
- Audit log, kesehatan sistem, laporan + ekspor CSV, notifikasi panel
- Halaman masuk (masuk, lupa sandi, atur ulang, konfirmasi sandi) masih memakai gaya lama gelap — belum diseragamkan

**Pengujian**
- Suite penuh: 530 tes, 5.296 asersi, 2 dilewati, 0 gagal
- Penjaga panel (Node): 46 tes lulus
- Database tes terpisah dari database pengembangan; CI memakai database sendiri

---

## Belum ada / belum dipakai

| Hal | Keadaan |
|---|---|
| Pelanggan nyata | belum ada |
| Transaksi nyata | belum ada |
| Top up saldo oleh pelanggan | belum pernah |
| Discord webhook untuk notifikasi admin | belum diisi |
| DOKU | belum dipasang |
| Deploy panel baru ke server | belum — masih branch lokal |
| Sebagian aksi backend tanpa tombol di panel | 9 endpoint (lihat `ADMIN-PANEL.md` §10) |
| Keputusan blok rekonsiliasi refund top up di Pembayaran | belum diputuskan |

---

## Catatan penting

- **Data di lingkungan lokal adalah data uji**, bukan data produksi.
- **Refund tidak otomatis.** Member lewat penyesuaian saldo (Super Admin), guest lewat transfer manual.
- Semua status di atas berarti "kode tersedia", bukan "sudah terbukti dipakai orang".
- Perubahan panel belum di-push dan belum di-PR; penggabungan butuh persetujuan pemilik.
