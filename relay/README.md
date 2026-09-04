# LFAMILIA Provider Relay

Relay berjalan di VPS ber-IP publik statis. Source relay tidak memiliki fallback hostname, upstream provider, bind address, port, timeout, token, environment, VA, API key, atau credential provider.

Semua nilai operasional relay dibaca dari `/etc/lfamilia-relay.env`.

## Routing

Worker selalu mengirim environment secara eksplisit:

- DigiFlazz: `development` atau `production`
- iPaymu: `sandbox` atau `production`
- Midtrans BI-SNAP: `sandbox` atau `production`

Relay menolak request yang environment-nya tidak valid atau upstream environment tersebut belum dikonfigurasi. Relay tidak menggunakan pola "selain sandbox = production".

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

DIGIFLAZZ_RELAY_HOST=<relay-host>
DIGIFLAZZ_DEVELOPMENT_UPSTREAM_ORIGIN=<development-origin>
DIGIFLAZZ_PRODUCTION_UPSTREAM_ORIGIN=<production-origin>

IPAYMU_RELAY_HOST=<relay-host>
IPAYMU_SANDBOX_UPSTREAM_ORIGIN=<sandbox-origin>
IPAYMU_PRODUCTION_UPSTREAM_ORIGIN=<production-origin>

MIDTRANS_BISNAP_RELAY_HOST=<relay-host>
MIDTRANS_BISNAP_SANDBOX_UPSTREAM_ORIGIN=<sandbox-origin>
MIDTRANS_BISNAP_PRODUCTION_UPSTREAM_ORIGIN=<production-origin>
```

Tidak ada VA iPaymu di VPS. Tidak ada API key provider di VPS. Environment dipilih oleh Worker melalui header internal yang dilindungi relay token.

## Authentication relay

Cloudflare Worker menyimpan:

```text
PROVIDER_RELAY_TOKEN
PROVIDER_RELAY_HOSTS
```

`PROVIDER_RELAY_TOKEN` harus sama dengan `RELAY_TOKEN` di VPS.

Header internal relay tidak diteruskan ke provider.

## systemd

Unit service hanya menunjuk file env dan source relay:

```ini
[Unit]
Description=LFAMILIA Provider Relay
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=/opt/lfamilia-relay
EnvironmentFile=/etc/lfamilia-relay.env
ExecStart=/usr/bin/node /opt/lfamilia-relay/server.mjs
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

Tidak ada domain, IP, port, upstream, atau token yang ditulis langsung di unit service.

## Caddy

`relay/Caddyfile.example` membaca hostname dan target reverse proxy dari environment:

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

{$IPAYMU_RELAY_HOST} {
  import lfamilia_provider_relay
}

{$MIDTRANS_BISNAP_RELAY_HOST} {
  import lfamilia_provider_relay
}
```

## Pergantian Production

VPS disiapkan untuk seluruh environment sejak awal. Setelah credential Production tersedia, perubahan dilakukan pada Cloudflare:

1. isi credential Production;
2. ubah selector environment;
3. Worker mengirim environment baru ke relay;
4. relay memakai upstream Production yang sudah tersedia di env VPS.

Tidak perlu mengubah source relay, Caddy, systemd, atau SSH ke VPS saat pergantian environment.

## Callback

Callback provider masuk langsung ke domain publik Worker, bukan melalui VPS relay.

Midtrans Snap tetap dapat berjalan langsung Worker ke Midtrans. BI-SNAP, DigiFlazz, dan iPaymu dapat memakai relay bila membutuhkan IP keluar statis.
