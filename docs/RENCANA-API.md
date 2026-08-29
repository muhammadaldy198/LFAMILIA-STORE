# Status Integrasi API LFAMILIA STORE

## Sudah diimplementasikan

- iPaymu Direct Payment untuk VA bank, DANA, ShopeePay, dan QRIS.
- `feeDirection: BUYER`, sehingga biaya yang dikembalikan iPaymu tampil sebagai biaya admin pelanggan.
- Verifikasi signature callback iPaymu, kecocokan invoice/transaksi/nominal, dan idempotensi status lunas.
- Adapter DigiFlazz beserta signature transaksi dan webhook HMAC-SHA1.
- Adapter VIPayment beserta form API, signature, dan webhook.
- Produk otomatis atau manual per produk; provider dan SKU per nominal.
- D1 untuk produk, nominal, order, dan log event.
- Status invoice publik yang hanya mengembalikan data aman dan menyamarkan tujuan.
- Antrean admin untuk produk manual.
- Stok kode internal AES-256-GCM, impor massal, pencegahan duplikat, dan reservasi satu kode per pesanan.
- Pengiriman kode otomatis melalui Resend dan template WhatsApp Cloud API, termasuk status per kanal dan kirim ulang dari admin.

## Wajib sebelum transaksi nyata

1. Terapkan seluruh migrasi D1 production.
2. Aktifkan Cloudflare Access untuk admin.
3. Isi secret melalui Cloudflare, bukan GitHub.
4. Ganti seluruh harga demo dan petakan SKU provider.
5. Uji iPaymu sandbox untuk pending, lunas, kedaluwarsa, gagal, nominal salah, dan callback ganda.
6. Uji DigiFlazz development dan VIPayment dengan produk uji.
7. Konfirmasi syarat whitelist/static IP dari iPaymu dan DigiFlazz.
8. Tambahkan domain dan kontak dukungan resmi.
9. Tinjau kebijakan refund, privasi, syarat, pajak, dan aturan penerusan biaya.
10. Uji satu kode REDFINGER dari pembayaran sandbox sampai email/WhatsApp dan pastikan stok berkurang tepat satu.

## Provider berikutnya

Lapakgaming atau provider lain dapat ditambahkan hanya bila tersedia API resmi dan credential merchant. Tambahkan adapter yang mengikuti `ProviderAdapter`; jangan scraping, jangan menyalin cookie, dan jangan meminta password/OTP pelanggan.
