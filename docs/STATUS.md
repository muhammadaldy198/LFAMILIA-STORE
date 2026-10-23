# STATUS — LFAMILIA STORE

Catatan apa yang **sudah jalan** dan apa yang **belum**. File ini berubah setiap kali ada pekerjaan selesai; aturan produk tidak di sini, tapi di [`PRD.md`](PRD.md).

**Terakhir diperbarui:** 22 Oktober 2026
**Keadaan situs:** ada di alamat publik `lfamiliastore.my.id`, **belum beroperasi produksi**. Belum ada pelanggan nyata, belum ada transaksi nyata.

---

## Sedang dikerjakan

| Pekerjaan | Status |
|---|---|
| PRD ditulis ulang (`docs/PRD.md`) | selesai, menunggu commit |
| Pembersihan dokumen (arsip, README, AGENTS, CLAUDE) | selesai, menunggu commit |
| Spesifikasi panel admin (`docs/ADMIN-PANEL.md`) | belum dimulai |
| Panel admin dibangun ulang (tema terang) | belum dimulai |

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
- 27 halaman, 74 route, 27 controller
- Peran Super Admin dan Admin dengan 14 izin
- Audit log, kesehatan sistem, laporan + ekspor
- **Tampilan masih versi lama (gelap).** Akan dibangun ulang.

---

## Belum ada / belum dipakai

| Hal | Keadaan |
|---|---|
| Pelanggan nyata | belum ada |
| Transaksi nyata | belum ada |
| Top up saldo oleh pelanggan | belum pernah |
| Discord webhook untuk notifikasi admin | belum diisi |
| Diskon per tingkatan member | ambang sudah ada, besar diskon belum diisi |
| DOKU | belum dipasang |
| Panel admin versi terang | belum dibuat |
| `docs/ADMIN-PANEL.md` | belum ditulis |

---

## Catatan penting

- **Data di lingkungan lokal adalah data uji**, bukan data produksi.
- **Refund tidak otomatis.** Member lewat penyesuaian saldo (Super Admin), guest lewat transfer manual.
- Semua status di atas berarti "kode tersedia", bukan "sudah terbukti dipakai orang".
