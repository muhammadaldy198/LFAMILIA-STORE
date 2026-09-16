# LFAMILIA Integration Configuration

Arsitektur aktif:

- **Payment gateway:** DOKU Checkout / Direct API dan Midtrans Snap / BI-SNAP sesuai routing channel yang diaktifkan dari Admin Panel
- **Provider produk otomatis:** DigiFlazz
- **Validasi akun/nickname:** KokinPay, server-side
- **Produk lain:** manual / stok internal LFAMILIA

Credential operasional disimpan terenkripsi dari Admin Panel. Credential payment, DigiFlazz API key, dan KokinPay API key tidak ditulis di repository.

## Cloudflare root secret

Satu-satunya root secret Integration Manager yang tetap berada di Cloudflare adalah:

```text
INTEGRATION_ENCRYPTION_KEY
```

Minimal 32 karakter. Jangan menggantinya setelah credential tersimpan di D1.

## DOKU Direct API

Admin Panel menyimpan profil Sandbox dan Production secara terpisah. Runtime internal hasil hidrasi D1 menggunakan field berikut:

```text
DOKU_SANDBOX_CLIENT_ID
DOKU_SANDBOX_SECRET_KEY
DOKU_SANDBOX_PRIVATE_KEY
DOKU_SANDBOX_PRIVATE_KEY_PASSPHRASE
DOKU_SANDBOX_API_URL
DOKU_SANDBOX_QRIS_MERCHANT_ID
DOKU_SANDBOX_QRIS_TERMINAL_ID
DOKU_SANDBOX_QRIS_POSTAL_CODE
DOKU_SANDBOX_VA_CONFIG_JSON

DOKU_PRODUCTION_CLIENT_ID
DOKU_PRODUCTION_SECRET_KEY
DOKU_PRODUCTION_PRIVATE_KEY
DOKU_PRODUCTION_PRIVATE_KEY_PASSPHRASE
DOKU_PRODUCTION_API_URL
DOKU_PRODUCTION_QRIS_MERCHANT_ID
DOKU_PRODUCTION_QRIS_TERMINAL_ID
DOKU_PRODUCTION_QRIS_POSTAL_CODE
DOKU_PRODUCTION_VA_CONFIG_JSON
```

Nama tersebut adalah runtime internal, bukan Variable/Secret Cloudflare yang harus dibuat manual.

Base URL default:

- Sandbox: `https://api-sandbox.doku.com`
- Production: `https://api.doku.com`

Payment initiation memakai B2B token SNAP, RSA SHA256 untuk token signature, dan HMAC-SHA512 untuk transactional request. Notification handler juga memverifikasi signature non-SNAP HMAC-SHA256 yang masih digunakan DOKU pada beberapa notification flow.

## DigiFlazz

```text
DIGIFLAZZ_USERNAME
DIGIFLAZZ_DEVELOPMENT_API_KEY
DIGIFLAZZ_PRODUCTION_API_KEY
DIGIFLAZZ_DEVELOPMENT_API_URL
DIGIFLAZZ_PRODUCTION_API_URL
DIGIFLAZZ_DEVELOPMENT_PRICE_LIST_URL
DIGIFLAZZ_PRODUCTION_PRICE_LIST_URL
DIGIFLAZZ_WEBHOOK_SECRET
```

Semua credential diisi melalui Integration Manager.

## KokinPay Validasi Akun

KokinPay hanya dipakai server untuk validasi akun/nickname. API key disimpan dari **Super Admin → Integrasi → KokinPay** dan dihidrasi sebagai runtime internal:

```text
KOKINPAY_API_KEY
```

Jangan membuat `KOKINPAY_API_KEY` sebagai Cloudflare Variable/Secret terpisah. Sumber credential aktif adalah profil terenkripsi di Admin/D1.

Endpoint backend aktif:

```text
https://api.kokinpay.com/v1/check-nickname
https://api.kokinpay.com/v1/check-region
https://api.kokinpay.com/v1/check-pln
```

Menu **Validasi Akun** dipakai untuk tes nickname game, nickname + region Mobile Legends, PLN, dan melihat daftar game code. Produk mengaktifkan validasi melalui **Produk → Input Customer → Kode Game Nickname**. Kode kosong berarti produk tidak menjalankan validasi nickname.

Mobile Legends membutuhkan User ID + Server/Zone dan wajib lolos nickname serta region. Checkout melakukan verifikasi ulang di server sebelum order/pembayaran dibuat; hasil dari browser tidak dipercaya sebagai sumber kebenaran.

## VPS Relay

Relay hanya digunakan untuk integrasi yang memang memerlukan egress/IP statis sesuai konfigurasi Admin. Credential merchant tetap disimpan terenkripsi di D1 dan tidak ditaruh di VPS.

Lihat `relay/README.md` untuk konfigurasi relay yang sedang dipakai.
