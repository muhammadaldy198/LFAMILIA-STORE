# Konfigurasi Integrasi LFAMILIA STORE

Integrasi pembayaran aktif memakai **DOKU Direct API** dan **Midtrans Snap**. DOKU Checkout hosted tidak dipakai untuk transaksi baru; handler lama dipertahankan hanya agar transaksi lama yang belum terminal tetap dapat diselesaikan dengan aman.

Credential pembayaran diatur dari **Super Admin → Pembayaran**. Credential DOKU Direct API disimpan terpisah untuk Sandbox dan Production dan dienkripsi di D1 menggunakan root secret `INTEGRATION_ENCRYPTION_KEY` (minimal 32 karakter).

DOKU Direct API membutuhkan Client ID, Secret Key, RSA Private Key, API URL, serta konfigurasi channel yang dipakai. QRIS membutuhkan Merchant ID, Terminal ID, dan Postal Code. Virtual Account menggunakan VA Config JSON per bank. DANA dan ShopeePay memakai jalur e-wallet Direct API yang didukung backend.

Base URL default DOKU:

```text
Sandbox    https://api-sandbox.doku.com
Production https://api.doku.com
```

Callback yang perlu didaftarkan:

```text
https://lfamiliastore.my.id/api/payments/doku/callback
https://lfamiliastore.my.id/api/payments/midtrans/snap/notification
https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback
```

DOKU Direct API berjalan langsung dari Worker LFAMILIA dan tidak memakai VPS relay. VPS relay tetap khusus Digiflazz. Redirect halaman pembayaran tidak pernah dianggap sebagai bukti pembayaran; backend hanya mengubah order menjadi `paid` setelah callback/status gateway tervalidasi. Digiflazz baru dipanggil setelah status pembayaran benar-benar `paid`.
