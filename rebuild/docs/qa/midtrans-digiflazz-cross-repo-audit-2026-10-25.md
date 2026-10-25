# Perbandingan integrasi Midtrans dan Digiflazz (25 Oktober 2026)

## Repositori pembanding (referensi, bukan sumber kebenaran)
- https://github.com/karuhun-developer/webtopup — Laravel; `MidtransService`, `HandleMidtransCallbackAction`, `HandleDigiflazzCallbackAction`, dan `DigiflazzService`.
- https://github.com/bagusok/baguspay — NestJS; `apps/web-api/src/integrations/h2h/digiflazz/digiflazz.service.ts`, processor, dan callback Digiflazz. **Tidak memakai Midtrans untuk gateway utamanya** (provider pembayaran lain).
- https://github.com/wweziza/lswstore — Next.js; `app/api/midtrans/callback/route.jsx` dan `app/lib/midtrans.js`. Menggunakan **VIP Reseller**, bukan Digiflazz, untuk pengiriman game.

Kode dari tiga repo tidak boleh disalin membabi buta: beberapa memakai aturan status yang lebih lemah, dan ketiganya tidak membuktikan kelayakan merchant Midtrans ataupun harga SKU LFAMILIA.

## Temuan yang diperbaiki

### Midtrans — konfirmasi lunas dengan dua syarat
- Sebelumnya status provider `settlement` selalu dianggap `PAID`, bahkan bila `fraud_status` menyatakan `deny` atau `challenge`. `capture` juga bisa dianggap lunas tanpa pemeriksaan `status_code`.
- Sekarang `settlement` atau `capture` harus berstatus `status_code=200` dan, **jika fraud_status ada**, nilainya `accept`. Respons bertentangan tetap `PENDING`; tidak mengirim produk otomatis.
- Tanda tangan HMAC SHA-512 dari notifikasi, GET status Midtrans, verifikasi nominal, dan idempotensi pembayaran yang sudah ada **tidak diubah**.

Rujukan resmi: https://docs.midtrans.com/docs/https-notification-webhooks dan https://docs.midtrans.com/reference/handle-notifications.

### Digiflazz — RC dan status harus sejalan
- `Sukses` hanya dinyatakan berhasil jika `rc=00`; label `Sukses` dengan RC lain menjadi `UNKNOWN` sampai diverifikasi.
- `Gagal` dengan `rc=01` (timeout), `rc=70` (timeout biller), atau RC pending `03/99` tetap `UNKNOWN`, bukan kegagalan final. `Gagal` dengan `rc=00` juga tidak diterima sebagai bukti gagal.
- `Pending` dengan `rc=00` merupakan respons kontradiktif, juga harus ditinjau sebagai `UNKNOWN`.
- Mekanisme **cek ulang memakai ref_id yang sama**, pembatasan tiga pengecekan otomatis, larangan duplikasi transaksi, dan batas `max_price` **tidak diubah**.

Rujukan resmi: https://developer.digiflazz.com/api/buyer/topup/ dan https://developer.digiflazz.com/api/buyer/response-code/.

## Keputusan tetap
- Harga tetap hanya 3 konsep: **Harga Seller, Harga Modal, Harga Jual**. Tidak ada rumus harga, biaya, margin, atau kuota yang diubah.
- Tidak ada refund otomatis, fallback gateway otomatis, perubahan metode yang aktif, mock di produksi, ataupun deploy VPS.
- Fitur PLN/pascabayar baru, migrasi seluruh stack, dan pembandingan UI **di luar scope**; temuan lain harus melalui PR terpisah.
