# Arsitektur aktual LFAMILIA STORE

Dokumen ini menjelaskan implementasi dan operasi yang diverifikasi pada Tahap 4. PRD menjelaskan requirement produk; file ini menjelaskan bentuk runtime yang sekarang.

## Request flow

### Customer

`Browser → Cloudflare → Nginx → Laravel/Inertia → MySQL/Redis`

Laravel adalah source of truth business logic. Vue/Inertia adalah customer UI. MySQL menyimpan data bisnis; Redis dipakai untuk cache, queue, dan session.

### Admin

`Browser → Cloudflare Access → /admin → Laravel Admin`

Admin tetap memakai authorization Laravel di server. Cloudflare Access adalah lapisan edge tambahan dan bukan pengganti RBAC aplikasi.

### CloudPanel

`Browser → Cloudflare Access → Cloudflare proxy → panel.lfamiliastore.my.id:8443 → CloudPanel`

Pada audit 2026-10-04, zone Cloudflare aktif, hostname panel proxied, aplikasi Access LFAMILIA CloudPanel terpasang, dan origin memakai Cloudflare Origin CA.

## Transaction flow

`Customer → Checkout → Order → Payment route → Payment transaction → callback/reconciliation → Fulfillment → provider mapping`

Checkout menghitung ulang data server-side dan membuat snapshot order. Customer memilih **channel/metode pembayaran**, bukan gateway internal. Payment routing menentukan gateway yang eligible sebelum external request.

Setelah external request sudah dibuat, recovery bukan “coba gateway lain begitu saja”. State uncertain harus direkonsiliasi; callback/provider result tervalidasi menjadi sumber status authoritative sesuai implementasi masing-masing gateway/provider.

## Payment routing

`PaymentRoutingService` memilih route yang:

- channel-nya aktif;
- gateway dan route aktif;
- gateway tidak maintenance;
- mendukung purpose order atau wallet top-up;
- mempunyai credential yang dianggap ready untuk gateway eksternal yang memerlukannya.

Route diurutkan dengan angka `priority` kecil lebih dahulu, lalu ID. Jadi priority 10 dipilih sebelum 20 bila keduanya eligible.

**Selection sebelum request** berbeda dari **recovery setelah request**. `PaymentService` tidak melakukan universal gateway failover setelah request eksternal yang statusnya belum pasti. Transaksi dapat masuk `UNKNOWN` dan harus diperiksa/reconciled, bukan diduplikasi.

## Order/payment safety

Implementasi yang ditemukan di kode:

- checkout idempotency key + fingerprint;
- payment idempotency key + request fingerprint;
- row locking pada transaksi kritis;
- satu non-rejected payment aktif per order/top-up;
- payment state transition terpusat;
- callback tervalidasi dan deduplicated;
- terminal state tidak boleh didowngrade oleh callback lama;
- wallet debit/credit memakai transaction, lock, ledger, dan idempotency;
- order menyimpan snapshot cost, margin, discount, fee, total, package, provider mapping, dan payment route;
- perubahan katalog/harga setelah order dibuat tidak menulis ulang snapshot order;
- order events/audit record dipakai untuk tracing state penting.

## Fulfillment

Satu `ProductPackage` dapat mempunyai beberapa `provider_mappings`. Untuk Digiflazz, setiap mapping dapat memiliki `buyer_sku_code`/external SKU berbeda.

Candidate dipilih dengan priority kecil lebih dahulu, lalu cost dan ID. Mapping nonaktif, provider nonaktif, SKU yang tidak usable, stok/cut-off bermasalah, adapter yang tidak didukung, atau cost di atas `max_price` dapat dilewati **sebelum request**.

Setelah request:

- `PENDING`, `SENDING`, atau `UNKNOWN` tidak langsung memicu source berikutnya;
- reconciliation memakai reference attempt yang sama;
- source berikutnya hanya boleh dibuat ketika kegagalan sudah definitive/`FAILED_CONFIRMED` atau mapping belum pernah dikirim dan aman dilewati;
- attempt sebelumnya dan mapping yang sudah dipakai tidak boleh diduplikasi;
- customer tetap melihat satu nominal/order, bukan daftar provider internal.

Mekanisme ini bukan klaim “failover universal semua provider”. Adapter otomatis yang ditemukan saat audit adalah Digiflazz dan stok kode internal; manual fulfillment memiliki flow sendiri.

## Auth boundary

Customer auth dan Admin auth terpisah.

- Customer: Fortify web session, register/login/logout, forgot/reset password, Google OAuth via Socialite bila integrasi aktif, profile/account.
- Admin: guard/table terpisah, role `SUPER_ADMIN` atau `ADMIN`.
- `ADMIN` memakai permission granular yang diperiksa server-side.
- `SUPER_ADMIN` memperoleh akses penuh dan operasi super-only.
- Customer membership tier **bukan RBAC**: BASIC → SILVER → GOLD → DIAMOND → PLATINUM → MAFIA.
- OTP WhatsApp tidak digunakan.

## Secret boundary

- **Repo/code:** source code, schema, migrations, protocol mapping, validation, business logic.
- **ENV server:** bootstrap/operational infrastructure configuration seperti APP_KEY, DB/Redis connectivity, deploy paths/service names, backup file locations.
- **Super Admin → Integrasi:** provider/application credentials yang dikelola aplikasi.

`integration_credentials.config_ciphertext` memakai Laravel encrypted array cast. Secret tidak boleh di-hardcode, disimpan di fixture production, masuk log, Markdown, atau commit `.env`.

## Integrasi yang mempunyai dukungan kode/panel

Digiflazz, KokinPay/nickname tooling, Midtrans, DOKU, Resend, Google OAuth, Telegram, Discord, dan Turnstile ditemukan di implementasi/admin integration workspace.

Keberadaan adapter/panel **bukan bukti** credential production lengkap atau external live transaction sudah lolos. Live provider acceptance tetap tahap terpisah.
