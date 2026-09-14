# LFAMILIA Provider Relay

Relay VPS digunakan untuk dua kebutuhan terbatas:

- **DigiFlazz** — jalur provider existing ketika IP keluar statis diperlukan.
- **Midtrans BI-SNAP** — hanya sebagai egress/static-IP relay agar request merchant keluar dari IP VPS yang didaftarkan ke Midtrans.

**DOKU Direct API tidak menggunakan relay.**

Penting: credential merchant Midtrans (`Client ID`, `Client Secret`, `Partner ID`, private key, Merchant ID) **tidak disimpan di VPS**. Semua credential tersebut dikelola dari **Admin Panel → Integrasi**, disimpan terenkripsi di D1, dan request BI-SNAP sudah ditandatangani di backend LFAMILIA sebelum diteruskan relay.

## Environment VPS

```env
RELAY_BIND_HOST=<bind-host>
RELAY_BIND_PORT=<bind-port>
RELAY_TOKEN=<secret-random>
RELAY_MAX_BODY_BYTES=<max-request-bytes>
RELAY_UPSTREAM_TIMEOUT_MS=<provider-timeout-ms>
RELAY_REQUEST_TIMEOUT_BUFFER_MS=<request-timeout-buffer-ms>
RELAY_HEADERS_TIMEOUT_MS=<headers-timeout-ms>

DIGIFLAZZ_RELAY_HOST=digiflazz-relay.lfamiliastore.my.id
DIGIFLAZZ_DEVELOPMENT_UPSTREAM_ORIGIN=https://api.digiflazz.com
DIGIFLAZZ_PRODUCTION_UPSTREAM_ORIGIN=https://api.digiflazz.com

MIDTRANS_RELAY_HOST=midtrans-relay.lfamiliastore.my.id
MIDTRANS_SANDBOX_UPSTREAM_ORIGIN=https://merchants.sbx.midtrans.com
MIDTRANS_PRODUCTION_UPSTREAM_ORIGIN=https://merchants.midtrans.com
```

Jangan menambahkan credential payment Midtrans atau DOKU ke environment VPS.

Worker mengirim relay token dan environment provider pada setiap request. Header internal relay dihapus sebelum request diteruskan ke provider.

Untuk Midtrans, relay hanya mengizinkan endpoint outbound yang memang dipakai LFAMILIA:

- `POST /v1.0/access-token/b2b`
- `POST /v1.0/transfer-va/create-va`

Relay bukan generic/open proxy.

## Admin Panel

Buka **Admin Panel → Integrasi → Relay & Keamanan** lalu isi:

- DigiFlazz Relay URL
- Midtrans Relay URL
- Relay Token

Nilai konfigurasi Worker disimpan terenkripsi di D1. `Relay Token` di Admin harus sama dengan `RELAY_TOKEN` di VPS.

Credential DOKU dan Midtrans tetap di tab integrasi masing-masing, bukan di VPS.

## Caddy

```caddy
{
  admin off
}

(lfamilia_provider_relay) {
  reverse_proxy {$RELAY_UPSTREAM} {
    header_up Host {host}
  }
}

{$DIGIFLAZZ_RELAY_HOST} {
  import lfamilia_provider_relay
}

{$MIDTRANS_RELAY_HOST} {
  import lfamilia_provider_relay
}
```

Kedua hostname dapat mengarah ke service Node relay yang sama. Service memilih upstream berdasarkan hostname dan environment header internal.

## Setelah source relay diperbarui

1. Tambahkan environment Midtrans di service VPS.
2. Pastikan DNS/hostname `midtrans-relay.lfamiliastore.my.id` mengarah ke VPS.
3. Reload Caddy dan restart service relay.
4. Buka `/health` dan pastikan `configured.midtrans=true`.
5. Daftarkan **public outgoing IP VPS** ke Midtrans untuk BI-SNAP production.

Jangan apply perubahan production sampai konfigurasi Admin, callback, migration D1, dan uji sandbox/pre-production sudah lolos.
