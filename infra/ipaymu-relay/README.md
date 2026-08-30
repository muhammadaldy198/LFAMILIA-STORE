# Relay IP statis iPaymu LFAMILIA

Relay ini membuat seluruh request pembayaran keluar menuju iPaymu melalui satu IPv4 publik VPS. Credential iPaymu tetap berada di Cloudflare Worker; VPS hanya meneruskan request yang memiliki signature relay LFAMILIA.

## Kebutuhan

- VPS Ubuntu 24.04 dengan IPv4 publik tetap
- Node.js 22 dan Caddy
- DNS `A` untuk `ipaymu-relay.lfamiliastore.my.id` menuju IPv4 VPS
- Port 80/443 terbuka; port 8788 tidak dibuka ke internet

## Instalasi VPS

1. Salin `relay.mjs` dan `server.mjs` ke `/opt/lfamilia-ipaymu-relay/`.
2. Buat user sistem tanpa login bernama `lfamilia-relay`, lalu jadikan user itu pemilik folder relay.
3. Salin `.env.example` ke `/etc/lfamilia-ipaymu-relay.env`, isi secret acak minimal 32 karakter, dan batasi permission file ke `600` milik root.
4. Salin `lfamilia-ipaymu-relay.service` ke `/etc/systemd/system/`, lalu aktifkan servicenya.
5. Salin `Caddyfile` ke `/etc/caddy/Caddyfile`, lalu reload Caddy.
6. Pastikan `https://ipaymu-relay.lfamiliastore.my.id/healthz` merespons JSON `{"ok":true}`.

## Konfigurasi LFAMILIA Worker

Tambahkan secret yang sama sebagai `IPAYMU_RELAY_SECRET`, lalu set:

```text
IPAYMU_API_BASE_URL=https://ipaymu-relay.lfamiliastore.my.id
IPAYMU_ENV=production
```

Masukkan IPv4 publik VPS sebagai **IP Website (IP Outbound Back-End)** di iPaymu. Notify URL tetap langsung ke Worker:

```text
https://lfamiliastore.my.id/api/payments/ipaymu/callback
```

Jangan menyimpan nilai `RELAY_SHARED_SECRET`, VA, atau API Key di repository.

