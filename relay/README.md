# LFAMILIA DigiFlazz Relay

Relay VPS hanya digunakan untuk **DigiFlazz** ketika IP keluar statis diperlukan. DOKU tidak melewati relay ini.

Semua nilai operasional dibaca dari `/etc/lfamilia-relay.env`; API key DigiFlazz tetap dikelola terenkripsi dari Admin Panel dan tidak ditulis ke source relay.

## Environment VPS

```env
RELAY_BIND_HOST=<bind-host>
RELAY_BIND_PORT=<bind-port>
RELAY_UPSTREAM=<caddy-reverse-proxy-target>
RELAY_TOKEN=<secret-random>
RELAY_MAX_BODY_BYTES=<max-request-bytes>
RELAY_UPSTREAM_TIMEOUT_MS=<provider-timeout-ms>
RELAY_REQUEST_TIMEOUT_BUFFER_MS=<request-timeout-buffer-ms>
RELAY_HEADERS_TIMEOUT_MS=<headers-timeout-ms>

DIGIFLAZZ_RELAY_HOST=digiflazz-relay.lfamiliastore.my.id
DIGIFLAZZ_DEVELOPMENT_UPSTREAM_ORIGIN=https://api.digiflazz.com
DIGIFLAZZ_PRODUCTION_UPSTREAM_ORIGIN=https://api.digiflazz.com
```

Worker mengirim `x-lfamilia-digiflazz-environment` (`development` atau `production`) serta relay token. Header internal tidak diteruskan ke DigiFlazz.

## Admin Panel

Isi dari **Admin Panel → Integrasi & harga → VPS Relay**:

- DigiFlazz Relay URL
- Relay Token

Nilai disimpan terenkripsi di D1. Token harus sama persis dengan `RELAY_TOKEN` pada VPS.

## Caddy

Gunakan hanya host DigiFlazz:

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
```

Setelah source relay diperbarui di VPS, restart service relay dan reload Caddy. Domain relay iPaymu/BI-SNAP lama tidak lagi diperlukan.
