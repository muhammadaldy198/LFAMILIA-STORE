# Admin panel, RBAC & integrations

Admin panel menggunakan Laravel + Vue/Inertia. Authorization selalu server-side; menu visibility hanya presentasi.

## Role

- **SUPER_ADMIN** — seluruh permission dan operasi super-only.
- **ADMIN** — hanya permission granular yang diberikan.

Role Staff tidak aktif. Customer membership BASIC–MAFIA bukan Admin role.

Permission yang didefinisikan service mencakup dashboard, orders, catalog, content, fulfillment, providers, payments, customers, vouchers, support, reports, settings, dan notifications sesuai kebutuhan route.

## Menu aktual

Menu dari `AdminPermissionService`:

1. Dashboard
2. Pesanan
3. Produk
4. Manual
5. Banner & Konten
6. Digiflazz
7. Validasi Akun
8. Provider
9. Pembayaran
10. Pelanggan
11. Promo
12. Layanan Pelanggan
13. Laporan
14. Admin & Akses
15. Pengaturan
16. Integrasi
17. System Health
18. Audit Log

`Validasi Akun`, `Admin & Akses`, `Integrasi`, `System Health`, dan `Audit Log` bersifat super-only pada menu service. Menu lain tunduk pada permission granular.

## Area dan boundary

- **Dashboard** — operational metrics/search/notifications sesuai permission; finance disaring berdasarkan role.
- **Pesanan** — list/detail, payment/fulfillment history, aksi operasional yang diizinkan.
- **Produk** — kategori, produk, nominal, input field, media, pricing, provider mapping/import.
- **Manual** — manual fulfillment dan recovery action yang tetap memakai safety service.
- **Banner & Konten** — storefront assets/content; bukan tempat secret.
- **Digiflazz** — catalog/operational monitor/sync; credential tetap di Integrasi.
- **Validasi Akun** — nickname/game code/checker tooling.
- **Provider** — registry dan mapping/health operasional; tidak menggandakan secret editor.
- **Pembayaran** — channel, gateway state, maintenance, routing, Manual QRIS, top-up control; credential tidak boleh dimasukkan ke route.
- **Pelanggan** — profile/history/tier/wallet operations sesuai role; adjustment saldo hanya flow yang diotorisasi dan memakai ledger.
- **Promo** — voucher dan storefront priority yang memang diimplementasikan.
- **Layanan Pelanggan** — tiket, conversation, quick reply/operational actions.
- **Laporan** — operational metrics; finance sensitif dibatasi Super Admin.
- **Admin & Akses** — akun Admin, role/permission, self-lockout/last-owner protection.
- **Pengaturan** — store/business setting non-secret.
- **Integrasi** — encrypted provider/application credential dan test connection; Super Admin.
- **System Health** — internal runtime/integration state tanpa membocorkan secret.
- **Audit Log** — security/operational audit; tidak menyediakan edit/delete log normal.

## Integrasi

Profil kode/panel yang ditemukan mencakup Digiflazz, KokinPay/nickname, Midtrans, DOKU, Resend, Google OAuth, Telegram, Discord, dan Turnstile.

Credential disimpan pada `integration_credentials.config_ciphertext` dengan encrypted array cast. Normal Inertia props hanya boleh menerima status/config non-secret. Reveal secret memerlukan kontrol Super Admin yang diterapkan dan diaudit.

**Tes koneksi atau keberadaan profil bukan bukti live transaction provider telah lolos.**

## Data sensitif

Jangan menaruh password, API credential, private token, payment signature, raw saved-game secret, atau ciphertext di audit payload/UI biasa. Export configuration normal harus mengecualikan secret.

## UI Admin dan workspace produk

Design system Admin berada di `resources/css/admin.css` dan dibatasi pada shell Admin. Kontrol mobile 36 px, desktop 40 px; sidebar desktop 240 px, topbar 56 px. `AdminResponsiveTable` memakai slot/handler tabel yang sama, dengan kolom prioritas eksplisit di mobile dan informasi sekunder dalam Detail. Checkbox tetap untuk pilihan jamak/konfirmasi; boolean memakai `AdminSwitch`.

Produk menggunakan workspace penuh dengan URL `/admin/catalog?edit=<id>`. Daftar ditutup selama editor aktif. Lima tab: Informasi, Nominal & Harga, Tampilan Produk, Data Pelanggan, Penanganan. Nominal dirender 25 per halaman dan form detail hanya untuk nominal terpilih. Reorder desktop dan tombol naik/turun tetap tersedia. Backend catalog masih mengirim seluruh catalog sebagaimana sebelumnya; pagination server catalog adalah pekerjaan terpisah.

Pembayaran memisahkan Channel, Gateway, Routing, QRIS Manual, Top Up Saldo, Tampilan Halaman, dan Transaksi. Detail pelanggan memisahkan Profil, Tier, Pesanan, Saldo & Top Up, Tiket, serta Akun Game. Semua request, authorization, konfirmasi saldo, ledger, status integrasi dan credential preservation tetap melalui backend existing.

Media menggunakan endpoint Spatie existing. Gambar terpilih dapat dipratinjau sebelum unggah, error unggahan tampil di tempat, dan hapus meminta konfirmasi. Upload favicon otomatis mengaktifkan asset dan memperbarui link favicon Admin/storefront melalui shared props existing.

Audit source/history dan rencana komponen dicatat di `ADMIN-UI-AUDIT.md`.
