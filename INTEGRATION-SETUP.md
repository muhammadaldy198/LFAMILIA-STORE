# Konfigurasi Integrasi LFAMILIA STORE

Arsitektur aktif LFAMILIA:

- **Payment gateway:** DOKU Checkout
- **Provider otomatis:** DigiFlazz
- **Produk manual / stok internal:** diproses oleh LFAMILIA

## 1. Persiapan database DOKU

Tidak perlu menjalankan Wrangler dari Cloudflare.

Buka:

**Admin Panel → Integrasi & harga → Persiapan database DOKU**

Tekan **Persiapkan Database DOKU** satu kali sebelum mengaktifkan pembayaran. Proses ini:

- menambahkan kolom DOKU yang belum tersedia;
- menonaktifkan checkout/top up DOKU selama persiapan;
- membersihkan transaksi pra-rilis;
- mempertahankan produk, akun pelanggan, staff, katalog, dan konfigurasi toko;
- menyimpan marker satu-kali agar transaksi baru tidak ikut dibersihkan pada penggunaan berikutnya.

## 2. Root encryption Cloudflare

Cloudflare hanya perlu menyimpan root secret Integration Manager:

```text
INTEGRATION_ENCRYPTION_KEY
```

Gunakan nilai acak minimal 32 karakter. Jangan menggantinya setelah credential terenkripsi tersimpan di D1.

## 3. DOKU

Buka:

**Admin Panel → Integrasi & harga → Kredensial API & callback → DOKU Checkout**

Simpan credential Sandbox dan Production secara terpisah:

- Client ID
- Secret Key
- Checkout API URL opsional

Endpoint default:

- Sandbox: `https://api-sandbox.doku.com/checkout/v1/payment`
- Production: `https://api.doku.com/checkout/v1/payment`

Pilih environment aktif dari Admin Panel.

Notification URL:

```text
https://lfamiliastore.my.id/api/payments/doku/callback
```

Setelah credential siap, buka menu **Pembayaran** untuk mengaktifkan DOKU pada checkout dan/atau top up wallet.

## 4. DigiFlazz

Buka **Integrasi & harga → Kredensial API & callback → DigiFlazz**.

Isi:

- Username
- API Key
- Transaction API URL
- Price List URL
- Webhook Secret

Webhook:

```text
https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback
```

Produk otomatis eksternal hanya memakai DigiFlazz. Produk manual tidak membutuhkan provider eksternal.

## 5. VPS Relay

VPS relay hanya digunakan untuk DigiFlazz jika membutuhkan IP keluar statis.

Di Admin Panel → VPS Relay isi:

- DigiFlazz Relay URL
- Relay Token

Gunakan source `relay/server.mjs` dan `relay/Caddyfile.example` terbaru di VPS.

DOKU berjalan langsung dari Worker dan tidak melewati VPS relay.

## 6. Cloudflare Access

Area Owner tetap dilindungi Cloudflare Access.

Worker membutuhkan:

```text
TEAM_DOMAIN=https://lfamilia.cloudflareaccess.com
POLICY_AUD=<Application Audience aplikasi Access LFAMILIA>
```

Credential DOKU dan DigiFlazz tidak ditempatkan di Cloudflare Variables/Secrets.

## 7. Sebelum membuka toko

Pastikan:

- Persiapan database DOKU sudah berstatus siap.
- DOKU Sandbox berhasil diuji end-to-end.
- Notification DOKU tervalidasi.
- Credential Production DOKU sudah diisi sebelum go-live.
- DigiFlazz SKU, harga, dan margin sudah diverifikasi.
- Relay DigiFlazz sehat bila memang digunakan.
- Produk manual masuk antrean Admin setelah pembayaran lunas.
- Tidak ada credential provider di GitHub.
