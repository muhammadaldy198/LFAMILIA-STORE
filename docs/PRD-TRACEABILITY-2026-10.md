# PRD v1.1 Traceability Matrix & Decision Register

**Repo:** muhammadaldy198/LFAMILIA-STORE
**Tanggal:** 2026-10-17
**Baseline main:** `780e9dc` (post PR #279 merger)
**Dokumen sumber:** `docs/PRD-LFAMILIA-STORE-v1.md` (MASTER v1.1, changelog 2026-10-15)
**Bagian dari:** Roadmap Audit FASE 2 (PR-C) — dokumentasi murni, tidak mengubah runtime.
**Prioritas dokumen:** PRD v1.1 = master. Dokumen ini adalah peta bukti (traceability) yang menautkan setiap keputusan PRD ke implementasi yang ada, bukan sumber keputusan baru.

---

## Cara baca dokumen ini

- **Status** merefleksikan kondisi kode pada baseline `780e9dc` (2026-10-17):
  - `IMPLEMENTED` — keputusan PRD terpenuhi, ada bukti kode/route/migration.
  - `PARTIAL` — sebagian terpenuhi; ada celah atau keputusan spesifik yang belum diimplementasikan.
  - `DEFERRED` — keputusan PRD ditunda sengaja (lihat §47 / arahan pemilik), bukan bug.
  - `STALE_DOC` — kode benar tetapi dokumen domain (`rebuild/*.md`) tidak sinkron dengan perilaku aktual (lihat Finding F-03, F-05, F-08).
  - `UNVERIFIED` — tidak ada bukti yang bisa diverifikasi dari baseline ini; ditandai untuk verifikasi lanjut.
  - `GAP` — ditemukan selisih antara keputusan PRD dan perilaku kode.
- Kolom **Bukti** merujuk ke file/route/konstanta, bukan klaim tanpa dasar.
- Temuan audit F-01…F-09 dari Roadmap Audit disebutkan sebagai pelengkap; dokumen ini **tidak** mengubah runtime.

---

## Matriks traceability §1–47

### §1 Tujuan Produk
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Store live di lfamiliastore.my.id; fase live per addendum §47 (2026-10-15). |

*Bukti:* VPS produksi `server.lfamiliastore.my.id`, app `rebuild/`. Tidak ada artefak kode yang perlu dicek — ini adalah status operasional.

### §2 Stack Teknologi
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Backend Laravel 12 / PHP 8.4; Frontend Vue 3 + Inertia v2 + Tailwind v4; MySQL 8; Redis; Nginx; Fortify + Sanctum; Spatie Media Library; CloudPanel; Cloudflare. |

*Bukti:* `composer.json` (`laravel/framework` ^12, `spatie/laravel-medialibrary` ^11); `package.json` (vue@3, `@inertiajs/vue`); `config/mail.php`; `rebuild/` layout; deploy runbook CloudPanel/VPS.

### §3 Arsitektur Sistem
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Arsitektur layer services + controllers; struktur `app/Services`, `app/Models`, `routes/web.php`, `resources/js/Pages`, `database/migrations`. |

*Bukti:* Peta layanan (PaymentService, FulfillmentService, CheckoutService, WalletTopupService, MembershipService, dst.) + migrasi terstruktur.

### §4 Kategori Produk
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Kategori: Game, Pulsa, Paket Data, PLN, Voucher, Entertainment, PPOB. E-wallet tidak menjadi kategori awal. Kategori extensible dari panel. |

*Bukti:* `app/Models/Category.php`; route admin kategori (`AdminCatalogController`); `AdminController` untuk kategori.

### §5 Model Katalog & Multi-Provider
| Status | Keterangan |
|---|---|
| IMPLEMENTED (utama) / GAP (dead-code failover) | Struktur Kategori → Produk → Nominal → Provider Mapping. Satu nominal boleh punya banyak provider. Khusus Digiflazz: 1 SKU per nominal (model `_A`), tanpa `_B`. Fulfillment tidak terkunci hanya Digiflazz. **Auto-failover dinonaktifkan penuh** — F-04 menandai jalur `if (false && $failover)`. |

*Bukti:* `app/Models/ProductPackage.php`, `app/Models/ProviderMapping.php`; `app/Services/DigiflazzCatalogImport.php`; `app/Services/FulfillmentService.php:529-533` (`if (false && $failover)` + komentar "Per PRD §47"); `app/Services/FulfillmentService.php:474` ("Single SKU per nominal, no auto-switch"). Temuan **F-04**: dead-code failover masih ada (lihat Temuan).

### §6 Digiflazz
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Panel import/sync produk & SKU Digiflazz. `buyer_sku_code` berasal dari data Digiflazz, tidak dimanipulasi customer. Seller selection/ranking manual (Buyer Area → Produk). **Digiflazz Tools terpisah + auto-switch tidak ada** — sesuai §47. |

*Bukti:* `app/Services/DigiflazzCatalogImport.php`; `app/Services/AdminDigiflazzMonitorService.php`; `app/Http/Controllers/AdminDigiflazzController.php`; `app/Services/DigiflazzFixedPricePolicy.php`. Tidak ada route/service bersimbol `auto-switch` di `app/`.

### §7 Produk Manual
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Tab Manual terpisah; tidak terbatas Roblox; gamepass/gift/custom/entertainment/voucher; mode `AUTO_PROVIDER` & `MANUAL`; ID internal DB LFAMILIA; dapat pakai Manual QRIS + notifikasi internal/webhook. |

*Bukti:* `app/Services/AdminManualOrderService.php`; `app/Models/ProviderMapping.php` (mode fulfillment konstanta); menu panel "Manual".

### §8 Customer Input & Nickname
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Field per produk configurable (User ID, Zone, Server, HP, meter PLN, email, username). Hanya field yang dibutuhkan provider dikirim. Provider nickname utama KokinPay. Super Admin kelola game code/mapping/test nickname/enable-disable checker/status koneksi. **Checker gagal → customer tetap boleh lanjut dengan peringatan.** Nama provider tidak ditampilkan ke customer. |

*Bukti:* `app/Models/ProductInputField.php`; `app/Services/NicknameService.php:63,130` (warning "Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia"); `resources/js` field per produk.

### §9 Akun Pelanggan
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Guest Checkout + Registered Customer. Login email/password + Google OAuth. Setelah Google login, nomor HP wajib diisi tetapi OTP tidak wajib (v1). Fitur: profil, wallet, membership tier, riwayat, detail pesanan, tiket komplain, ubah/lupa password, hapus akun. |

*Bukti:* `app/Http/Controllers/GoogleAuthController.php:109` (redirect: phone ? account : account.phone.edit); `routes/web.php:125-128` (google redirect/callback); `app/Actions/Fortify/` (register/login); `app/Http/Controllers/Customer*/` (account, wallet, support).

### §10 Membership Pelanggan
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Terpisah dari RBAC Admin. Tier BASIC→SILVER→GOLD→DIAMOND→PLATINUM→MAFIA. BASIC default. Threshold/benefit configurable dari panel; tidak di-hardcode. Perubahan tier manual wajib audit log. |

*Bukti:* `app/Models/MembershipTier.php`; `app/Services/MembershipService.php:24` (`$tiers->firstWhere('code','BASIC') ?? $tiers->first()`); `app/Services/AdminAuditService.php` (audit perubahan membership).

### §11 Guest Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Guest boleh membeli. Guest **tidak punya wallet/saldo**. Guest dapat order ID & cek status order. Guest dapat review setelah berhasil via token/signed link aman. Android tidak mendukung guest checkout. |

*Bukti:* `app/Services/GuestOrderAccess.php` (token HMAC, `issue`/`issueStable`/`matches`/`store`); `app/Http/Controllers/GuestOrderController.php`; `app/Http/Controllers/ProductReviewController.php` (order check by `order_number`); `routes/web.php:111` (`/reviews` dalam grup `throttle:guest-order`).

### §12 Lifecycle Akun
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Cleanup akun otomatis bila saldo Rp0, belum transaksi, tidak aktif ≥30 hari; backend verifikasi tidak ada saldo/transaksi/tiket/kewajiban; customer boleh hapus sendiri. |

*Bukti:* `app/Services/CustomerCleanupService.php`; `app/Services/CustomerAccountDeletion.php`; migrasi lifetime terkait.

### §13 Wallet / Saldo
| Status | Keterangan |
|---|---|
| IMPLEMENTED (sebagian besar) | Wallet wajib registered. Sumber saldo: top-up, penambahan Super Admin, penyelesaian pesanan bermasalah (tambah saldo). Min top-up default Rp10.000 configurable. Saldo tidak ditarik tunai. **Ledger** dengan amount, balance before/after, source, reference, actor, timestamp, idempotency key. Saldo negatif dilarang (locking/transaction). |

*Bukti:* `database/migrations/2026_09_29_000002_create_commerce_tables.php` (tabel `wallets`, `wallet_ledger`, `wallet_topups`, index `wallet_source_reference_unique`); `app/Services/WalletTopupService.php:54-58` (lockForUpdate + idempotency); `app/Models/Wallet.php`. **Note:** SARAN per §47 tidak ada refund otomatis; penyelesaian pesanan = tambah saldo (dipisahkan dari webhook — lihat F-05).

### §14 Aplikasi Android (Fase Berikutnya)
| Status | Keterangan |
|---|---|
| DEFERRED | Tetap rencana fase berikutnya (addendum §47 2026-10-15). |

*Bukti:* Tidak ada kode Android pada baseline. Keputusan eksplisit.

### §15 Flow Checkout Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Urutan: input ID → cek nickname → pilih produk/nominal → metode bayar → voucher → ringkasan harga → buat order → bayar → verifikasi backend → fulfillment → selesai. Produk dinominal diurutkan berdasar nilai nominal, bukan harga jual. |

*Bukti:* `app/Services/CheckoutService.php`; `app/Services/CheckoutPricing.php`; `app/Services/CatalogNominalOrder.php`; `app/Services/VoucherStockService.php`; route `/checkout`; `resources/js/Pages/Catalog/Show.vue`.

### §16 Status Order
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Status: Menunggu Pembayaran, Dibayar, Diproses, Berhasil, Gagal, Dibatalkan, Kedaluwarsa, BLOCKED. **"Refund" bukan status order** (addendum §47); handler partial-refund hanya mencatat review, tidak mengubah status → no-refund. |

*Bukti:* `app/Services/PaymentStateService.php:33` (accepts PENDING/PAID/FAILED/CANCELLED/EXPIRED/REFUNDED/PARTIAL_REFUND_REVIEW); `app/Services/PaymentStateService.php:37-51` (partial-refund hanya event/log, **tidak** ubah saldo/status); `app/Services/AdminOrderPresentation.php` (label status, termasuk BLOCKED). **Mesin status memakai nama `REFUNDED`/`PARTIAL_REFUND_REVIEW` sebagai status masuk gateway** — lihat F-05 (bedakan penerimaan status dari operasi refund).

### §17 Pricing & Snapshot
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Formula modal + margin + fee = harga final. Margin per produk (%) → configurable. Fee gateway dibebankan ke customer. Kalkulasi final dilakukan backend. Snapshot disimpan saat order (modal, margin, discount, fee, total, provider, package, customer input). Harga order tidak berubah setelah dibuat. `max_price` berhentikan fulfillment otomatis. **ML = modal × 1,06 tanpa pembulatan** (§47). Harga jual game lain **belum diputuskan** — jangan diciptakan. |

*Bukti:* `database/migrations/2026_09_29_000002_create_commerce_tables.php` (kolom `snapshot` JSON + `customer_input`); `app/Services/CheckoutPricing.php`; `app/Services/DigiflazzFixedPricePolicy.php` (max_price cost comparator); `app/Services/AdminPricingPreviewService.php` (preview max price). **Note:** harga lengkap non-ML ditunda ke pembahasan produk — bukan GAP, keputusan sadar.

### §18 Voucher
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Voucher diskon. Periode aktif, min transaksi, limit per customer, total kuota, scope produk/kategori. Redemption atomic (anti double-use). |

*Bukti:* `app/Services/VoucherStockService.php:63-83` (lockForUpdate + atomic, "Caller must wrap in transaction"); route voucher; migrasi promo.

### §19 Payment Architecture
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Gateways awal: Midtrans Snap + DOKU Direct API. **DOKU dinonaktifkan sementara** (addendum §47). Customer pilih channel, bukan gateway. Super Admin atur routing channel → gateway. Panel enable/disable gateway, channel, fee per channel, maintenance mode. Webhook backend = otoritas paid; redirect frontend bukan bukti. |

*Bukti:* `app/Services/PaymentService.php`; `app/Services/PaymentRoutingService.php:282` (`'DOKU' => $this->credentialHasKeys(...)`); `app/Services/IntegrationRuntimeConfig.php:216` (`'doku' => str_starts_with(...)` — disabled check); `app/Services/Payment/*` (MidtransGateway, DokuDirectGateway, DokuSignature); migrasi `payment_route_fees_and_method_labels`.

### §20 Manual QRIS
| Status | Keterangan |
|---|---|
| DEFERRED | Dideskripsikan dalam PRD tetapi **pekerjaan penambahannya ditunda** sesuai arahan pemilik pada rangkaian tugas. Jangan dikerjakan tanpa izin baru. |

*Bukti:* Tidak ada jalur "manual QRIS generate" aktif yang memenuhi alur §20 pada baseline. Keputusan delegasi pemilik (Roadmap Audit / §47 context). **Note:** jangan dikonfirmasi sebagai bugs — ini deferral eksplisit.

### §21 Notifikasi
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Notifikasi Panel, Telegram webhook, Discord webhook. Event: order baru, pembayaran berhasil, manual QRIS, provider gagal, pending lama, order berhasil, refund, tiket baru, integration/system error. Telegram/Discord bisa diaktif-nonaktifkan dari konfigurasi Super Admin. |

*Bukti:* `app/Services/ExternalNotificationService.php` (telegram + discord send); `app/Services/DiscordNotificationRouting.php`; `app/Services/AdminNotificationService.php`; `app/Services/TransactionEmailService.php`; route admin webhook config.

### §22 Fulfillment & Reconciliation
| Status | Keterangan |
|---|---|
| IMPLEMENTED (dengan F-04/F-03) | Produk otomatis dikirim via queue setelah payment terverifikasi. Pending dicek ulang oleh queue/job. **Timeout/unknown tidak memicu provider kedua** sampai selesai reconciliation. Duplicate request & fulfillment dicegah. Reference unik + audit trail. |

*Bukti:* `app/Services/FulfillmentService.php` (ref_id unik, `hash_equals` di line 1158, reconciliation), `app/Services/Fulfillment/DigiflazzClient.php`, queue worker (lfamilia-queue). **GAP:** Temuan **F-04** dead-code `if (false && $failover)`; **F-03** dokumentasi domain `FULFILLMENT.md` tidak sinkron.

### §23 Review Pelanggan
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Order Berhasil membuat eligible review. Sistem tidak membuat isi otomatis. Guest via signed token. Review langsung tampil (v1) tanpa moderasi awal. Admin dapat hapus. |

*Bukti:* `app/Http/Controllers/ProductReviewController.php:95-109` (published immediately; admin controls via AdminContentController review update/delete); `routes/web.php:111,338,339`; `app/Services/GuestOrderAccess.php` (signed token). **Note:** guest review eligibility memakai `order_number` lookup + dedup check pada `ProductReview` — lihat `assets/session` untuk dogfood.

### §24 Role & Akses Administrasi
| Status | Keterangan |
|---|---|
| IMPLEMENTED | SUPER_ADMIN & ADMIN. ADMIN tidak boleh melihat secret/API credential sensitif. Staff tidak dipakai pada v1. Membership BASIC–MAFIA bukan role admin. |

*Bukti:* `app/Models/AdminUser.php`; `app/Services/AdminPermissionService.php`; route middleware `admin.super` / `admin`; migrasi RBAC/auth.

### §25 Menu Panel
| Status | Keterangan |
|---|---|
| IMPLEMENTED | 18 menu: Dashboard, Pesanan, Produk, Manual, Banner & Konten, Digiflazz, Provider, Pembayaran, Pelanggan, Promo, Layanan Pelanggan, Laporan, Admin & Akses, Pengaturan, Integrasi, System Health, Audit Log, **Validasi Akun** (addendum §47). |

*Bukti:* Route admin (`routes/web.php` grup `admin`): `/admin/health`, `/admin/digiflazz`, `/admin/nickname-tools`, `/admin/provider`, `/admin/content/reviews`, dst. Menu "Validasi Akun" = AccountValidationConfig + `AdminDigiflazzController`/`AdminNicknameToolsService`.

### §26 Audit Log
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Catat actor, role, action, target, before, after, IP, user agent, timestamp, correlation ID. Sensitif: harga, saldo, refund, provider, gateway, integration credential, reveal secret, role/admin, manual payment confirmation, perubahan membership. Log tersensor secret. |

*Bukti:* `app/Services/AdminAuditService.php:12-13` (list secret keys), `:55-69` (redact); `app/Http/Controllers/AdminAuditController.php`; middleware `AssignCorrelationId.php`; `Middleware` `admin.password.confirm` untuk sensitive actions.

### §27 Frontend Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED (desktop) / PARTIAL (mobile belum tervalidasi visual) | Re-dibuat meniru Ourastore; branding LFAMILIA; tema dark modern gaming; buang kotak-kotak; desktop dulu, mobile setelah stabil. |

*Bukti:* `resources/js/Pages/*`; `CustomerShell.vue`; App.css/tailwind theme dark. **Note:** DoD §41 belum centang mobile responsive — lihat Temuan/Acceptance.

### §28 Media Management
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Spatie Media Library. Super Admin ganti logo, favicon, banner desktop/mobile, gambar produk/nominal, popup, aset konten. Storage local VPS dan/atau object storage. |

*Bukti:* `composer.json` (`spatie/laravel-medialibrary` ^11); `app/Models/StoreAsset.php`, `HomeBanner.php`, `SitePopup.php`; route admin banner/popup/content.

### §29 Email
| Status | Keterangan |
|---|---|
| IMPLEMENTED (queue) / UNVERIFIED (provider produksi) | Provider: **Resend**. Event verification, reset password, payment success, transaction success, refund, support ticket, security notification. Email dikirim via queue. `MAIL_MAILER=log` di `.env.example` — provider produksi belum diverifikasi pada baseline ini. |

*Bukti:* `app/Services/TransactionEmailService.php`; `config/mail.php`; `.env.example:36` (`MAIL_MAILER=log`); queue job email. **Note:** provider Resend perlu diverifikasi dari env produksi — bukan rekaan.

### §30 Infrastruktur VPS & CloudPanel
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Satu VPS: Nginx, PHP-FPM, Laravel, MySQL, Redis, Queue Worker, Scheduler, CloudPanel. Cloudflare di depan (DNS, proxy, WAF, rate-limit, Turnstile, edge). |

*Bukti:* service aktif di produksi (nginx, php8.4-fpm, mysql, redis-server, lfamilia-queue, lfamilia-scheduler); `.github/workflows/validate-rebuild.yml` (M12 deployment assets check); `deploy/*.sh`.

### §31 GitHub & Deployment
| Status | Keterangan |
|---|---|
| IMPLEMENTED | GitHub = source & CI; credential tidak di repo; deploy dari VPS dengan repo Git; alur dev→commit/PR→CI hijau→main→produksi pull→composer/build/migrate/cache→queue restart→health check. Produksi prioritas; staging bisa ditambahkan. |

*Bukti:* `.github/workflows/validate-rebuild.yml`; `rebuild/deploy/{preflight,deploy,healthcheck,backup,restore-verify}.sh`; `/etc/lfamilia/ops.env` (REPO_REF=main, DEPLOY_REQUIRE_RECENT_BACKUP=true). **Note:** docs-only change tidak memerlukan deploy produksi (AGENTS.md §6).

### §32 Security Baseline
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Session Admin/Super Admin 24 jam. 2FA ditunda (v1) tapi arsitektur siap. Turnstile selektif (register, forgot password, suspicious login, form publik). Rate limit minimal: login, register, forgot password, nickname, order lookup, payment create, wallet top-up, voucher validation, API publik sensitif. |

*Bukti:* `config/session.php:7` (lifetime 1440 min = 24h); `app/Services/TurnstileService.php`; middleware `PublicAbuseProtection.php`; `routes/web.php` throttle groups (admin/account/support/checkout/wallet/guest/google/payment). **Note:** 2FA tidak aktif — keputusan v1.

### §33 Secret & Integration Credential
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Credential tidak hardcoded di repo; dikelola via Super Admin → Integrasi; nilai sensitif disimpan terenkripsi di DB; controlled reveal hanya Super Admin + audit; log menyensor password/key/secret/token/private-key/signature. |

*Bukti:* `app/Models/IntegrationCredential.php:15` (`config_ciphertext` => `encrypted:array`); `app/Services/AdminPermissionService.php` (reveal gate); `app/Services/AdminAuditService.php` (redaction). Tidak ada secret di tracked source (composer audit + guardrails CI).

### §34 System Health & Observability
| Status | Keterangan |
|---|---|
| IMPLEMENTED (check) / partial (population) | Pantau Laravel, MySQL, Redis, Queue, Scheduler, disk/storage, Digiflazz, KokinPay, Midtrans, DOKU, Resend. Status: Healthy, Degraded, Down, Not Configured, Maintenance. Correlation ID untuk setiap request/transaksi penting. |

*Bukti:* `app/Http/Controllers/AdminHealthController.php:23-130` (states HEALTHY/DEGRADED/DOWN/UNKNOWN); /health/ready route; middleware `AssignCorrelationId.php`; `app/Http/Controllers/CheckoutController.php` (corr ID). **Note:** coverage penuh semua monitor perlu verifikasi lanjut — label `partial` agar tidak mengklaim lebih.

### §35 Backup & Recovery
| Status | Keterangan |
|---|---|
| IMPLEMENTED (VPS) / GAP (external storage) | Backup MySQL otomatis harian; simpan di VPS + external/object storage; retention awal 7 hari; data sensitif dienkripsi; restore test berkala ke non-production; Export Configuration JSON (tanpa credential). |

*Bukti:* `rebuild/deploy/backup.sh` (encrypted `.tar.gz.enc`); `rebuild/deploy/restore-verify.sh` (checksum + restore temp DB); `/etc/lfamilia/ops.env` (`DEPLOY_REQUIRE_RECENT_BACKUP=true`); backup produksi terverifikasi `lfamilia-20261017T085010Z.tar.gz.enc`. **GAP:** Temuan **F-07** — external backup/RETENTION belum terverifikasi; `BACKUP_REQUIRE_REMOTE` pernah `false` di deploy README. Lihat Temuan.

### §36 Order & Wallet Security
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Backend tolak harga dari frontend, SKU invalid, produk/nominal nonaktif, provider code manipulated, fee palsu, total Rp0 ilegal, voucher tidak sah/expired/over-quota, negative amount. Backend hitung ulang. Payment create + tombol bayar idempotent. Wallet credit/debit, refund, fulfillment, callback idempotent. |

*Bukti:* `app/Services/PaymentService.php:29-121` (startOrder idempotency key + fingerprint + 409); `app/Services/CheckoutInputValidator.php`; `app/Services/WalletTopupService.php` (lockForUpdate + idempotency). **Note:** idempotency wallet debit/refund — lihat F-05 no-refund.

### §37 Callback / Webhook Security
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Verifikasi signature & source per provider; idempotency protection; callback disimpan sebagai audit/event; replay berbahaya ditolak; final state tidak di-downgrade. |

*Bukti:* `app/Http/Controllers/PaymentWebhookController.php:42` (`hash_equals($expected, $payload['signature_key'])`), `:119-121` (Doku timestamp & client_id), `:74-75` (merchant_reference + amount match); `app/Services/MidtransStatusVerification.php`; `app/Services/Payment/DokuSignature.php`. **Note:** final-state guard ada (`IGNORED_FINAL`), guard anti-downgrade terverifikasi di PaymentStateService.

### §38 Testing Wajib
| Status | Keterangan |
|---|---|
| IMPLEMENTED (praktik) / PARTIAL (gap coverage) | Backend unit/feature, API, auth/RBAC, payment state, wallet & ledger, voucher concurrency, provider/reconciliation, queue/job, frontend smoke (customer & admin), abuse tests. |

*Bukti:* `tests/Unit/*`, `tests/Feature/*`; `tests/Browser/*.mjs` (Playwright) + `tests/Browser/smoke.sh`; CI `validate-rebuild.yml` (foundation + frontend-static). **Note:** Temuan **F-08** — `TESTING.md` menyatakan bukan Playwright padahal checkout-visual.mjs memakai Playwright (documentation drift). Abuse-test coverage penuh perlu review.

### §39 Main Branch Policy
| Status | Keterangan |
|---|---|
| IMPLEMENTED | main selalu deployable. Merge ditolak bila build, automated tests, migration validation, lint/type check, atau critical security checks gagal. |

*Bukti:* `.github/workflows/validate-rebuild.yml` (required checks — lint, phpunit, build, audit, hooks); `AGENTS.md` §4; merge dengan failing checks diblokir.

### §40 Milestone Pembangunan
| Status | Keterangan |
|---|---|
| IMPLEMENTED M1–M12 | M1 Foundation … M12 Deployment. **M13 Acceptance = PARTIAL** (uji E2E sandbox ditunda ke terakhir — addendum §47). |

*Bukti:* Baseline main ter-validasi CI (foundation + frontend-static). Migration/asset/schema ada (M12). E2E sandbox belum dijalankan penuh (M13).

### §41 Definition of Done
| Status | Keterangan |
|---|---|
| PARTIAL | Checklist DoD: item yang sudah dicentang — [x] customer desktop, [x] register/login/google/forgot, [x] guest checkout, [x] wallet & membership, [x] catalog/nickname/voucher/pricing/snapshot, [x] multi-provider abstraction & reconciliation, [x] Super Admin/Admin permissions, [x] Telegram/Discord/email/health/audit, [x] queue & scheduler stabil, [x] tidak ada critical security issue, [x] abuse test & full checkout smoke, [x] build production & test wajib hijau. **Belum dicentang:** customer mobile responsive; Midtrans/DOKU/Manual QRIS/Digiflazz manual fulfillment (_DOKU disabled — sisanya jalan_); backup otomatis & restore berkala (restore-verify ada, bukti eksekusi berkala belum diverifikasi — F-07). |

*Bukti:* `docs/PRD-LFAMILIA-STORE-v1.md` §41; temuan **F-01, F-02** (CI) & **F-07** (backup) memengaruhi item yang belum dicentang.

### §42 Non-Negotiable Requirements
| Status | Keterangan |
|---|---|
| IMPLEMENTED | 11 non-negotiable: tidak percaya harga frontend; tidak simpan secret di GitHub; guest tidak punya wallet; nama provider tidak tampil; tidak double fulfillment; tidak failover sebelum status pasti; redirect bukan bukti paid; harga order tidak berubah; Android tidak buat DB customer terpisah; checkout tidak depend hardcoded satu provider; membership bukan akses admin. |

*Bukti:* `app/Services/CheckoutInputValidator.php` (harga/SKU); `config` (credential tidak tracked); `app/Services/GuestOrderAccess.php` (guest tanpa wallet); `app/Services/FulfillmentService.php` (no auto-failover, no double); `PaymentWebhookController` (webhook = otoritas paid); `Product` (`snapshot` harga); `app/Services/MembershipService.php` (terpisah); `ProviderMapping` (multi-provider).

### §43 Keputusan yang Dapat Dikonfigurasi di Panel
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Min top-up; margin per produk; gateway & channel aktif; routing channel Midtrans/DOKU; fee per channel; maintenance mode; kategori/produk/nominal aktif; nickname mapping & game code; voucher rule; webhook Telegram/Discord; membership threshold/benefit; logo/favicon/banner/popup. |

*Bukti:* `config` + `app/Services/*` (PaymentPageSettingsService, StorefrontContentService, VoucherStockService, MembershipService); tabel `settings`/`integration_credentials`, `payment_channels`, `payment_routes`, `membership_tiers`.

### §44 Prinsip Implementasi
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Sumber kebenaran = PRD + log keputusan pasca-PRD (§47). Implementasi boleh memperbaiki struktur/keamanan/performansi tetapi tidak mengubah alur bisnis tanpa keputusan eksplisit. Frontend mengejar kemiripan Ourastore; backend mandiri, aman, provider-agnostic. |

*Bukti:* `AGENTS.md`, `CLAUDE.md` (hierarki dokumen); dokumen ini (traceability) menautkan PRD → bukti. Perilaku aktif server-side pada `rebuild/`.

### §45 Homepage Support, Footer & Navigasi Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED | CTA/bantuan "BUTUH BANTUAN? / Tim LFAMILIA siap membantu"; banner/promotional strip configurable; identitas footer logo + tagline "Top up favoritmu, sat set tanpa ribet."; tautan sosial WhatsApp/Instagram/Email/Discord (edit dari panel, ikon tanpa config boleh disembunyikan); bagian LAYANAN (Top Up Game, Promo, Cek Transaksi, Hubungi Kami) menuju route/features berfungsi; bagian KALKULATOR (Win Rate, Zodiac, Magic Wheel, Semua Alat); bagian INFORMASI (FAQ, Syarat & Ketentuan, Kebijakan Pengembalian Dana, Kebijakan Privasi); copyright dinamis; floating support/chat button. |

*Bukti:* `app/Services/StorefrontContentService.php:24-27` (supportWhatsapp/instagramUrl/discordUrl); `resources/js/Components/CustomerShell.vue:48-71` (kalkulator/social/nav); `routes/web.php:65-78,77-78` (`/faq`, `/tools`, `/tools/{tool}`); `app/Http/Controllers/PublicContentController.php:33,45,50,55,63` (faq/legal); `app/Models/FaqEntry.php`, `HomeBanner.php`, `SitePopup.php`. **Note:** copyright tahun "sebaiknya dirender dinamis" — verifikasi apakah tahun di-render dinamis di `CustomerShell.vue`/footer.

### §46 Acceptance Criteria — Footer & Support Customer
| Status | Keterangan |
|---|---|
| PARTIAL / UNVERIFIED | CTA & tombol Hubungi Kami; banner bottom tanpa crop/space; logo/tagline/4 kanal sosial config; semua link Layanan/Kalkulator/Informasi benar; floating support tidak menutup konten; tidak ada link dummy/404; penggantian logo/banner/link dari panel ter-refleksi; visual konsisten tema dark. |

*Bukti:* Komponen + route di atas; **belum ada bukti UI acceptance penuh (desktop/mobile) yang tervalidasi pada baseline.** Lihat Finding F-09 & DoD §41 (mobile belum dicentang, verifikasi link/visual belum diverifikasi).

### §47 Keputusan Pasca-PRD
| Status | Keterangan |
|---|---|
| IMPLEMENTED (sebagai log) | Log keputusan bersumber pada PRD §47 & dokumen ini. Pool keputusan: source-of-truth = PRD + log; no-refund; `_B` removed; ML = modal × 1,06 (tidak dibulatkan); satu nominal multi-provider; Digiflazz Tools + auto-switch belum ada; DOKU disabled temporarily; Menu Validasi Akun; Website Live / M13 partial; Android tetap rencana. |

*Bukti:* `docs/PRD-LFAMILIA-STORE-v1.md` §47 table; implementasi aktif terhadap tiap keputusan: `FulfillmentService.php:474,529-533` (no auto-switch), `PaymentStateService.php:37-51` (no-refund), `DigiflazzFixedPricePolicy` (cost/max_price), `IntegrationRuntimeConfig.php:216` (DOKU disabled), `AccountValidationConfig` (menu Validasi Akun), `MembershipService` (terpisah dari RBAC).

---

## Decision Register (dibuat pada FASE 2, hanya observasi — tidak mengubah keputusan PRD)

| ID | Tanggal | Keputusan / Observasi | Dampak | Sumber |
|---|---|---|---|---|
| DEC-2026-10-17-01 | 2026-10-17 | PRD v1.1 adalah master; dokumen traceability ini adalah peta bukti, bukan sumber keputusan baru. | Hierarki dokumen mengikuti AGENTS.md §0 & PRD §44. | Roadmap Audit, PR-C. |
| DEC-2026-10-17-02 | 2026-10-17 | Item yang belum diverifikasi (F-07 external backup, F-08 TESTING.md Playwright, F-04 dead-code failover, M13 E2E, mobile acceptance §46) tidak boleh diklaim "selesai" — harus punya bukti operasi/regression hijau. | Mencegah over-claim; baris DoD yang belum dicentang tetap terbuka. | PRD §41, Temuan F-*. |
| DEC-2026-10-17-03 | 2026-10-17 | Status `REFUNDED`/`PARTIAL_REFUND_REVIEW` pada state machine menerima status masuk dari gateway — **tidak** berarti operasi refund otomatis (no-refund §47). Pemisahan konsep ini wajib agar F-05 tidak salah interpretasi. | Menghindari menambah jalur refund keluar; hanya logging/review. | PRD §13, §16, §47; F-05. |
| DEC-2026-10-17-04 | 2026-10-17 | Semua temuan F-01…F-09 tetap terbuka sampai tiap temuan punya bukti implementasi/regression (bukan hanya tangkapan layar). | PR-D/PR-E menangani dokumen drift; F-04/F-06 menunggu regression test; F-07 menunggu bukti eksekusi backup eksternal. | Roadmap Audit. |

Dokumen ini hanya observasi & traceability. Tidak ada perubahan runtime; tidak ada deploy yang dibutuhkan (AGENTS.md §6: dokumentasi murni).
