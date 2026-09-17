# LFAMILIA Integration Configuration

Gateway pembayaran aktif dikelola dari Admin Panel: **DOKU Direct API** dan **Midtrans Snap**. DOKU Checkout hosted tidak digunakan untuk transaksi baru.

Credential DOKU Direct API Sandbox/Production disimpan terenkripsi di D1 menggunakan `INTEGRATION_ENCRYPTION_KEY`. Backend mendukung QRIS, Virtual Account yang dikonfigurasi, serta DANA/ShopeePay Direct API. Callback DOKU tetap memakai `https://lfamiliastore.my.id/api/payments/doku/callback`.

Provider otomatis adalah Digiflazz. Isi username, API key per environment, URL transaksi, URL pricelist, dan webhook secret di **Super Admin → Integrasi → Digiflazz**. Credential disimpan terenkripsi dengan `INTEGRATION_ENCRYPTION_KEY`.

Relay VPS hanya digunakan untuk Digiflazz bila diperlukan. DOKU Direct API dipanggil langsung dari Worker. KokinPay dipakai server untuk validasi nickname sebelum checkout.
