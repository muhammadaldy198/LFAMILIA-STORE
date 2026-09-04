# LFAMILIA Provider Relay

Relay ini dijalankan di VPS ber-IP publik statis dan seluruh konfigurasi operasional dibaca dari environment. Tidak ada hostname provider, upstream API, bind address, port, timeout, atau token yang dijadikan fallback di source relay.

Midtrans Snap biasa tetap dapat berjalan langsung dari Worker ke Midtrans. Relay dipakai hanya untuk integrasi yang membutuhkan IP keluar statis.

## Keamanan

Relay bukan open proxy. Request provider wajib berupa `POST` dan membawa header:

`X-LFAMILIA-Relay-Token: <secret>`

Token hanya disimpan sebagai secret pada VPS dan Cloudflare Worker. Body, authorization header, signature, dan credential provider tidak dicatat ke log relay.

## Environment VPS

Buat `/etc/lfamilia-relay.env` dan isi nilainya sendiri. Jangan commit file ini:

```env
RELAY_BIND_HOST=<bind-host>
RELAY_BIND_PORT=<bind-port>
RELAY_UPSTREAM=<caddy-reverse-proxy-target>
RELAY_TOKEN=<secret-random-min-32-char>
RELAY_MAX_BODY_BYTES=<max-request-bytes>
RELAY_UPSTREAM_TIMEOUT_MS=<provider-timeout-ms>
RELAY_REQUEST_TIMEOUT_BUFFER_MS=<request-timeout-buffer-ms>
RELAY_HEADERS_TIMEOUT_MS=<headers-timeout-ms>

DIGIFLAZZ_RELAY_HOST=<digiflazz-relay-host>
DIGIFLAZZ_UPSTREAM_ORIGIN=<digiflazz-api-origin>

IPAYMU_RELAY_HOST=<ipaymu-relay-host>
IPAYMU_SANDBOX_UPSTREAM_ORIGIN=<ipaymu-sandbox-origin>
IPAYMU_PRODUCTION_UPSTREAM_ORIGIN=<ipaymu-production-origin>
IPAYMU_SANDBOX_VA=<ipaymu-sandbox-va>

MIDTRANS_BISNAP_RELAY_HOST=<midtrans-bisnap-relay-host>
MIDTRANS_BISNAP_UPSTREAM_ORIGIN=<midtrans-bisnap-api-origin>
MIDTRANS_BISNAP_AUTH_UPSTREAM_ORIGIN=<midtrans-bisnap-auth-origin>
MIDTRANS_BISNAP_AUTH_PATH_PREFIX=<midtrans-bisnap-auth-path-prefix>
```

Provider yang belum siap boleh memiliki hostname tetapi upstream-nya dikosongkan; relay akan mengembalikan status konfigurasi belum tersedia dan tidak meneruskan request. Relay iPaymu memilih Sandbox saat header VA sama dengan `IPAYMU_SANDBOX_VA`; VA lainnya diarahkan ke Production, sehingga perpindahan iPaymu tidak memerlukan perubahan VPS. Relay BI-SNAP mendukung GET dan POST; auth-code dapat diarahkan ke origin terpisah melalui `MIDTRANS_BISNAP_AUTH_UPSTREAM_ORIGIN` dan `MIDTRANS_BISNAP_AUTH_PATH_PREFIX`.

## systemd

Contoh unit `/etc/systemd/system/lfamilia-relay.service`:

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

Caddy juga membaca hostname dan target reverse proxy dari environment melalui `relay/Caddyfile.example`.

## Cloudflare Worker

Cloudflare menyimpan konfigurasi relay sebagai Variable/Secret, bukan source code:

- Secret `PROVIDER_RELAY_TOKEN` = nilai yang sama dengan `RELAY_TOKEN` di VPS.
- Variable `PROVIDER_RELAY_HOSTS` = daftar hostname relay yang diizinkan, dipisahkan koma.
- `DIGIFLAZZ_API_URL` dan `DIGIFLAZZ_PRICE_LIST_URL` diarahkan ke hostname relay DigiFlazz setelah relay aktif.

Callback provider tetap langsung ke domain publik LFAMILIA. Jangan mengubah URL Midtrans Snap hanya karena VPS relay sudah tersedia. BI-SNAP dan iPaymu baru diaktifkan setelah credential dan endpoint resmi masing-masing siap.
