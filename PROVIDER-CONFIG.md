# LFAMILIA Integration Configuration

Gateway pembayaran yang didukung dikelola dari Admin Panel: **DOKU Checkout** dan **Midtrans Snap**. Keduanya dapat dipakai untuk checkout dan top up saldo, serta dapat diaktifkan/dinonaktifkan dengan toggle tanpa menghapus credential.

Credential DOKU Checkout dan Midtrans Snap diisi dari **Super Admin → Integrasi**. Profile Sandbox/Production disimpan terenkripsi di D1 menggunakan `INTEGRATION_ENCRYPTION_KEY`. Backend membuat satu sesi DOKU Checkout hosted dan membatasi metode pembayaran sesuai channel yang dipilih customer. Callback DOKU memakai `https://lfamiliastore.my.id/api/payments/doku/callback`.

Top up saldo mempunyai gateway pilihan sendiri di **Super Admin → Pembayaran**: **DOKU Checkout** atau **Midtrans Snap**. Pilihan top up tidak mengikuti routing checkout. Gateway pilihan harus berstatus siap dan toggle gateway harus ON; jika OFF atau belum siap, backend menolak pembuatan top up dan tidak melakukan fallback otomatis.

Provider otomatis adalah Digiflazz. Isi username, API key per environment, URL transaksi, URL pricelist, dan webhook secret di **Super Admin → Integrasi → Digiflazz**. Credential disimpan terenkripsi dengan `INTEGRATION_ENCRYPTION_KEY`.

Pada runtime Laravel VPS, Digiflazz keluar langsung dari IP VPS ke endpoint resmi `api.digiflazz.com`. KokinPay dipakai backend untuk validasi nickname sebelum checkout. Profil relay historis di database tidak dihapus; route Worker lama dan DNS relay baru dapat dipensiunkan setelah cutover Laravel tervalidasi.
