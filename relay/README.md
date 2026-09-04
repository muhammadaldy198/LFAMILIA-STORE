# LFAMILIA Provider Relay

Relay ini dijalankan di VPS ber-IP publik statis. Satu proses Node menerima tiga hostname:

- `digiflazz-relay.lfamiliastore.my.id`
- `ipaymu-relay.lfamiliastore.my.id`
- `bisnap-relay.lfamiliastore.my.id`

Midtrans Snap biasa tidak perlu melewati relay dan tetap dapat memakai endpoint Midtrans langsung.

## Keamanan

Relay bukan open proxy. Semua request provider wajib berupa `POST` dan membawa header:

`X-LFAMILIA-Relay-Token: <secret>`

Token hanya disimpan sebagai secret pada VPS dan Cloudflare Worker. Body, authorization header, signature, dan credential provider tidak dicatat ke log relay.

## Environment VPS

Buat `/etc/lfamilia-relay.env` dan jangan commit nilainya:

```env
HOST=127.0.0.1
PORT=8788
RELAY_TOKEN=SECRET_ACAK_MINIMAL_32_KARAKTER

DIGIFLAZZ_RELAY_HOST=digiflazz-relay.lfamiliastore.my.id
DIGIFLAZZ_UPSTREAM_ORIGIN=https://api.digiflazz.com

IPAYMU_RELAY_HOST=ipaymu-relay.lfamiliastore.my.id
IPAYMU_UPSTREAM_ORIGIN=

MIDTRANS_BISNAP_RELAY_HOST=bisnap-relay.lfamiliastore.my.id
MIDTRANS_BISNAP_UPSTREAM_ORIGIN=
```

Biarkan upstream iPaymu dan BI-SNAP kosong sampai endpoint resmi untuk akun tersebut sudah dipastikan.

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

Salin `server.mjs` ke `/opt/lfamilia-relay/server.mjs`, lalu gunakan Caddyfile contoh pada folder ini.

## Cloudflare Worker

Setelah relay DigiFlazz aktif:

- Secret `PROVIDER_RELAY_TOKEN` = nilai yang sama dengan `RELAY_TOKEN` di VPS.
- Variable `PROVIDER_RELAY_HOSTS` = `digiflazz-relay.lfamiliastore.my.id,ipaymu-relay.lfamiliastore.my.id,bisnap-relay.lfamiliastore.my.id`
- `DIGIFLAZZ_API_URL` = `https://digiflazz-relay.lfamiliastore.my.id/v1/transaction`
- `DIGIFLAZZ_PRICE_LIST_URL` = `https://digiflazz-relay.lfamiliastore.my.id/v1/price-list`

Callback DigiFlazz tetap langsung ke:

`https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback`

Jangan mengubah URL Midtrans Snap saat ini hanya karena relay sudah dipasang. BI-SNAP dan iPaymu baru diarahkan ke relay setelah integrasi provider masing-masing siap.
