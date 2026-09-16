# Konfigurasi Integrasi LFAMILIA STORE

Arsitektur aktif LFAMILIA:

- **Payment gateway:** DOKU Checkout / Direct API dan Midtrans Snap / BI-SNAP sesuai routing channel yang diaktifkan di Admin Panel
- **Provider otomatis:** DigiFlazz
- **Validasi akun/nickname:** KokinPay, server-side
- **Produk manual / stok internal:** diproses oleh LFAMILIA
- **Halaman pembayaran:** UI pelanggan tetap milik LFAMILIA; nama provider/gateway tidak perlu ditampilkan ke pelanggan

## 1. Root encryption Cloudflare

Cloudflare hanya perlu menyimpan root secret Integration Manager:

```text
INTEGRATION_ENCRYPTION_KEY
```

Gunakan nilai acak minimal 32 karakter. Jangan menggantinya setelah credential terenkripsi tersimpan di D1.

Credential DOKU, Midtrans, DigiFlazz, KokinPay, dan layanan lain yang didukung panel disimpan melalui **Super Admin → Integrasi** dan dienkripsi di D1. Jangan menaruh credential tersebut di repository.

## 2. DOKU

Buka **Super Admin → Integrasi** lalu pilih DOKU Checkout atau DOKU Direct API sesuai mode yang digunakan. Profil Sandbox dan Production dipisahkan.

Base URL Direct API:

- Sandbox: `https://api-sandbox.doku.com`
- Production: `https://api.doku.com`

Notification URL LFAMILIA:

```text
https://lfamiliastore.my.id/api/payments/doku/callback
```

DOKU Direct API berjalan langsung dari Worker dan tidak melalui VPS relay.

## 3. Midtrans

Buka **Super Admin → Integrasi** lalu pilih Midtrans Snap atau Midtrans BI-SNAP sesuai mode yang digunakan. Profil Sandbox dan Production dipisahkan.

Notification URL Snap dan BI-SNAP yang aktif ditampilkan langsung di menu Integrasi agar dapat disalin ke dashboard Midtrans. Midtrans BI-SNAP dapat memakai VPS relay untuk egress/IP statis; credential tetap tersimpan terenkripsi di Admin/D1 dan tidak disimpan di VPS.

## 4. DigiFlazz

Buka **Super Admin → Integrasi → DigiFlazz**.

Isi Username, API Key, Transaction API URL, Price List URL, dan Webhook Secret.

Webhook:

```text
https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback
```

Menu DigiFlazz operasional dipakai untuk sinkronisasi/monitoring. Credential tetap dikelola dari menu Integrasi.

## 5. KokinPay Validasi Akun

Buka **Super Admin → Integrasi → KokinPay** dan simpan API key. API key hanya tersedia di server setelah didekripsi dari D1 dan tidak dikirim ke browser pelanggan.

Backend memakai endpoint aktif berikut:

```text
https://api.kokinpay.com/v1/check-nickname
https://api.kokinpay.com/v1/check-region
https://api.kokinpay.com/v1/check-pln
```

Untuk penggunaan operasional, menu **Validasi Akun** tersedia bagi **Admin dan Super Admin**. Di sana tersedia:

- cek nickname game;
- cek nickname + region Mobile Legends;
- cek nama pelanggan PLN;
- daftar game code yang dapat dipakai pada produk;
- penanda game code yang membutuhkan Server / Zone.

Staff tidak memiliki akses ke menu Validasi Akun. Pengaturan API key KokinPay tetap hanya dapat dilakukan Super Admin melalui menu Integrasi.

Setiap produk dapat diatur melalui **Produk → Input Customer → Kode Game Nickname**. Kode kosong berarti validasi nickname tidak dijalankan untuk produk tersebut. Produk yang memakai kode akan diverifikasi ulang oleh backend sebelum order/pembayaran dibuat, sehingga nickname dari browser tidak pernah menjadi sumber kebenaran.

Game code yang pada daftar KokinPay ditandai membutuhkan Server / Zone wajib memakai Checkout Type **ID + Server**. Backend mengecek aturan ini pada endpoint Input Customer, full product update, dan saat verifikasi checkout. Mobile Legends wajib melewati pengecekan nickname dan region. Genshin Impact juga membutuhkan Server dan compatibility repair menormalkan produk legacy menjadi field UID + Server.

Perubahan schema KokinPay menggunakan migration:

```text
0032_kokinpay_nickname_game_codes.sql
```

Migration menambahkan `products.nickname_game_code` dan mempertahankan konfigurasi nickname produk lama yang sudah didukung. Runtime compatibility repair memakai marker terpisah untuk backfill game code dan perbaikan input Genshin, sehingga database yang sudah pernah menjalankan versi backfill sebelumnya tetap mendapat perbaikan Server tanpa mengaktifkan ulang game code yang sengaja dikosongkan Admin.

## 6. Halaman pembayaran LFAMILIA

Buka **Admin Panel → Pembayaran** untuk mengatur metode/channel aktif, routing, dan tampilan halaman pembayaran. Pelanggan memilih metode pembayaran yang tersedia, bukan nama gateway.

Pastikan hanya channel yang benar-benar aktif pada merchant yang diaktifkan di panel.

## 7. VPS Relay

Relay digunakan hanya untuk integrasi yang memerlukan egress/IP statis sesuai konfigurasi Admin:

- DigiFlazz dapat memakai `https://digiflazz-relay.lfamiliastore.my.id`;
- Midtrans BI-SNAP dapat memakai relay Midtrans bila konfigurasi merchant memerlukan IP statis;
- DOKU tidak melalui relay.

Relay hanya meneruskan request. Credential merchant tetap disimpan di Admin/D1.

## 8. Cloudflare Access

Area Super Admin tetap dilindungi Cloudflare Access.

Worker membutuhkan:

```text
TEAM_DOMAIN=https://<team>.cloudflareaccess.com
POLICY_AUD=<Application Audience aplikasi Access LFAMILIA>
```

Endpoint `/admin/panel*` dan `/api/admin*` diverifikasi oleh Worker terhadap Cloudflare Access. Panel staff memakai autentikasi panel sesuai role dan tidak memperoleh akses ke menu Integrasi atau Validasi Akun.

## 9. Sebelum membuka toko

Pastikan migration production sudah sesuai branch yang akan dideploy, credential dapat didekripsi, payment channel diuji end-to-end, callback tervalidasi, DigiFlazz SKU/harga/margin diverifikasi, relay sehat bila digunakan, KokinPay berhasil memvalidasi akun nyata untuk game yang diaktifkan, produk tanpa dukungan nickname tidak diberi game code, game server-required benar-benar memakai ID + Server, dan tidak ada credential provider di GitHub atau response publik.
