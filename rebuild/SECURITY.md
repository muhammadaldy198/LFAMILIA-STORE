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

## Server security — current infrastructure, 2026-10-29

Berdasarkan laporan BETOL, origin berada di VPS baru dengan CloudPanel dan nginx, MySQL/Redis lokal, queue/scheduler, sertifikat Cloudflare Origin CA untuk HTTPS (443) dan panel (8443), serta firewall yang tidak mengekspos port HTTP/HTTPS/panel secara publik. Detail firewall, proses, dan layanan harus diverifikasi ulang oleh BETOL dari VPS; jangan menyamakan laporan VPS sebelumnya dengan keadaan server baru.

## Cloudflare — verified API, 2026-10-29

- Domain customer tunggal: `lfamilia.com` (alias `www.lfamilia.com`).
- CloudPanel: `panel.lfamilia.com`, terlindungi Cloudflare Access.
- Website `/admin`: terlindungi Cloudflare Access **dan** otorisasi Laravel.
- Ketiga hostname memakai CNAME proxied ke Tunnel `lfamilia-origin-20261028`.
- TLS **Full (strict)**, TLS minimum **1.2**, DNSSEC **aktif**, origin `noTLSVerify:false` dengan CA pool.
- Route webhook wajib tetap melalui URL `https://lfamilia.com` yang dikonfigurasi di dashboard masing-masing provider; Laravel tetap memverifikasi signature, nominal, order reference, dan state.
- Cloudflare Turnstile memerlukan hostname `lfamilia.com` pada widget Cloudflare **serta** profil integrasi aktif `allowed_hostnames` di aplikasi, jika allowlist digunakan.
- Tidak ada dukungan runtime untuk alamat customer sebelumnya dalam konfigurasi target ini. Kredensial lama dan konfigurasi provider tidak otomatis berubah melalui PR.

## Secret management

- Repo/code: source, migrations, schema, protocol, business logic.
- Server ENV/ops files: bootstrap dan infrastructure configuration.
- Super Admin → Integrasi: provider/application credential yang dikelola aplikasi.

Dilarang hardcode secret, commit `.env`, menaruh credential di fixture production, atau membocorkan secret di log/Markdown.

