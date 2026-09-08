# LFAMILIA Integration Configuration

Arsitektur aktif:

- **Payment gateway:** DOKU Direct API / SNAP
- **Provider produk otomatis:** DigiFlazz
- **Produk lain:** manual / stok internal LFAMILIA

Credential operasional disimpan terenkripsi dari Admin Panel. DOKU Client ID, Secret Key, RSA private key, dan DigiFlazz API key tidak ditulis di repository.

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

## VPS Relay

Relay hanya untuk DigiFlazz:

```text
DigiFlazz Relay URL
Relay Token
```

DOKU tidak menggunakan VPS relay. Lihat `relay/README.md`.
