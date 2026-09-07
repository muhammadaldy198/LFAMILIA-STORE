# LFAMILIA Integration Configuration

Arsitektur aktif:

- **Payment gateway:** DOKU Checkout
- **Provider produk otomatis:** DigiFlazz
- **Produk lain:** manual / stok internal LFAMILIA

Credential operasional disimpan terenkripsi dari Admin Panel. Tidak ada DOKU Client ID, DOKU Secret Key, atau DigiFlazz API key yang perlu ditulis di repository.

## Cloudflare root secret

Satu-satunya root secret Integration Manager yang tetap berada di Cloudflare adalah:

```text
INTEGRATION_ENCRYPTION_KEY
```

Minimal 32 karakter. Jangan menggantinya setelah credential tersimpan di D1.

## DOKU

Admin Panel menyimpan profil Sandbox dan Production secara terpisah:

```text
DOKU_SANDBOX_CLIENT_ID
DOKU_SANDBOX_SECRET_KEY
DOKU_SANDBOX_API_URL

DOKU_PRODUCTION_CLIENT_ID
DOKU_PRODUCTION_SECRET_KEY
DOKU_PRODUCTION_API_URL
```

Nama di atas adalah runtime internal hasil hidrasi D1, bukan Variable/Secret Cloudflare yang harus dibuat manual.

Endpoint resmi default:

- Sandbox: `https://api-sandbox.doku.com/checkout/v1/payment`
- Production: `https://api.doku.com/checkout/v1/payment`

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
