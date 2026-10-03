# Authentication & identity

## Customer auth

Customer web auth menggunakan Fortify pada guard `web`. Implementasi mencakup register, login, logout, email verification, forgot password, password reset, perubahan password, dan account/profile flow.

Google OAuth menggunakan Socialite dengan stateful redirect/callback. Client ID/secret dibaca dari integration credential terenkripsi; Google OAuth hanya operasional bila profil Integrasi terkait aktif dan lengkap. Verified Google email dapat ditautkan ke customer yang sesuai dengan proteksi terhadap subject conflict.

Nomor HP diwajibkan pada flow customer yang membutuhkannya, termasuk penyelesaian profil setelah Google login. **OTP WhatsApp tidak digunakan.**

Sanctum melindungi endpoint API account. Policy token aplikasi Android bukan alasan untuk mencampur session web customer dengan guard Admin.

## Admin auth

Admin memakai guard/session dan tabel `admin_users` terpisah. Role yang diterima implementasi adalah:

- `SUPER_ADMIN`
- `ADMIN`

Tidak ada role Staff aktif. Akun tidak aktif ditolak.

Super Admin awal dibuat secara interaktif dengan command aplikasi; tidak ada bootstrap password di Git.

`ADMIN` memakai permission granular dan authorization selalu diperiksa server-side. Menyembunyikan menu di Vue bukan authorization.

## Customer tier bukan Admin role

Membership customer:

BASIC → SILVER → GOLD → DIAMOND → PLATINUM → MAFIA

Tier ini dipakai untuk customer membership/benefit/pricing dan tidak memberi akses Admin.

## Session/security

Application security middleware menambahkan header security, no-store/private untuk area sensitif, request correlation ID, rate limiting, dan challenge Turnstile bila integrasi aktif pada flow yang ditentukan.

Password/remember token dirotasi sesuai flow reset/change yang diimplementasikan. Jangan mendokumentasikan credential atau session secret di repository.

## Credential boundary

Google/Resend/Turnstile dan provider credential yang dikelola aplikasi disimpan melalui Super Admin → Integrasi dengan encrypted cast. Nilai secret tidak boleh masuk Markdown, fixture production, source code, atau log.
