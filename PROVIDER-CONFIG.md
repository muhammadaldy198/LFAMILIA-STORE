# Provider Environment Matrix

Konfigurasi provider LFAMILIA bersifat environment-explicit. Credential dan pilihan environment operasional disimpan terenkripsi dari Admin Panel. Production credential boleh kosong sampai onboarding selesai. Nama variabel di bawah adalah nama runtime internal yang dibentuk dari konfigurasi panel, bukan daftar Variable/Secret yang harus dibuat manual di Cloudflare.

## Selector Admin Panel

Atur dari **Admin Panel → Integrasi & harga → Kredensial API & callback**:

- Midtrans memakai Snap.
- Environment Midtrans: Sandbox atau Production
- Environment iPaymu: Sandbox atau Production
- Environment DigiFlazz: Development atau Production

Runtime internal memakai `MIDTRANS_ENV`, `IPAYMU_ENV`, dan `DIGIFLAZZ_ENV`; Midtrans selalu menggunakan Snap. Nilainya dihidrasi dari D1 oleh Integration Manager.

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
Relay Token
```

Tidak perlu membuat `PROVIDER_RELAY_*` manual di Cloudflare. Worker membentuk runtime relay dari profile terenkripsi tersebut.

## Switching to Production

1. Buka **Admin Panel → Integrasi & harga → Kredensial API & callback**.
2. Isi credential pada slot Production provider terkait.
3. Ubah selector environment menjadi **Production** lalu simpan.
4. Jangan mengubah repo, Caddy, atau service VPS hanya untuk berpindah environment.

Tidak ada fallback otomatis ke Production hanya karena credential Production sudah tersedia. `INTEGRATION_ENCRYPTION_KEY` tetap satu-satunya root secret integrasi yang wajib berada di Cloudflare.


## Security root

Credential provider di Integration Manager dienkripsi sebelum masuk D1. Satu-satunya root secret yang wajib tetap berada di Cloudflare adalah `INTEGRATION_ENCRYPTION_KEY` (minimal 32 karakter). Jika root secret berubah, profile lama tidak dapat didekripsi dan panel akan menandainya sebagai **Kunci enkripsi tidak cocok**.
