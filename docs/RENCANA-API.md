# Rencana Integrasi API LFAMILIA STORE

Dokumen ini adalah panduan implementasi tahap berikutnya. Source yang sekarang hanya frontend demo dan tidak menyimpan uang atau pesanan.

## Arsitektur yang disarankan

- **Cloudflare Worker + Vinext:** halaman dan endpoint server.
- **Cloudflare D1:** produk, pengguna, invoice, pembayaran, status supplier, log webhook, dan audit admin.
- **DigiFlazz:** daftar harga, pemeriksaan saldo, pembuatan pesanan, dan callback status.
- **Midtrans Snap/Core API:** pembuatan QRIS dan notifikasi pembayaran.
- **Cloudflare Queues (opsional):** memisahkan verifikasi pembayaran dari pengiriman pesanan agar retry aman.

## Alur transaksi

1. Browser mengirim produk, nominal, User ID, server, dan kontak.
2. Server membaca harga dari database—jangan percaya harga yang dikirim browser.
3. Server membuat invoice unik berstatus `PENDING_PAYMENT`.
4. Server membuat transaksi QRIS Midtrans dan mengembalikan token/QR ke browser.
5. Webhook Midtrans diverifikasi dengan signature dan dicatat satu kali.
6. Hanya pembayaran valid dengan nominal yang cocok yang mengubah status menjadi `PAID`.
7. Pekerjaan pengiriman dibuat satu kali, lalu server memanggil DigiFlazz dengan reference ID invoice yang stabil.
8. Callback DigiFlazz memperbarui status menjadi `PROCESSING`, `SUCCESS`, atau `FAILED`.
9. Pelanggan melihat status dari nomor invoice tanpa membuka data sensitif.

## Endpoint minimum

| Endpoint | Fungsi |
| --- | --- |
| `GET /api/products` | Katalog dan harga aktif dari database |
| `POST /api/orders` | Validasi tujuan dan membuat invoice |
| `POST /api/payments/midtrans` | Membuat pembayaran untuk invoice |
| `POST /api/webhooks/midtrans` | Verifikasi notifikasi pembayaran |
| `POST /api/webhooks/digiflazz` | Verifikasi callback pemasok |
| `GET /api/orders/:invoice` | Status publik yang sudah disaring |
| `/api/admin/*` | Produk, margin, pesanan, laporan, dan pengaturan |

## Aturan keamanan wajib

- Server Key Midtrans dan API key DigiFlazz hanya berada di Cloudflare Secrets.
- Gunakan signature resmi Midtrans dan secret callback DigiFlazz; jangan hanya mempercayai status dari request.
- Terapkan idempotensi di webhook dan pengiriman—satu invoice tidak boleh mengirim produk dua kali.
- Cocokkan `order_id`, nominal, currency, dan status transaksi dengan database.
- Simpan log perubahan status dan siapa yang melakukan tindakan admin.
- Lindungi admin dengan autentikasi, otorisasi per peran, rate limit, dan MFA bila tersedia.
- Jangan menyimpan password mentah; gunakan penyedia auth atau hash password yang sesuai.
- Batasi data yang tampil pada endpoint cek invoice.
- Validasi format User ID/server per produk dan lakukan rate limiting pada checkout.
- Mulai dengan sandbox, lalu uji skenario berhasil, pending, kedaluwarsa, gagal, webhook ganda, timeout supplier, dan refund.

## Biaya admin pelanggan

Biaya admin harus dihitung di server dan ditampilkan sebelum pelanggan menekan bayar. Jangan mengunci angka `0,7%` sebagai nilai produksi karena tarif, pajak, dan aturan penerusan biaya dapat berbeda menurut kontrak atau jenis merchant.

Gunakan konfigurasi database untuk tarif efektif yang disetujui. Jika toko harus menerima nilai bersih tertentu dan biaya berupa persentase `r`, rumus gross-up dasar adalah:

```text
biaya = pembulatan_ke_atas(subtotal / (1 - r) - subtotal)
total = subtotal + biaya
```

Rumus final tetap harus memasukkan PPN atau komponen lain sesuai invoice resmi Midtrans. Pastikan penerusan biaya kepada pelanggan diperbolehkan oleh ketentuan Midtrans dan peraturan yang berlaku.

## Tahapan implementasi

1. Buat skema D1 dan autentikasi admin.
2. Sinkronkan produk DigiFlazz ke tabel staging, lalu aktifkan produk secara manual.
3. Buat order dan cek invoice tanpa pembayaran.
4. Integrasikan Midtrans sandbox beserta verifikasi webhook.
5. Integrasikan DigiFlazz sandbox/testing dengan idempotensi.
6. Uji rekonsiliasi, retry, refund, laporan, dan notifikasi.
7. Tinjau dokumen hukum, kontak dukungan, identitas merchant, serta tarif final.
8. Baru pindah ke kredensial produksi dan hapus banner mode demo.
