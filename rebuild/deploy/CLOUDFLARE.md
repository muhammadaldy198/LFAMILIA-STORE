# Cloudflare production baseline

Status di bawah diverifikasi melalui **@Cloudflare** pada 2026-10-04. Jangan mengganti status ini dengan asumsi dari config lokal.

## Zone/DNS

Zone `lfamiliastore.my.id` aktif dan tidak paused.

- apex `lfamiliastore.my.id`: proxied;
- `www.lfamiliastore.my.id`: proxied CNAME ke apex;
- `panel.lfamiliastore.my.id`: proxied.

Origin IP sengaja tidak ditulis di dokumentasi.

Mail records tetap DNS-only sesuai jenis record dan tidak boleh diproxy seperti traffic web.

Cloudflare Pages/Workers/D1 **bukan runtime utama aplikasi**. Jangan membuat ulang relay hostname Digiflazz lama kecuali ada requirement baru yang eksplisit.

## SSL/TLS

Verified zone settings:

- SSL mode: **Full (strict)**;
- Always Use HTTPS: **on**;
- minimum TLS version: **1.2**;
- TLS 1.3: **on**.

VPS/Nginx memakai Cloudflare Origin CA certificate untuk origin TLS yang diperiksa pada Tahap 4.

## Cloudflare Access

Dua Access application terverifikasi:

- **LFAMILIA Admin** → `lfamiliastore.my.id/admin`;
- **LFAMILIA CloudPanel** → `panel.lfamiliastore.my.id`.

Keduanya mempunyai allow policy. CloudPanel session duration yang terbaca saat audit adalah 4 jam; aplikasi Admin terkonfigurasi 24 jam.

Access tidak menggantikan Laravel auth/RBAC. `/admin` tetap memerlukan guard/permission Laravel setelah request lolos edge.

## Origin firewall

Pada VPS, UFW membatasi HTTP/HTTPS dan CloudPanel 8443 ke Cloudflare network ranges. Dengan demikian origin web tidak dimaksudkan menjadi jalur publik langsung.

Saat Cloudflare IP ranges berubah, firewall server harus disinkronkan melalui prosedur security/server, bukan dengan membuka origin ke semua alamat.

## Ownership

- DNS/Access/zone TLS → **@Cloudflare**.
- Nginx/UFW/Origin CA install/service → **@Remote Desktop Commander**.
- Source/config documentation → **@GitHub**.

Jangan simpan API token Cloudflare, Access secret, Origin CA private key, atau origin IP di repository.
