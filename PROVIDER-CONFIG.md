# LFAMILIA Integration Configuration

Gateway pembayaran hosted dikelola dari Admin Panel: DOKU Checkout dan Midtrans Snap.

Provider otomatis adalah Digiflazz. Isi username, API key per environment, URL transaksi, URL pricelist, dan webhook secret di **Super Admin → Integrasi → Digiflazz**. Credential disimpan terenkripsi dengan `INTEGRATION_ENCRYPTION_KEY`.

Relay VPS hanya digunakan untuk Digiflazz bila diperlukan. KokinPay dipakai server untuk validasi nickname sebelum checkout.
