# Konfigurasi Integrasi LFAMILIA STORE

Arsitektur aktif LFAMILIA:

- **Payment gateway:** DOKU Direct API / SNAP
- **Provider otomatis:** DigiFlazz
- **Produk manual / stok internal:** diproses oleh LFAMILIA
- **Halaman pembayaran:** dimiliki dan dirender oleh LFAMILIA, bukan Hosted Checkout DOKU

## 1. Persiapan database DOKU

Tidak perlu menjalankan Wrangler dari Cloudflare.

Buka:

**Admin Panel → Integrasi & harga → Persiapan database DOKU**

Tekan **Persiapkan Database DOKU** satu kali sebelum mengaktifkan pembayaran. Proses ini menyiapkan kolom DOKU, menonaktifkan pembayaran selama persiapan, membersihkan transaksi pra-rilis, mempertahankan katalog/akun/staff, dan menyimpan marker agar pembersihan tidak dijalankan ulang pada transaksi baru.

## 2. Root encryption Cloudflare

Cloudflare hanya perlu menyimpan root secret Integration Manager:

```text
INTEGRATION_ENCRYPTION_KEY
```

Gunakan nilai acak minimal 32 karakter. Jangan menggantinya setelah credential terenkripsi tersimpan di D1.

## 3. DOKU Direct API / SNAP

Buka:

**Admin Panel → Integrasi & harga → Kredensial API & callback → DOKU Direct API**

Simpan profil Sandbox dan Production secara terpisah. Field yang tersedia:

- Client ID
- Secret Key
- RSA Private Key PKCS#8
- Private Key Passphrase, hanya jika key memakai passphrase
- Direct API Base URL opsional
- QRIS Merchant ID / Mall ID
- QRIS Terminal ID
- QRIS Postal Code
- konfigurasi Virtual Account per bank dalam JSON

Base URL default:

- Sandbox: `https://api-sandbox.doku.com`
- Production: `https://api.doku.com`

RSA private key hanya disimpan terenkripsi oleh LFAMILIA. Public key pasang/daftarkan ke DOKU sesuai proses aktivasi SNAP. Jangan menaruh private key di GitHub.

Flow aktif:

- QRIS: `/snap-adapter/b2b/v1.0/qr/qr-mpm-generate`
- Query QRIS: `/snap-adapter/b2b/v1.0/qr/qr-mpm-query`
- DANA / ShopeePay: `/direct-debit/core/v1/debit/payment-host-to-host`
- Virtual Account: `/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va`
- B2B token: `/authorization/v1/access-token/b2b`

QRIS dan nomor Virtual Account ditampilkan langsung di halaman pembayaran LFAMILIA. DANA/ShopeePay menggunakan redirect hanya pada tahap otorisasi pelanggan.

Notification URL LFAMILIA:

```text
https://lfamiliastore.my.id/api/payments/doku/callback
```

Pasang URL tersebut pada konfigurasi Notification URL metode pembayaran DOKU yang diaktifkan. Endpoint LFAMILIA memverifikasi format signature SNAP (`X-SIGNATURE`) maupun notification Direct API non-SNAP (`Signature: HMACSHA256=...`) karena format notifikasi DOKU dapat berbeda per metode.

Setelah credential siap, buka menu **Pembayaran** untuk mengaktifkan DOKU pada checkout dan/atau top up wallet, kemudian sync metode pembayaran.

## 4. Halaman pembayaran LFAMILIA

Buka **Admin Panel → Pembayaran → Halaman pembayaran**.

Pemilik dapat mengubah branding, gambar header, warna, judul/pesan status, pengingat invoice, teks tombol, bantuan, dan elemen yang ditampilkan. QRIS/VA tetap berasal dari DOKU, tetapi UI pembayaran berada di LFAMILIA.

## 5. DigiFlazz

Buka **Integrasi & harga → Kredensial API & callback → DigiFlazz**.

Isi Username, API Key, Transaction API URL, Price List URL, dan Webhook Secret.

Webhook:

```text
https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback
```

Produk otomatis eksternal hanya memakai DigiFlazz. Produk manual tidak membutuhkan provider eksternal.

## 6. VPS Relay

VPS relay hanya digunakan untuk DigiFlazz jika membutuhkan IP keluar statis.

Di **Admin Panel → VPS Relay** isi DigiFlazz Relay URL dan Relay Token. Gunakan source `relay/server.mjs` dan `relay/Caddyfile.example` terbaru di VPS.

DOKU Direct API berjalan langsung dari Worker dan tidak melewati VPS relay.

## 7. Cloudflare Access

Area Owner tetap dilindungi Cloudflare Access.

Worker membutuhkan:

```text
TEAM_DOMAIN=https://lfamilia.cloudflareaccess.com
POLICY_AUD=<Application Audience aplikasi Access LFAMILIA>
```

Credential DOKU dan DigiFlazz tidak ditempatkan di Cloudflare Variables/Secrets. Credential tersebut dikelola dari Admin Panel dan dienkripsi di D1.

## 8. Sebelum membuka toko

Pastikan Persiapan database DOKU sudah siap, Sandbox diuji end-to-end untuk setiap metode yang akan diaktifkan, notification tervalidasi, credential Production sudah lengkap, QRIS/VA benar-benar menampilkan artefak pembayaran di halaman LFAMILIA, DigiFlazz SKU/harga/margin sudah diverifikasi, relay DigiFlazz sehat bila digunakan, produk manual masuk antrean setelah lunas, dan tidak ada credential provider di GitHub.
