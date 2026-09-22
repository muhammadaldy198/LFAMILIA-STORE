# Konfigurasi Integrasi LFAMILIA STORE

Integrasi pembayaran yang didukung hanya **DOKU Checkout** dan **Midtrans Snap** untuk checkout maupun top up saldo. DOKU Checkout hosted digunakan sebagai jalur pembayaran DOKU.

Credential pembayaran diatur dari **Super Admin → Integrasi → DOKU Checkout / Midtrans Snap**. Credential disimpan terpisah untuk Sandbox dan Production dan dienkripsi di D1 menggunakan root secret `INTEGRATION_ENCRYPTION_KEY` (minimal 32 karakter). Menu **Pembayaran** hanya mengatur operasional seperti gateway aktif/nonaktif, environment aktif, routing top up, channel pembayaran, tampilan pembayaran, dan transaksi.

DOKU Checkout hanya membutuhkan Client ID, Secret Key, dan API URL per environment. QRIS, VA, dan e-wallet yang aktif di DOKU dipilih melalui hosted checkout tanpa RSA Private Key, Merchant ID/Terminal ID QRIS, atau VA Config JSON di LFAMILIA.

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

Di **Super Admin → Pembayaran**, DOKU Checkout dan Midtrans memiliki toggle aktif/nonaktif yang terpisah dari status kesiapan credential. Top up saldo juga mempunyai pilihan gateway sendiri (`DOKU Checkout` atau `Midtrans Snap`) dan master toggle top up otomatis. Gateway top up yang dipilih harus berstatus siap dan aktif; backend tidak melakukan fallback diam-diam ke gateway lain.

DOKU Checkout berjalan langsung dari Worker LFAMILIA dan tidak memakai VPS relay. VPS relay tetap khusus Digiflazz. Redirect halaman pembayaran tidak pernah dianggap sebagai bukti pembayaran; backend hanya mengubah order menjadi `paid` setelah callback/status gateway tervalidasi. Digiflazz baru dipanggil setelah status pembayaran benar-benar `paid`.

## Google Login pelanggan

Google Login pelanggan memakai **Google Identity Services (GIS)** dan hanya membutuhkan **OAuth Client ID**. Tidak ada Client Secret, authorization-code exchange, atau Authorized Redirect URI untuk flow ini.

Di Google Cloud Console:

1. Buat OAuth client bertipe **Web application**.
2. Pada **Authorized JavaScript origins**, tambahkan origin website production:

```text
https://lfamiliastore.my.id
```

3. **Authorized redirect URIs boleh dikosongkan** untuk flow GIS popup/button ini.
4. Salin Client ID lalu simpan dari **Super Admin → Integrasi → Google Login**.

Client ID tidak di-hardcode di repository. Nilainya dikelola dari panel Integrasi dan disimpan di konfigurasi terenkripsi D1 menggunakan `INTEGRATION_ENCRYPTION_KEY`. Backend hanya menerima ID token Google dari browser, lalu memverifikasi signature melalui JWKS Google, issuer, audience (`Client ID`), masa berlaku token, dan `email_verified` sebelum membuat sesi pelanggan.

Jika email Google sama dengan akun LFAMILIA yang sudah aktif, akun Google ditautkan ke akun tersebut. Jika belum ada, akun pelanggan baru dibuat otomatis. Relasi Google memakai claim `sub` sebagai identitas provider yang unik.

Migration schema terkait:

```text
drizzle/0036_customer_google_oauth.sql
```

## Ulasan pembeli guest

Akun tidak lagi menjadi syarat mutlak untuk memberi ulasan. Pembeli guest dapat mengirim ulasan bila backend berhasil mencocokkan **invoice yang sudah paid**, produk, dan nomor WhatsApp checkout. Satu order hanya dapat dipakai untuk satu ulasan. Nama reviewer disamarkan di frontend dan data sensitif seperti nomor WhatsApp atau invoice lengkap tidak ditampilkan bersama ulasan.
