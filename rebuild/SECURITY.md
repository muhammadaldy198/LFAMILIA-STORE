# Security baseline

> Aturan produk ada di [`docs/PRD.md`](../docs/PRD.md). Dokumen ini menjelaskan **cara kode bekerja**, bukan aturan produk. Kalau berbeda, PRD yang menang.

Dokumen ini memisahkan security yang ada di aplikasi dari kondisi server/edge yang diverifikasi pada Tahap 4.

## Application security

Implementasi mencakup:

- secure production-startup guardrails untuk debug/HTTPS/trusted host/session settings;
- encrypted session payload configuration, Secure/HttpOnly/SameSite cookie policy sesuai production config;
- security headers dan no-store/private untuk area sensitif;
- request/correlation ID;
- endpoint-specific rate limiting;
- Turnstile enforcement bila integration aktif pada flow yang dikonfigurasi;
- Admin/customer guard terpisah dan server-side authorization;
- encrypted integration credential;
- secret reveal yang dibatasi/audited;
- audit payload redaction;
- payment/provider callback validation dan idempotency;
- monotonic/terminal-state protection;
- wallet locking/ledger idempotency;
- fulfillment reconciliation dan no-double-fulfillment rules.

## Payment/webhook

### Midtrans

Notification signature diperiksa. Sebelum callback mengubah state, implementasi melakukan server-to-server status verification dan mencocokkan reference/amount terhadap transaksi lokal.

### DOKU

Signed notification memeriksa request identity/timestamp/signature sesuai implementation; direct API response juga memverifikasi response signature/timestamp.

### Digiflazz

Webhook/provider result menggunakan signature/source/reference validation yang diimplementasikan. Provider response harus cocok dengan stored reference, SKU, dan target sebelum state dipakai.

Redirect frontend tidak pernah menjadi bukti paid.

## Server security — diverifikasi 2026-10-04, dikoreksi 2026-10-24

Pada VPS production saat audit Tahap 4 (2026-10-04), SSH tercatat key-only. **Keadaan itu sudah berubah atas keputusan pemilik (2026-10-06):**

- SSH password authentication: **ENABLED** (keputusan pemilik — pemilik menyadari keamanan lebih rendah dan memilih begitu untuk sementara);
- keyboard-interactive auth: mengikuti pengaturan SSH aktif;
- public-key auth: **enabled**;
- root login: **password diizinkan** (`PermitRootLogin yes`);
- Fail2ban: **active + enabled**;
- UFW: **active**;
- public HTTP/HTTPS rules dibatasi ke Cloudflare networks;
- port CloudPanel 8443 dibatasi ke Cloudflare networks;
- MySQL listen pada localhost;
- Redis listen pada localhost;
- unattended security updates: **active + enabled**;
- network hardening yang diperiksa: SYN cookies aktif, redirects/send_redirects nonaktif, rp_filter strict;
- Nginx origin certificate adalah Cloudflare Origin CA.

Tidak ada private key, origin IP, password, token, atau encryption material yang didokumentasikan di repo.

## Cloudflare — diverifikasi melalui @Cloudflare 2026-10-04

- zone `lfamiliastore.my.id`: active;
- apex dan `www`: proxied;
- `panel.lfamiliastore.my.id`: proxied;
- SSL mode: **strict**;
- Always Use HTTPS: **on**;
- minimum TLS: **1.2**;
- TLS 1.3: **on**;
- Access application: **LFAMILIA Admin** pada `lfamiliastore.my.id/admin`;
- Access application: **LFAMILIA CloudPanel** pada `panel.lfamiliastore.my.id`;
- kedua aplikasi mempunyai allow policy yang terkonfigurasi.

Cloudflare Access adalah lapisan tambahan. Authorization Laravel tetap wajib untuk `/admin`.

## Secret management

- Repo/code: source, migrations, schema, protocol, business logic.
- Server ENV/ops files: bootstrap dan infrastructure configuration.
- Super Admin → Integrasi: provider/application credential yang dikelola aplikasi.

Dilarang hardcode secret, commit `.env`, menaruh credential di fixture production, atau membocorkan secret di log/Markdown.


## Migration note — 2026-10-29

Zona historis `lfamiliastore.my.id` pada audit di atas tetap dipertahankan sebagai domain lama.
Zona aktif baru `lfamilia.com` memakai Cloudflare Tunnel tersendiri, DNS proxied untuk apex/www/panel, SSL Full (strict), TLS minimum 1.2, dan Cloudflare Access untuk `/admin` serta `panel.lfamilia.com`.
Port origin web/panel tidak dibuka untuk internet; koneksi `cloudflared` keluar dari VPS menuju Cloudflare.
Token Tunnel/Origin CA private key dan konfigurasi provider tidak boleh disimpan di repository.
Verifikasi checkout sandbox, callback pembayaran, OAuth, dan backup off-site harus lolos sebelum transaksi live dibuka.
