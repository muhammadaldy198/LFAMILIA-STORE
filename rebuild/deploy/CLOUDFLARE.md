# Cloudflare production baseline — LFAMILIA.COM

> Status operasional terverifikasi lewat Cloudflare API pada 2026-10-29. Konfigurasi kredensial selalu di Cloudflare/VPS, bukan di git.

## Zone, DNS, dan Tunnel

- Domain utama: `https://lfamilia.com`, `www.lfamilia.com` (redirect canonical ke apex).
- CloudPanel: `panel.lfamilia.com` via Cloudflare Access.
- Ketiga record DNS merupakan proxied CNAME ke tunnel `lfamilia-origin-20261028` (ID `f23b8901-3ef5-4ac0-9613-284ff7f7a6d5`).
- Ingress `lfamilia.com` dan `www.lfamilia.com` → `https://127.0.0.1:443` (Origin SNI `lfamilia.com`); `panel.lfamilia.com` → `https://127.0.0.1:8443`.
- Origin HTTPS memverifikasi CA bundle dan hostname dengan `noTLSVerify: false`; ingress terakhir `http_status:404`.
- DNSSEC aktif; SSL mode **Full (strict)**; Always Use HTTPS **on**; minimum TLS **1.2**; TLS 1.3 **on**.
- Satu-satunya domain customer yang didukung adalah `lfamilia.com` beserta alias `www.lfamilia.com`. Tidak ada fallback atau routing kompatibilitas domain sebelumnya.

## Proteksi aplikasi

- Cloudflare Access: `lfamilia.com/admin` (owner/admin) dan `panel.lfamilia.com` (owner); CloudPanel session 4 jam, admin 24 jam.
- WAF memblokir scanner umum, membatasi webhook Digiflazz ke IP yang ditentukan di aturan, dan mengecualikan callback yang dipilih dari Managed Free WAF (validasi signature/provider tetap WAJIB di Laravel).
- Cache bypass pada `/api/`, `/admin`, `/account`, `/checkout`, `/payment`, `/orders`, `/auth/` dan rute sensitif lain.
- Nginx website dan clp-nginx panel adalah dua instance berbeda; port 443/8443 tidak diekspos secara publik. Pada VPS hanya SSH publik sesuai laporan pemelihara VPS.
- PHP dan MySQL/Redis dijalankan di origin, bukan Workers atau Pages.

## Email dan integrasi

- Resend: domain sender `lfamilia.com` **verified**; DKIM/SPF/MX dipasang melalui DNS, `_dmarc` sementara `p=none`.
- Cloudflare Email Routing: `support@lfamilia.com` dan `admin@lfamilia.com` meneruskan ke penerima pemilik yang sudah diverifikasi.
- Sender `integration_credentials` Resend `from_email` dan pengaturan `store.email` masih perlu disesuaikan via panel admin/VPS; DNS saja tidak mengubah isinya.
- Notifikasi provider domain baru: `/api/fulfillment/digiflazz/webhook`, `/api/payments/midtrans/notification`, `/api/payments/doku/notification`; Google OAuth `/auth/google/callback`. **Wajib** ubah URL callback di dashboard masing-masing provider ke `https://lfamilia.com`, tidak cukup di source code. Sebelum pembaruan dan uji callback berhasil, pembayaran/fulfillment tetap dalam mode uji.

## Hal yang belum dinyatakan lulus

- Pengujian transaksi Midtrans/DOKU sandbox dan Digiflazz TEST end-to-end; callback provider nyata tanpa uang asli.
- Backup off-site R2; bucket `lfamilia-production-backups` tersedia namun teramati kosong pada saat migrasi.
- Verifikasi akhir reset password/email dengan sender Resend baru dan OAuth Google.

## Ownership

- Cloudflare API/DNS/Access/WAF/Tunnel token: pengelola Cloudflare.
- Nginx, firewall, `cloudflared` service, origin TLS, DB/Redis, R2 rclone/backup: BETOL (VPS).
- Laravel code, env examples, CI, PR/Codex Review: GitHub.
- **Tidak** simpan token, private key, .env produksi, API key, atau IP origin dalam git.
