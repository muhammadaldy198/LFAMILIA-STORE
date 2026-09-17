# Konfigurasi Integrasi LFAMILIA STORE

Integrasi pembayaran yang aktif hanya **DOKU Checkout hosted** dan **Midtrans Snap hosted**. Credential keduanya diatur dari **Super Admin → Pembayaran**.

Menu **Integrasi** menyimpan Digiflazz, KokinPay, Resend, dan VPS Relay khusus Digiflazz. Simpan root secret `INTEGRATION_ENCRYPTION_KEY` (minimal 32 karakter) di Cloudflare agar credential panel terenkripsi di D1.

Callback yang perlu didaftarkan:

```text
https://lfamiliastore.my.id/api/payments/doku/callback
https://lfamiliastore.my.id/api/payments/midtrans/snap/notification
https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback
```

Digiflazz dipanggil server hanya setelah pembayaran tervalidasi sebagai `paid`; redirect halaman pembayaran tidak pernah dianggap sebagai bukti pembayaran.
