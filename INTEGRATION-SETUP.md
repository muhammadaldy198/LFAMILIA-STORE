# Konfigurasi Integrasi LFAMILIA STORE

Integrasi pembayaran yang didukung hanya **DOKU Direct API** dan **Midtrans Snap** untuk checkout maupun top up saldo. DOKU Hosted Checkout tidak digunakan dan tidak memiliki jalur pembuatan pembayaran aktif.

Credential pembayaran diatur dari **Super Admin → Integrasi → DOKU Direct API / Midtrans Snap**. Credential disimpan terpisah untuk Sandbox dan Production dan dienkripsi di D1 menggunakan root secret `INTEGRATION_ENCRYPTION_KEY` (minimal 32 karakter). Menu **Pembayaran** hanya mengatur operasional seperti gateway aktif/nonaktif, environment aktif, routing top up, channel pembayaran, tampilan pembayaran, dan transaksi.

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

Di **Super Admin → Pembayaran**, DOKU Direct API dan Midtrans memiliki toggle aktif/nonaktif yang terpisah dari status kesiapan credential. Top up saldo juga mempunyai pilihan gateway sendiri (`DOKU Direct API` atau `Midtrans Snap`) dan master toggle top up otomatis. Gateway top up yang dipilih harus berstatus siap dan aktif; backend tidak melakukan fallback diam-diam ke gateway lain.

DOKU Direct API berjalan langsung dari Worker LFAMILIA dan tidak memakai VPS relay. VPS relay tetap khusus Digiflazz. Redirect halaman pembayaran tidak pernah dianggap sebagai bukti pembayaran; backend hanya mengubah order menjadi `paid` setelah callback/status gateway tervalidasi. Digiflazz baru dipanggil setelah status pembayaran benar-benar `paid`.

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


## WhatsApp OTP pelanggan

Nomor WhatsApp pelanggan diverifikasi melalui **Meta WhatsApp Cloud API**. Credential tidak ditulis di source code atau Cloudflare Variables biasa; simpan dari **Super Admin → Integrasi → WhatsApp OTP** agar terenkripsi menggunakan `INTEGRATION_ENCRYPTION_KEY`.

Field wajib:

```text
Graph API URL       contoh: https://graph.facebook.com/vXX.X
Phone Number ID     ID nomor WhatsApp Business pengirim
Access Token        token yang mempunyai izin mengirim pesan WhatsApp
Template Name       nama template OTP/authentication yang sudah disetujui
Template Language   contoh: id
OTP Button Subtype  kosong, url, atau quick_reply sesuai template
```

Template harus mempunyai satu variabel body untuk kode OTP 6 digit. Bila template memakai tombol OTP, pilih subtype yang sesuai dengan template yang disetujui Meta. Tombol **Periksa** di panel hanya menyatakan konfigurasi wajib lengkap dan dapat dibaca backend; pengiriman nyata tetap harus diuji dengan nomor WhatsApp yang dapat menerima template.

Flow akun:

```text
Google / daftar email
        ↓
session dibuat
        ↓
phone_verified_at ada?
   ├─ ya  → dashboard customer
   └─ tidak
        ↓
masukkan nomor WhatsApp
        ↓
OTP WhatsApp 6 digit
        ↓
verifikasi sukses
        ↓
dashboard customer
```

OTP berlaku 5 menit, resend memiliki cooldown, percobaan kode dibatasi, dan database hanya menyimpan hash OTP beserta salt. Nomor disimpan dalam format internasional `+62...`. Nomor terverifikasi tidak dapat diganti langsung dari form profil; perubahan nomor harus melewati verifikasi OTP kembali.

Migration terkait:

```text
drizzle/0037_customer_phone_whatsapp_reviews.sql
```

**Urutan deploy production:** jalankan migration 0037 di D1 terlebih dahulu, deploy code, lalu isi/cek WhatsApp OTP dari menu Integrasi. Jangan deploy code yang membaca `phone_verified_at` sebelum schema 0037 tersedia di database production.

## Ulasan pembeli guest

Akun tidak lagi menjadi syarat mutlak untuk memberi ulasan. Pembeli guest dapat mengirim ulasan bila backend berhasil mencocokkan **invoice yang sudah paid**, produk, dan nomor WhatsApp checkout. Satu order hanya dapat dipakai untuk satu ulasan. Nama reviewer disamarkan di frontend dan data sensitif seperti nomor WhatsApp atau invoice lengkap tidak ditampilkan bersama ulasan.
