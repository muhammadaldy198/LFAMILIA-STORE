# Provider Environment Matrix

Konfigurasi provider LFAMILIA bersifat environment-explicit. Production credential boleh kosong sampai onboarding selesai. Source hanya membaca slot environment yang dipilih.

## Selectors

```text
MIDTRANS_ENV=sandbox|production
MIDTRANS_MODE=snap|bisnap
IPAYMU_ENV=sandbox|production
DIGIFLAZZ_ENV=development|production
```

## Midtrans Snap

```text
MIDTRANS_SNAP_SANDBOX_SERVER_KEY
MIDTRANS_SNAP_PRODUCTION_SERVER_KEY
MIDTRANS_SNAP_SANDBOX_CLIENT_KEY
MIDTRANS_SNAP_PRODUCTION_CLIENT_KEY
MIDTRANS_SNAP_SANDBOX_API_URL
MIDTRANS_SNAP_PRODUCTION_API_URL
MIDTRANS_SNAP_SANDBOX_SCRIPT_URL
MIDTRANS_SNAP_PRODUCTION_SCRIPT_URL
```

## Midtrans BI-SNAP

Common:

```text
MIDTRANS_BISNAP_TIMEZONE_OFFSET
MIDTRANS_BISNAP_CURRENCY
MIDTRANS_BISNAP_DEVICE_ID
MIDTRANS_BISNAP_PAYMENT_EXPIRY_MINUTES
MIDTRANS_BISNAP_TOKEN_EXPIRY_SAFETY_SECONDS
```

Gunakan prefix `MIDTRANS_BISNAP_SANDBOX_` dan `MIDTRANS_BISNAP_PRODUCTION_` untuk setiap slot berikut:

```text
CLIENT_ID
PRIVATE_KEY
CLIENT_SECRET
PARTNER_ID
CHANNEL_ID
MERCHANT_ID
VA_PARTNER_SERVICE_ID
VA_RANDOMIZE
QRIS_ACQUIRER
ACCESS_TOKEN_URL
DIRECT_DEBIT_URL
QRIS_URL
VA_URL
PUBLIC_KEY
```

Callback BI-SNAP:

```text
<PUBLIC_BASE_URL>/v1.0/debit/notify
<PUBLIC_BASE_URL>/v1.0/qr/qr-mpm-notify

Virtual Account BI-SNAP tetap memakai notification legacy Midtrans:
<PUBLIC_BASE_URL>/api/payments/midtrans/callback
```

## iPaymu

```text
IPAYMU_SANDBOX_VA
IPAYMU_SANDBOX_API_KEY
IPAYMU_SANDBOX_API_URL

IPAYMU_PRODUCTION_VA
IPAYMU_PRODUCTION_API_KEY
IPAYMU_PRODUCTION_API_URL
```

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

## Service Integration Manager

Selain provider pembayaran, panel dapat menyimpan terenkripsi:

```text
MELOSTORE_API_KEY
MELOSTORE_SECRET_KEY
MELOSTORE_API_URL
NICKNAME_API_KEY
RESEND_API_KEY
RESEND_FROM_EMAIL
RESEND_API_URL
VOUCHER_DELIVERY_CHANNEL=website|email
PROVIDER_RELAY_TOKEN
PROVIDER_RELAY_HOSTS
VOUCHER_ENCRYPTION_KEY
```

`INTEGRATION_ENCRYPTION_KEY` tetap Cloudflare Secret root minimal 32 karakter dan tidak disimpan di D1.

## VPS Relay

Relay dikonfigurasi dari **Admin Panel → Integrasi & harga → VPS Relay** dan disimpan terenkripsi di D1.

Field Admin Panel:

```text
DigiFlazz Relay URL
iPaymu Relay URL
BI-SNAP Relay URL
Relay Token
```

Tidak perlu membuat `PROVIDER_RELAY_*` manual di Cloudflare. Worker membentuk runtime relay dari profile terenkripsi tersebut.

## Switching to Production

1. Isi slot Production di Cloudflare.
2. Ubah selector terkait ke `production`.
3. Jangan mengubah repo.
4. Jangan mengubah VPS.
5. Jangan mengubah Caddy.

Tidak ada fallback otomatis ke Production karena key tersedia, prefix key, VA, atau nilai lainnya.


## Security root

Credential provider di Integration Manager dienkripsi sebelum masuk D1. Satu-satunya root secret yang wajib tetap berada di Cloudflare adalah `INTEGRATION_ENCRYPTION_KEY` (minimal 32 karakter). Jika root secret berubah, profile lama tidak dapat didekripsi dan panel akan menandainya sebagai **Kunci enkripsi tidak cocok**.
