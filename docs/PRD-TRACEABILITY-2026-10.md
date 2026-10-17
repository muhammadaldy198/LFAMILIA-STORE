# PRD v1.1 Traceability Matrix & Decision Register

**Repo:** muhammadaldy198/LFAMILIA-STORE
**Tanggal:** 2026-10-17
**Baseline runtime:** `780e9dc` (post PR #279 merger — status kode yang dievaluasi).
**Baseline kebijakan:** `be250100` (post PR #283 merger — kebijakan refund manual via CS yang terevaluasi di §16/§29/§45/§47).
**Dokumen sumber:** `docs/PRD-LFAMILIA-STORE-v1.md` (MASTER v1.1, changelog 2026-10-15)
**Bagian dari:** Roadmap Audit FASE 2 (PR-C) — dokumentasi murni, tidak mengubah runtime.
**Prioritas dokumen:** PRD v1.1 = master untuk **kebijakan produk** (business intent). Untuk **perilaku implementasi aktual**, bukti runtime terverifikasi (kode/migrasi/test) lebih tinggi melampaui teks PRD/decision-log (CLAUDE.md §3). Dokumen ini adalah peta bukti (traceability) yang menautkan setiap keputusan PRD ke implementasi yang ada, bukan sumber keputusan baru.

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
- Temuan audit di bawah (F-01…F-09) dirujuk lintas dokumen sebagai pelengkap; definisinya disertakan di sini agar self-contained. Sumber: Roadmap Audit PRD v1.1 (2026-10-17), Register Temuan Audit.

### Referensi temuan audit (F-01…F-09)

| ID | Level | Temuan | Status solusi |
|---|---|---|---|
| F-01 | P0 | Main foundation gagal pada `Validate local Git hooks`; Pint `no_unused_imports` pada `rebuild/tests/Feature/FulfillmentTest.php`. | SOLVED via PR #279 (CI hijau). |
| F-02 | P0 | PR #279 foundation gagal: 5 error PHPUnit (4 fixture duplicate `users_phone_normalized_unique`, 1 test backup SKU lama). | SOLVED via PR #279 (para fixture/ekspektasi dikoreksi tanpa melonggarkan unik/anti-failover). |
| F-03 | P1 | PRD v1.1 §47: satu SKU / no auto-switch; `rebuild/FULFILLMENT.md` masih merinci failover definitif + max-price backup; `rebuild/ADMIN.md` menjelaskan cadangan otomatis. | OPEN — menunggu PR-E (dokumen domain drift). |
| F-04 | P1 | `rebuild/app/Services/FulfillmentService.php:531` punya `if (false && $failover)` (dead), TETAPI jalur **retry failover masih hidup**: `retrySafeAttempt()` di line 577–578 memanggil `failoverIfSafe()` saat `$attempt->safe_to_failover=true`. Commit #278 mengubah tulisan baru ke `false` tapi **tidak ada migrasi untuk data lama** — baris `safe_to_failover=true` pasca-#278 masih reachable lewat action Admin retry. **Ini active gap, bukan dead code.** | OPEN — butuh migrasi/guard baris lama + regression; jangan klaim failover fully disabled. |
| F-05 | P3 | `rebuild/PAYMENT.md` dan `PaymentStateService` memuat `REFUNDED`/`REFUND_REVIEW` sebagai status. Ini adapter status masuk gateway, bukan jalur refund otomatis. Refund = **manual via CS** (keputusan owner, lihat DE-2026-10-17-05). | OPEN (kosmetik) — tidak perlu pisahkan rekonsiliasi; status masuk tetap, operasi refund tetap manual. |
| F-06 | P1 | Digiflazz mapping nonmanual menerima refresh harga supplier; manual-lock dikecualikan. **Bukan gap test**: `DigiflazzCatalogImport::upsert()` (baris 48–51) tetap menimpa `cost_idr`/`max_price_idr` untuk mapping dengan `is_manual_price=false` pada tiap refresh; `AdminDigiflazzRestorationTest` sudah memverifikasi lock maupun perubahan harga unlocked. | OPEN — butuh **enforcement harga tetap** untuk mapping unlocked (cost_idr = Harga Max konstan, tidak mengikuti harga supplier), plus coverage; test lock tambahan tidak menutup gap ini. |
| F-07 | P1 | PRD §35 mensyaratkan external backup; deploy README pernah mencatat `BACKUP_REQUIRE_REMOTE=false`. | OPEN — verifikasi konfigurasi aktual + bukti restore + backup media. |
| F-08 | P2 | `rebuild/TESTING.md` menyatakan bukan Playwright, tetapi `rebuild/tests/Browser/checkout-visual.mjs` memakai Playwright. | OPEN — koreksi dokumentasi jalur Chromium. |
| F-09 | P2 | `CLAUDE.md` bertanggal verified 4 Okt; 20 skill repo + 8 roles belum punya matriks bersama skills tambahan VM. | OPEN — menunggu PR-D (AGENTS.md + CLAUDE.md + skill discovery). |

### Lampiran: catatan status per temuan

Temuan F-01/F-02 **telah dibereskan** dalam PR #279 (baseline `780e9dc`), sehingga tidak lagi terbuka. Temuan F-03…F-09 tetap terbuka dan dilacak pada PR-D (F-09, sebagian F-03) dan PR-E (F-03, F-05, F-08) sesuai urutan roadmap. Temuan level P1 yang benar-benar **P1** (F-03, F-04, F-06, F-07 — **F-05 adalah P3 kosmetik**, bukan P1) tidak boleh diklaim selesai tanpa bukti regression/operasional.

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

*Bukti:* `rebuild/composer.json` (`laravel/framework` ^12, `spatie/laravel-medialibrary` ^11); `rebuild/package.json` (vue@3, `@inertiajs/vue`); `rebuild/` layout; deploy runbook CloudPanel/VPS. (Email via job Resend — `rebuild/config/mail.php` tidak ada di repo, lihat §29.)

### §3 Arsitektur Sistem
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Arsitektur layer services + controllers; struktur `rebuild/app/Services`, `rebuild/app/Models`, `rebuild/routes/web.php`, `rebuild/resources/js/Pages`, `rebuild/database/migrations`. |

*Bukti:* Peta layanan (PaymentService, FulfillmentService, CheckoutService, WalletTopupService, MembershipService, dst.) + migrasi terstruktur.

### §4 Kategori Produk
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Kategori: Game, Pulsa, Paket Data, PLN, Voucher, Entertainment, PPOB. E-wallet tidak menjadi kategori awal. Kategori extensible dari panel. |

*Bukti:* `rebuild/app/Models/Category.php`; route admin kategori `rebuild/routes/web.php:302-304` (`AdminCatalogController::category/updateCategory/destroyCategory`).

### §5 Model Katalog & Multi-Provider
| Status | Keterangan |
|---|---|
| PARTIAL / GAP | Struktur Kategori → Produk → Nominal → Provider Mapping. Satu nominal boleh punya banyak provider. Khusus Digiflazz: 1 SKU per nominal (model `_A`), tanpa `_B`. Fulfillment tidak terkunci hanya Digiflazz. **Auto-switch nonaktif** (data baru tidak pernah auto-switch ke SKU lain — PRD §47); **jalur retry-failover legacy tetap hidup** (F-04: baris data lama `safe_to_failover=true` masih reachable via Admin retry). **GAP:** invariant "1 SKU Digiflazz per nominal" hanya dijaga di sisi **send** (no auto-switch/no auto-failover baru), TETAPI sisi **konfigurasi** masih mengizinkan multi-SKU: `AdminDigiflazzController::attachSource()` dapat menambah mapping Digiflazz kedua, route `/admin/catalog/mappings/{mapping}` bisa mengaktifkannya tanpa cek sibling Digiflazz, dan schema hanya `unique(provider_id, external_sku)`. |

*Bukti:* `rebuild/app/Models/ProductPackage.php`, `rebuild/app/Models/ProviderMapping.php`; `rebuild/app/Services/DigiflazzCatalogImport.php`; `rebuild/app/Services/FulfillmentService.php:529-533` (`if (false && $failover)` dead + komentar "Per PRD §47") **dan `:577-578`** (`retrySafeAttempt` → `failoverIfSafe()` saat `safe_to_failover=true` — jalur retry legacy masih hidup, F-04 active gap); `rebuild/app/Http/Controllers/AdminDigiflazzController.php` (`attachSource`, `auto_sync`); `rebuild/database/migrations/2026_09_29_000001_create_identity_catalog_tables.php:134` (`unique(['provider_id','external_sku'])` — bukan unique per nominal). **Temuan:** F-04 retry-failover legacy (active gap) + invarian multi-SKU dibiarkan terbuka (PR §5).

### §6 Digiflazz
| Status | Keterangan |
|---|---|
| PARTIAL (karena F-04) | Panel import/sync produk & SKU Digiflazz. `buyer_sku_code` berasal dari data Digiflazz, tidak dimanipulasi customer. Seller selection/ranking manual (Buyer Area → Produk). **Digiflazz Tools terpisah + auto-switch tidak ada** (persyaratan §47 "no backup SKU"), TETAPI jalur **retry-failover legacy masih reachable** (F-04) — sehingga nomor ini perlu dicatat partial sampai jalur itu dihapus/di-guard. |

*Bukti:* `rebuild/app/Services/DigiflazzCatalogImport.php`; `rebuild/app/Services/AdminDigiflazzMonitorService.php`; `rebuild/app/Http/Controllers/AdminDigiflazzController.php`; `rebuild/app/Services/DigiflazzFixedPricePolicy.php`. Tidak ada route/service bersimbol `auto-switch` di `rebuild/app/`.

### §7 Produk Manual
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Tab Manual terpisah; tidak terbatas Roblox; gamepass/gift/custom/entertainment/voucher; mode `AUTO_PROVIDER` & `MANUAL`; ID internal DB LFAMILIA; dapat pakai Manual QRIS + notifikasi internal/webhook. |

*Bukti:* `rebuild/app/Services/AdminManualOrderService.php`; `rebuild/app/Models/ProviderMapping.php` (mode fulfillment konstanta); menu panel "Manual".

### §8 Customer Input & Nickname
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Field per produk configurable (User ID, Zone, Server, HP, meter PLN, email, username). Hanya field yang dibutuhkan provider dikirim. Provider nickname utama KokinPay. Super Admin kelola game code/mapping/test nickname/enable-disable checker/status koneksi. **Checker gagal → customer tetap boleh lanjut dengan peringatan.** Nama provider tidak ditampilkan ke customer. |

*Bukti:* `rebuild/app/Models/ProductInputField.php`; `rebuild/app/Services/NicknameService.php:63,130` (warning "Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia"); `rebuild/resources/js` field per produk.

### §9 Akun Pelanggan
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Guest Checkout + Registered Customer. Login email/password + Google OAuth. Setelah Google login, nomor HP wajib diisi tetapi OTP tidak wajib (v1). Fitur: profil, wallet, membership tier, riwayat, detail pesanan, tiket komplain, ubah/lupa password, hapus akun. |

*Bukti:* `rebuild/app/Http/Controllers/GoogleAuthController.php:109` (redirect: phone ? account : account.phone.edit); `rebuild/routes/web.php:125-128` (google redirect/callback); `rebuild/app/Actions/Fortify/` (register/login); `rebuild/app/Http/Controllers/Customer*/` (account, wallet, support).

### §10 Membership Pelanggan
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Terpisah dari RBAC Admin. Tier BASIC→SILVER→GOLD→DIAMOND→PLATINUM→MAFIA. BASIC default. Threshold/benefit configurable dari panel; tidak di-hardcode. Perubahan tier manual wajib audit log. |

*Bukti:* `rebuild/app/Models/MembershipTier.php`; `rebuild/app/Services/MembershipService.php:24` (`$tiers->firstWhere('code','BASIC') ?? $tiers->first()`); `rebuild/app/Services/AdminAuditService.php` (audit perubahan membership).

### §11 Guest Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Guest boleh membeli. Guest **tidak punya wallet/saldo**. Guest dapat order ID & cek status order. Guest dapat review setelah berhasil via token/signed link aman. Android tidak mendukung guest checkout. |

*Bukti:* `rebuild/app/Services/GuestOrderAccess.php` (token HMAC, `issue`/`issueStable`/`matches`/`store`); `rebuild/app/Http/Controllers/GuestOrderController.php`; `rebuild/app/Http/Controllers/ProductReviewController.php` (order check by `order_number`); `rebuild/routes/web.php:111` (`/reviews` dalam grup `throttle:guest-order`).

### §12 Lifecycle Akun
| Status | Keterangan |
|---|---|
| PARTIAL / GAP | Cleanup akun otomatis bila saldo Rp0, belum transaksi, tidak aktif ≥30 hari; backend verifikasi tidak ada saldo/transaksi/tiket/kewajiban; customer boleh hapus sendiri. **GAP:** `rebuild/app/Services/CustomerAccountDeletion.php:26` menolak deletion bila ada baris `support_tickets` **apapun** (termasuk tiket yang sudah CLOSED), sedangkan PRD §12 hanya mensyaratkan "tidak ada tiket **aktif**". Akibatnya akun tak aktif yang hanya punya tiket lama tutup tidak bisa dibersihkan. |

*Bukti:* `rebuild/app/Services/CustomerCleanupService.php`; `rebuild/app/Services/CustomerAccountDeletion.php:26` (`support_tickets->where('user_id')->exists()` tanpa filter status); migrasi lifetime terkait. **Aksi:** samakan filter tiket aktif (mis. `status != closed`) ATAU tandai gap; tidak diubah pada PR-C (dokumen saja).

### §13 Wallet / Saldo
| Status | Keterangan |
|---|---|
| IMPLEMENTED (sebagian besar) | Wallet wajib registered. Sumber saldo: top-up, penambahan Super Admin, penyelesaian pesanan bermasalah (tambah saldo). Min top-up default Rp10.000 configurable. Saldo tidak ditarik tunai. **Ledger** dengan amount, balance before/after, source, reference, actor, timestamp, idempotency key. Saldo negatif dilarang (locking/transaction). |

*Bukti:* `rebuild/database/migrations/2026_09_29_000002_create_commerce_tables.php` (tabel `wallets`, `wallet_ledger`, `wallet_topups`, index `wallet_source_reference_unique`); `rebuild/app/Services/WalletTopupService.php:54-58` (lockForUpdate + idempotency); `rebuild/app/Models/Wallet.php`. **Note:** SARAN per §47 tidak ada refund otomatis; penyelesaian pesanan = tambah saldo (dipisahkan dari webhook — lihat F-05).

### §14 Aplikasi Android (Fase Berikutnya)
| Status | Keterangan |
|---|---|
| DEFERRED | Tetap rencana fase berikutnya (addendum §47 2026-10-15). |

*Bukti:* Tidak ada kode Android pada baseline. Keputusan eksplisit.

### §15 Flow Checkout Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Urutan: input ID → cek nickname → pilih produk/nominal → metode bayar → voucher → ringkasan harga → buat order → bayar → verifikasi backend → fulfillment → selesai. Produk dinominal diurutkan berdasar nilai nominal, bukan harga jual. |

*Bukti:* `rebuild/app/Services/CheckoutService.php`; `rebuild/app/Services/CheckoutPricing.php`; `rebuild/app/Services/CatalogNominalOrder.php`; `rebuild/app/Services/VoucherStockService.php`; route `/checkout`; `rebuild/resources/js/Pages/Catalog/Show.vue`.

### §16 Status Order
| Status | Keterangan |
|---|---|
| IMPLEMENTED (status order) / CATATAN (refund manual) | Status resmi §16: Menunggu Pembayaran, Dibayar, Diproses, Berhasil, Gagal, Dibatalkan, Kedaluwarsa, BLOCKED. **"Refund" bukan status order otomatis** (addendum §47) — tetapi **refund NYATA & dilayani manual via CS**: customer mengajukan (5 kondisi, ≤7 hari), CS tinjau (1–7 hari), dana dicairkan transfer balik/saldo. Label `REFUND`/`REFUNDED`/`PAYMENT_REFUNDED` di runtime adalah status masuk gateway + penanda order yang menunggu/passed review refund, BUKAN eksekusi otomatis. |

*Bukti:* `rebuild/app/Services/PaymentStateService.php:33` (accepts PENDING/PAID/FAILED/CANCELLED/EXPIRED/REFUNDED/PARTIAL_REFUND_REVIEW), `:37-51` (partial-refund hanya event/log, tidak ubah saldo); `rebuild/app/Services/AdminOrderPresentation.php:15,17,27,41` (`STATUSES` berisi `REFUND`/`REFUNDED`/`PAYMENT_REFUNDED`); `rebuild/app/Http/Controllers/AdminOrdersController.php:104` (filter status); `rebuild/app/Http/Controllers/PublicOrderTrackingController.php:160,299,307,361`. **Refund manual via CS:** tidak ada endpoint refund otomatis; keputusan & pencairan dilakukan di panel/CS (DE-2026-10-17-05).

### §17 Pricing & Snapshot
| Status | Keterangan |
|---|---|
| PARTIAL / UNVERIFIED (untuk ML ×1,06) | Formula modal + margin + fee = harga final. Margin per produk (%) configurable. Fee gateway dibebankan ke customer. Kalkulasi final backend. Snapshot disimpan (modal, margin, discount, fee, total, provider, package, customer input). Harga order tidak berubah setelah dibuat. `max_price` menghentikan fulfillment. **Temuan:** keputusan §47 "**ML = modal × 1,06 tanpa pembulatan**" **belum terbukti diimplementasikan**: `CheckoutPricing::margin()` menerapkan `margin_percent`/`margin_fixed`/`sell_price` apa pun yang dikonfigurasi (bukan memaksa 6% untuk ML), `DigiflazzFixedPricePolicy::fixedPriceFromCost()` generik dan membulatkan hasil ke integer, dan tidak ada kode/seed yang mengidentifikasi produk ML lalu memaksa `cost × 1.06`. Sehingga kode hanya membuktikan 6% **bisa** dikonfigurasi, bukan invariant ML di-enforce. Harga jual game lain belum diputuskan — jangan diciptakan. |

*Bukti:* `rebuild/database/migrations/2026_09_29_000002_create_commerce_tables.php` (kolom `snapshot` JSON + `customer_input`); `rebuild/app/Services/CheckoutPricing.php:246-254` (margin dari `pricing_mode` generic); `rebuild/app/Services/DigiflazzFixedPricePolicy.php:13-31` (`fixedPriceFromCost` generic + `intdiv` rounding); `rebuild/app/Services/AdminPricingPreviewService.php` (preview max price). **Aksi:** verifikasi konfigurasi margin ML di produksi ATAU enforce & test invariant `cost × 1,06`; tidak diubah pada PR-C (dokumen). Note: harga lengkap non-ML ditunda ke pembahasan produk.

### §18 Voucher
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Voucher diskon. Periode aktif, min transaksi, limit per customer, total kuota, scope produk/kategori. Redemption atomic (anti double-use). |

*Bukti:* **Discount voucher (atomic redemption)** → `rebuild/app/Services/CheckoutService.php::voucher()` line 373–433 (`lockForUpdate` pada `vouchers`, hitung `voucher_redemptions` aktif, insert reservation di dalam transaction checkout — anti double-use); **voucher stock codes** (reserve inventory) → `rebuild/app/Services/VoucherStockService.php:67-90` (`claim()` lock `voucher_stock_codes`); route voucher; migrasi promo.

### §19 Payment Architecture
| Status | Keterangan |
|---|---|
| IMPLEMENTED (Midtrans) / UNVERIFIED (DOKU disabled) | Gateway awal: Midtrans Snap + DOKU Direct API. **DOKU dinonaktifkan sementara** (addendum §47) — **namun status disabled TIDAK terbukti dari kode repo**: `rebuild/app/Services/IntegrationRuntimeConfig.php:216` hanya menginfer environment production/sandbox dari `base_url`, tidak menonaktifkan gateway; selama kredensial DOKU terisi, `rebuild/app/Services/PaymentRoutingService::gatewayReady('DOKU')` (baris 282) tetap menerima DOKU dan admin dapat mengaktifkan gateway/route-nya. Keputusan §47 tetap berlaku sebagai arahan; bukti disabled harus dari state operasional (cek `payment_gateways.is_active` di produksi) atau kode guard. Customer pilih channel bukan gateway; Super Admin kelola routing/fee/maintenance; webhook = otoritas paid. |

*Bukti:* `rebuild/app/Services/PaymentService.php:340-345` (dispatch MIDTRANS/DOKU/MANUAL_QRIS); `rebuild/app/Services/PaymentRoutingService.php:278-282` (`gatewayReady()` DOKU = credentialHasKeys); `rebuild/app/Services/IntegrationRuntimeConfig.php:210-222` (infer environment, bukan disable); `rebuild/app/Services/Payment/*` (MidtransGateway, DokuDirectGateway, DokuSignature); migrasi `payment_route_fees_and_method_labels`. **Aksi:** verifikasi `payment_gateways.is_active` untuk DOKU di produksi, atau tambahkan guard kode bila perlu; tidak diubah pada PR-C (dokumen).

### §20 Manual QRIS
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Manual QRIS dibangun via `PaymentService` + `ManualQrisGateway`. Order dibuat → QRIS LFAMILIA ditampilkan → status Menunggu Pembayaran → notifikasi admin → konfirmasi manual → Dibayar → Diproses → Berhasil. Route internal `manual_qris` → `MANUAL_QRIS` di-seed aktif (`supports_order=true`, `is_active=true`). |

*Bukti:* `rebuild/app/Services/Payment/ManualQrisGateway.php:13-26` (`create()` → asset `manual_qris` + `public_payload.kind=manual_qris`); `rebuild/app/Services/PaymentService.php:345` (`'MANUAL_QRIS' => $this->manualQris->create()`); `rebuild/app/Http/Controllers/AdminPaymentController.php:800-824` (`ensureInternalRoutes()` — route `manual_qris`/`saldo`). **Catatan:** tidak mengubah PRD; koreksi klasifikasi dari DEFERRED (Roadmap Audit menyebut "pekerjaan penambahannya ditunda" — tetapi pada baseline `780e9dc` jalur ini sudah ada dan dipakai oleh test payment). Perilaku konfirmasi manual diverifikasi manual oleh admin/CS; tidak ada gateway webhook asli (sesuai PRD §20).

### §21 Notifikasi
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Notifikasi Panel, Telegram webhook, Discord webhook. Event: order baru, pembayaran berhasil, manual QRIS, provider gagal, pending lama, order berhasil, refund, tiket baru, integration/system error. Telegram/Discord bisa diaktif-nonaktifkan dari konfigurasi Super Admin. |

*Bukti:* `rebuild/app/Services/ExternalNotificationService.php` (telegram + discord send); `rebuild/app/Services/DiscordNotificationRouting.php`; `rebuild/app/Services/AdminNotificationService.php`; `rebuild/app/Services/TransactionalEmailService.php`; route admin webhook config.

### §22 Fulfillment & Reconciliation
| Status | Keterangan |
|---|---|
| IMPLEMENTED (dengan F-04/F-03) | Produk otomatis dikirim via queue setelah payment terverifikasi. Pending dicek ulang oleh queue/job. **Timeout/unknown tidak memicu provider kedua** sampai selesai reconciliation. Duplicate request & fulfillment dicegah. Reference unik + audit trail. |

*Bukti:* `rebuild/app/Services/FulfillmentService.php` (ref_id unik, `hash_equals` di line 1158, reconciliation, `retrySafeAttempt` `:577-578`), `rebuild/app/Services/Fulfillment/DigiflazzClient.php`, queue worker (lfamilia-queue). **GAP:** Temuan **F-04** jalur retry-failover legacy masih hidup (`safe_to_failover=true` baris lama, reachable via Admin retry) — active gap, bukan dead-code; **F-03** dokumentasi domain `rebuild/FULFILLMENT.md` tidak sinkron.

### §23 Review Pelanggan
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Order Berhasil membuat eligible review. Sistem tidak membuat isi otomatis. Guest via signed token. Review langsung tampil (v1) tanpa moderasi awal. Admin dapat hapus. |

*Bukti:* `rebuild/app/Http/Controllers/ProductReviewController.php:15,41-85` (store; verifikasi `order_number`; guest via session `guest_order_id` ATAU `GuestOrderAccess::matches()` baris 71-79; dedup `ProductReview::where('order_id',...)->exists()` baris 83); `rebuild/routes/web.php:111,338,339`; `rebuild/app/Services/GuestOrderAccess.php:11,30` (`issue`/`matches`).

### §24 Role & Akses Administrasi
| Status | Keterangan |
|---|---|
| IMPLEMENTED | SUPER_ADMIN & ADMIN. ADMIN tidak boleh melihat secret/API credential sensitif. Staff tidak dipakai pada v1. Membership BASIC–MAFIA bukan role admin. |

*Bukti:* `rebuild/app/Models/AdminUser.php`; `rebuild/app/Services/AdminPermissionService.php`; route middleware `admin.super` / `admin`; migrasi RBAC/auth.

### §25 Menu Panel
| Status | Keterangan |
|---|---|
| IMPLEMENTED | 18 menu: Dashboard, Pesanan, Produk, Manual, Banner & Konten, Digiflazz, Provider, Pembayaran, Pelanggan, Promo, Layanan Pelanggan, Laporan, Admin & Akses, Pengaturan, Integrasi, System Health, Audit Log, **Validasi Akun** (addendum §47). |

*Bukti:* Route admin (`rebuild/routes/web.php` grup `admin`): `/admin/health`, `/admin/digiflazz`, `/admin/nickname-tools`, `/admin/providers` (`AdminProviderController`, baris 361-362), `/admin/content/reviews`, dst. Menu "Validasi Akun" = AccountValidationConfig + `AdminDigiflazzController`/`AdminNicknameToolsService`.

### §26 Audit Log
| Status | Keterangan |
|---|---|
| IMPLEMENTED / PARTIAL (untuk event "reveal secret") | Catat actor, role, action, target, before, after, IP, user agent, timestamp, correlation ID. Sensitif: harga, saldo, refund, provider, gateway, integration credential, role/admin, manual payment confirmation, perubahan membership. Log tersensor secret. **Temuan:** row menyebut "reveal secret" sebagai event audit, padahal §33 menetapkan **tidak ada endpoint reveal** (`SecurityM10Test` assert 404) — sehingga tidak ada aksi reveal yang dapat menghasilkan audit record. Konsisten dengan keputusan no-reveal §33; rujukan "reveal secret" di sini adalah sisa teks PRD §26, bukan capability aktif. |

*Bukti:* `rebuild/app/Services/AdminAuditService.php:12-13` (list secret keys), `:55-69` (redact); `rebuild/app/Http/Controllers/AdminAuditController.php`; middleware `rebuild/app/Http/Middleware/AssignCorrelationId.php`; `Middleware` `admin.password.confirm` untuk sensitive actions. Tidak ada event reveal di `AdminAuditService` karena tidak ada reveal endpoint (§33).

### §27 Frontend Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED (desktop) / PARTIAL (mobile belum tervalidasi visual) | Re-dibuat meniru Ourastore; branding LFAMILIA; tema dark modern gaming; buang kotak-kotak; desktop dulu, mobile setelah stabil. |

*Bukti:* `rebuild/resources/js/Pages/*`; `rebuild/resources/js/Components/CustomerShell.vue`; App.css/tailwind theme dark. **Note:** DoD §41 belum centang mobile responsive — lihat Temuan/Acceptance.

### §28 Media Management
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Spatie Media Library. Super Admin ganti logo, favicon, banner desktop/mobile, gambar produk/nominal, popup, aset konten. Storage local VPS dan/atau object storage. |

*Bukti:* `rebuild/composer.json` (`spatie/laravel-medialibrary` ^11); `rebuild/app/Models/StoreAsset.php`, `rebuild/app/Models/HomeBanner.php`, `rebuild/app/Models/SitePopup.php`; route admin banner/popup/content.

### §29 Email
| Status | Keterangan |
|---|---|
| IMPLEMENTED / CATATAN (event refund) | Provider: **Resend**. Email via queue untuk verification, reset password, payment success (confirmed), transaction success (completed), support ticket, security notification. **Catatan (DE-2026-10-17-05):** PRD §29 mencantumkan "refund" sebagai event email, tetapi `TransactionalEmailService` tidak punya metode email refund — `queueOrderFailed()` berkomentar "refund — topik refund ditutup, tindak lanjut lewat CS". **Ini konsisten dengan proses nyata**: refund = manual via CS (bukan event sistem), jadi tidak ada email otomatis refund; komunikasi hasil review refund dilakukan CS langsung ke customer. Bukan GAP. `MAIL_MAILER=log` di `rebuild/.env.example` — provider produksi belum diverifikasi. |

*Bukti:* `rebuild/app/Jobs/SendTransactionalEmailJob.php:28,35` (fetch kredensial `IntegrationCredential` code `resend`, POST `https://api.resend.com/emails`); `rebuild/app/Services/TransactionalEmailService.php:51,105,165,233` (`queueOrderCreated`/`queuePaymentConfirmed`/`queueOrderCompleted`/`queueOrderFailed`); `rebuild/app/Services/PaymentStateService.php:281-298,388-412` (cabang refund = notifikasi admin saja); `rebuild/.env.example:36` (`MAIL_MAILER=log` — tanpa `rebuild/config/mail.php` ter-commit; pengiriman Resend via job + kredensial). **Catatan:** tidak ada email refund karena refund manual via CS (DE-2026-10-17-05), bukan bug.

### §30 Infrastruktur VPS & CloudPanel
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Satu VPS: Nginx, PHP-FPM, Laravel, MySQL, Redis, Queue Worker, Scheduler, CloudPanel. Cloudflare di depan (DNS, proxy, WAF, rate-limit, Turnstile, edge). |

*Bukti:* service aktif di produksi (nginx, php8.4-fpm, mysql, redis-server, lfamilia-queue, lfamilia-scheduler); `.github/workflows/validate-rebuild.yml` (M12 deployment assets check); `rebuild/deploy/*.sh`.

### §31 GitHub & Deployment
| Status | Keterangan |
|---|---|
| IMPLEMENTED | GitHub = source & CI; credential tidak di repo; deploy dari VPS dengan repo Git; alur dev→commit/PR→CI hijau→main→produksi pull→composer/build/migrate/cache→queue restart→health check. Produksi prioritas; staging bisa ditambahkan. |

*Bukti:* `.github/workflows/validate-rebuild.yml`; `rebuild/deploy/{preflight,deploy,healthcheck,backup,restore-verify}.sh`; `/etc/lfamilia/ops.env` (REPO_REF=main, DEPLOY_REQUIRE_RECENT_BACKUP=true). **Note:** docs-only change tidak memerlukan deploy produksi (AGENTS.md §6).

### §32 Security Baseline
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Session Admin/Super Admin 24 jam. 2FA ditunda (v1) tapi arsitektur siap. Turnstile selektif (register, forgot password, suspicious login, form publik). Rate limit minimal: login, register, forgot password, nickname, order lookup, payment create, wallet top-up, voucher validation, API publik sensitif. |

*Bukti:* `rebuild/config/session.php:7` (lifetime 1440 min = 24h); `rebuild/app/Services/TurnstileService.php`; middleware `rebuild/app/Http/Middleware/PublicAbuseProtection.php`; `rebuild/routes/web.php` throttle groups (admin/account/support/checkout/wallet/guest/google/payment). **Note:** 2FA tidak aktif — keputusan v1.

### §33 Secret & Integration Credential
| Status | Keterangan |
|---|---|
| PARTIAL / GAP (controlled reveal) | Credential tidak hardcoded di repo; dikelola via Super Admin → Integrasi; nilai sensitif disimpan terenkripsi di DB; log menyensor password/key/secret/token/private-key/signature. **Temuan:** klaim "controlled reveal hanya Super Admin + audit" **tidak didukung baseline** — TIDAK ada route/controller reveal (`grep` route + controller + `AdminPermissionService` = tidak ada), dan `SecurityM10Test::test_secret_reveal_endpoint_is_not_exposed` (baris 252-274) justru meng-assert `404` untuk `POST /admin/integrations/midtrans/reveal/server_key` — bahkan dengan password yang benar. Jadi PRD mensyaratkan reveal auditable, baseline justru **tidak menyediakan reveal sama sekali** (defense-in-depth), tanpa keputusan supersede tertulis. Keputusan "no reveal" yang tersirat ini perlu didokumentasikan eksplisit (mis. addendum §47) atau fitur reveal ditambahkan dengan audit trail. |

*Bukti:* `rebuild/app/Models/IntegrationCredential.php:15` (`config_ciphertext` => `encrypted:array`); `rebuild/tests/Feature/SecurityM10Test.php:252-274` (reveal endpoint di-assert 404); tidak ada token reveal di `rebuild/routes/` maupun `AdminPermissionService`. Tidak ada secret di tracked source (composer audit + guardrails CI).

### §34 System Health & Observability
| Status | Keterangan |
|---|---|
| IMPLEMENTED (check) / partial (population) | Pantau Laravel, MySQL, Redis, Queue, Scheduler, disk/storage, Digiflazz, KokinPay, Midtrans, DOKU, Resend. Status: Healthy, Degraded, Down, Not Configured, Maintenance. Correlation ID untuk setiap request/transaksi penting. |

*Bukti:* `rebuild/app/Http/Controllers/AdminHealthController.php:23-130` (states HEALTHY/DEGRADED/DOWN/UNKNOWN); /health/ready route; middleware `rebuild/app/Http/Middleware/AssignCorrelationId.php`; `rebuild/app/Http/Controllers/CheckoutController.php` (corr ID). **Note:** coverage penuh semua monitor perlu verifikasi lanjut — label `partial` agar tidak mengklaim lebih.

### §35 Backup & Recovery
| Status | Keterangan |
|---|---|
| IMPLEMENTED (VPS) / GAP (external storage) | Backup MySQL otomatis harian; simpan di VPS + external/object storage; retention awal 7 hari; data sensitif dienkripsi; restore test berkala ke non-production; Export Configuration JSON (tanpa credential). |

*Bukti:* `rebuild/deploy/backup.sh` (encrypted `.tar.gz.enc`); `rebuild/deploy/restore-verify.sh` (checksum + restore temp DB); `/etc/lfamilia/ops.env` (`DEPLOY_REQUIRE_RECENT_BACKUP=true`); backup produksi terverifikasi `lfamilia-20261017T085010Z.tar.gz.enc`. **GAP:** Temuan **F-07** — external backup/RETENTION belum terverifikasi; `BACKUP_REQUIRE_REMOTE` pernah `false` di deploy README. Lihat Temuan.

### §36 Order & Wallet Security
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Backend tolak harga dari frontend, SKU invalid, produk/nominal nonaktif, provider code manipulated, fee palsu, total Rp0 ilegal, voucher tidak sah/expired/over-quota, negative amount. Backend hitung ulang. Payment create + tombol bayar idempotent. Wallet credit/debit, refund, fulfillment, callback idempotent. |

*Bukti:* `rebuild/app/Services/PaymentService.php:29-121` (startOrder idempotency key + fingerprint + 409); `rebuild/app/Services/CheckoutInputValidator.php`; `rebuild/app/Services/WalletTopupService.php` (lockForUpdate + idempotency). **Note:** idempotency wallet debit; refund tidak otomatis (manual via CS — lihat DE-2026-10-17-05).

### §37 Callback / Webhook Security
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Verifikasi signature & source per provider; idempotency protection; callback disimpan sebagai audit/event; replay berbahaya ditolak; final state tidak di-downgrade. |

*Bukti:* `rebuild/app/Http/Controllers/PaymentWebhookController.php:42` (`hash_equals($expected, $payload['signature_key'])`), `:119-121` (Doku timestamp & client_id), `:74-75` (merchant_reference + amount match); `rebuild/app/Services/MidtransStatusVerification.php`; `rebuild/app/Services/Payment/DokuSignature.php`. **Note:** final-state guard ada (`IGNORED_FINAL`), guard anti-downgrade terverifikasi di PaymentStateService.

### §38 Testing Wajib
| Status | Keterangan |
|---|---|
| IMPLEMENTED (praktik) / PARTIAL (gap coverage) | Backend unit/feature, API, auth/RBAC, payment state, wallet & ledger, voucher concurrency, provider/reconciliation, queue/job, frontend smoke (customer & admin), abuse tests. |

*Bukti:* `rebuild/tests/Unit/*`, `rebuild/tests/Feature/*`; `rebuild/tests/Browser/*.mjs` (Playwright) + `rebuild/tests/Browser/smoke.sh`; CI `.github/workflows/validate-rebuild.yml` (foundation + frontend-static). **Note:** Temuan **F-08** — `rebuild/TESTING.md` menyatakan bukan Playwright padahal checkout-visual.mjs memakai Playwright (documentation drift). Abuse-test coverage penuh perlu review.

### §39 Main Branch Policy
| Status | Keterangan |
|---|---|
| POLICY (bukan enforcement) / UNVERIFIED | main selalu deployable. Merge ditolak bila build, automated tests, migration validation, lint/type check, atau critical security checks gagal. **Temuan:** ini adalah **aturan prosedural** (`AGENTS.md` §4 + CI workflow), BUKAN enforcement GitHub yang terbukti. `.github/workflows/validate-rebuild.yml` mendefinisikan job/trigger, tetapi **tidak ada ruleset/branch-protection yang memverifikasi** bahwa check gagal benar-benar menolak merge. Pemeriksaan GitHub API `GET /branches/main/protection` → `404 Branch not protected`, sehingga maintainer masih bisa merge perubahan yang gagal check. |

*Bukti:* `.github/workflows/validate-rebuild.yml` (jobs & trigger, tanpa ruleset); `AGENTS.md` §4 (prosedur); `gh api repos/.../branches/main/protection` → 404 (tidak diproteksi). **Aksi:** aktifkan required status checks di branch protection `main`, atau tandai sebagai policy yang belum di-enforce; tidak diubah pada PR-C (dokumen).

### §40 Milestone Pembangunan
| Status | Keterangan |
|---|---|
| Per-milestone (nama sesuai PRD §40) | **IMPLEMENTED:** M1 Foundation, M2 Database, M3 Authentication, M9 Admin, M11 Testing. **PARTIAL:** M4 Customer (gap lifecycle §12 — `CustomerAccountDeletion` menolak bila ada tiket closed), M5 Product (multi-SKU Digiflazz, §5), M6 Checkout (harga ML ×1,06 belum di-enforce, §17), M7 Payment (status DOKU disabled belum terbukti di repo, §19), M8 Fulfillment (retry-failover legacy masih hidup — F-04), M10 Security (controlled reveal tidak ada, §33), M12 Deployment (backup, F-07), M13 Acceptance (E2E sandbox ditunda). |

*Bukti:* Baseline main ter-validasi CI (foundation + frontend-static). Migration/asset/schema ada; deploy scripts (`rebuild/deploy/*.sh`, systemd units) ada. Gap per milstone: M4 (§12 lifecycle tiket closed), M5 (§5 multi-SKU), M6 (§17 ML pricing), M7 (§19 DOKU unverified), M8 (F-04 retry-failover legacy — active gap), M10 (§33 no-reveal), M12 (backup F-07), M13 (E2E sandbox belum dijalankan).

### §41 Definition of Done
| Status | Keterangan |
|---|---|
| PARTIAL | Checklist DoD: item yang sudah dicentang — [x] customer desktop, [x] register/login/google/forgot, [x] guest checkout, [x] wallet & membership, [x] catalog/nickname/voucher/pricing/snapshot, [x] multi-provider abstraction & reconciliation, [x] Super Admin/Admin permissions, [x] Telegram/Discord/email/health/audit, [x] queue & scheduler stabil, [x] tidak ada critical security issue, [x] abuse test & full checkout smoke, [x] build production & test wajib hijau. **Belum dicentang:** customer mobile responsive; Midtrans/DOKU/Manual QRIS/Digiflazz manual fulfillment (_DOKU disabled — sisanya jalan_); backup otomatis & restore berkala (restore-verify ada, bukti eksekusi berkala belum diverifikasi — F-07). |

*Bukti:* `docs/PRD-LFAMILIA-STORE-v1.md` §41; item yang masih terbuka berhubungan dengan **F-07** (backup otomatis & restore berkala belum terbukti). F-01/F-02 (CI) sudah SOLVED via PR #279 dan tidak lagi memengaruhi DoD.

### §42 Non-Negotiable Requirements
| Status | Keterangan |
|---|---|
| IMPLEMENTED (dengan catatan F-04) | 11 non-negotiable: tidak percaya harga frontend; tidak simpan secret di GitHub; guest tidak punya wallet; nama provider tidak tampil; tidak double fulfillment; tidak failover sebelum status pasti; redirect bukan bukti paid; harga order tidak berubah; Android tidak buat DB customer terpisah; checkout tidak depend hardcoded satu provider; membership bukan akses admin. **Catatan:** invariant "tidak failover sebelum status pasti" terjaga untuk data baru; jalur retry-failover legacy tetap reachable (lihat F-04) sehingga belum bisa diklaim menyeluruh sampai jalur itu dibersihkan. |

*Bukti:* `rebuild/app/Services/CheckoutInputValidator.php` (harga/SKU); `rebuild/config/` (credential tidak tracked); `rebuild/app/Services/GuestOrderAccess.php` (guest tanpa wallet); `rebuild/app/Services/FulfillmentService.php` (no double, no auto-switch; jalur retry-failover legacy masih hidup — lihat F-04); `rebuild/app/Http/Controllers/PaymentWebhookController.php` (webhook = otoritas paid); `rebuild/app/Models/Product.php` (`snapshot` harga); `rebuild/app/Services/MembershipService.php` (terpisah); `rebuild/app/Models/ProviderMapping.php` (multi-provider).

### §43 Keputusan yang Dapat Dikonfigurasi di Panel
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Min top-up; margin per produk; gateway & channel aktif; routing channel Midtrans/DOKU; fee per channel; maintenance mode; kategori/produk/nominal aktif; nickname mapping & game code; voucher rule; webhook Telegram/Discord; membership threshold/benefit; logo/favicon/banner/popup. |

*Bukti:* `rebuild/config/` + `rebuild/app/Services/*` (PaymentPageSettingsService, StorefrontContentService, VoucherStockService, MembershipService); tabel `settings`/`integration_credentials`, `payment_channels`, `payment_routes`, `membership_tiers`.

### §44 Prinsip Implementasi
| Status | Keterangan |
|---|---|
| IMPLEMENTED | Untuk **perilaku implementasi aktual**, hierarki bukti mengikuti CLAUDE.md §3: kode/migrasi/test/runtime-config → dokumentasi operasional `rebuild/` → **product intent** (PRD v1.1 + log keputusan §47). PRD v1.1 adalah sumber **kebijakan produk & business intent**; kode yang sudah terverifikasi adalah bukti perilaku aktual — jika berbeda, telaah dulu apakah perbedaan itu keputusan baru / gap / stale doc / bug (CLAUDE.md:61-68), bukan otomatis menangkan salah satu. Implementasi boleh memperbaiki struktur/keamanan/performansi tetapi tidak mengubah alur bisnis tanpa keputusan eksplisit. Frontend mengejar kemiripan Ourastore; backend mandiri, aman, provider-agnostic. |

*Bukti:* `AGENTS.md`, `CLAUDE.md` (hierarki dokumen); dokumen ini (traceability) menautkan PRD → bukti. Perilaku aktif server-side pada `rebuild/`.

### §45 Homepage Support, Footer & Navigasi Customer
| Status | Keterangan |
|---|---|
| IMPLEMENTED (struktur) / CATATAN (konten legal) | CTA/bantuan "BUTUH BANTUAN? / Tim LFAMILIA siap membantu"; banner/promotional strip configurable; identitas footer logo + tagline "Top up favoritmu, sat set tanpa ribet."; tautan sosial WhatsApp/Instagram/Email/Discord (edit dari panel, ikon tanpa config boleh disembunyikan); bagian LAYANAN (Top Up Game, Promo, Cek Transaksi, Hubungi Kami) menuju route/features berfungsi; bagian KALKULATOR (Win Rate, Zodiac, Magic Wheel, Semua Alat); bagian INFORMASI (FAQ, Syarat & Ketentuan, Kebijakan Pengembalian Dana, Kebijakan Privasi); copyright dinamis; floating support/chat button. **Catatan (keputusan owner, DE-2026-10-17-05):** halaman legal `/refund`, `/terms`, `/privacy` menyebut refund — **dibiarkan sebagaimana adanya**. Per §13/§47, penyelesaian pesanan bermasalah = tambah saldo/manual CS, bukan refund otomatis; konten legal ditangani di luar scope roadmap ini. Bukan GAP yang ditindaklanjuti di sini. |

*Bukti:* `rebuild/app/Services/StorefrontContentService.php:24-27` (supportWhatsapp/instagramUrl/discordUrl); `rebuild/resources/js/Components/CustomerShell.vue:48-71` (kalkulator/social/nav); `rebuild/routes/web.php:65-78,77-78` (`/faq`, `/tools`, `/tools/{tool}`, `/refund`); `rebuild/app/Http/Controllers/PublicContentController.php:33,45,50,55,63` (faq/legal/refund); `rebuild/database/migrations/2026_09_30_000010_create_customer_content_tables.php` + `rebuild/database/migrations/2026_10_02_000002_sync_storefront_legal_pages.php` (seeded `content_pages`: refund/terms/privacy); `rebuild/database/data/legal-pages-2026-10-02.json`; `rebuild/app/Models/FaqEntry.php`, `rebuild/app/Models/HomeBanner.php`, `rebuild/app/Models/SitePopup.php`. **Keputusan owner:** konten legal dibiarkan, lihat DE-2026-10-17-05.

### §46 Acceptance Criteria — Footer & Support Customer
| Status | Keterangan |
|---|---|
| PARTIAL / UNVERIFIED | CTA & tombol Hubungi Kami; banner bottom tanpa crop/space; logo/tagline/4 kanal sosial config; semua link Layanan/Kalkulator/Informasi benar; floating support tidak menutup konten; tidak ada link dummy/404; penggantian logo/banner/link dari panel ter-refleksi; visual konsisten tema dark. |

*Bukti:* Komponen + route di atas; **belum ada bukti UI acceptance penuh (desktop/mobile) yang tervalidasi pada baseline.** Item belum terverifikasi: CTA/button aktif, banner tanpa crop, 4 kanal sosial config, semua link Layanan/Kalkulator/Informasi benar (bukan dummy/404), floating support tidak menutup konten, refleksi perubahan panel ke frontend, visual konsisten tema dark. DoD §41 juga belum mencentang mobile responsive. Ini dilacak sebagai gap UI acceptance, bukan F-09 (F-09 = stale CLAUDE.md/skill-matrix, tidak menyentuh visual).

### §47 Keputusan Pasca-PRD
| Status | Keterangan |
|---|---|
| IMPLEMENTED (sebagai log) | Log keputusan bersumber pada PRD §47 & dokumen ini. **Untuk business intent**, source-of-truth = PRD + log keputusan; **untuk perilaku implementasi aktual**, kode/runtime terverifikasi adalah bukti (CLAUDE.md §3) dan menang bila berbeda dengan teks PRD yang stale. Pool keputusan: **refund tidak otomatis — penyelesaian pesanan bermasalah via manual CS/tambah saldo (DE-2026-10-17-05)**; `_B` removed; ML = modal × 1,06 (tidak dibulatkan); satu nominal multi-provider; Digiflazz Tools + auto-switch belum ada; DOKU disabled temporarily; Menu Validasi Akun; Website Live / M13 partial; Android tetap rencana. |

*Bukti:* `docs/PRD-LFAMILIA-STORE-v1.md` §47 table; implementasi aktif terhadap tiap keputusan: `rebuild/app/Services/FulfillmentService.php:474,529-533` (no auto-switch), `rebuild/app/Services/PaymentStateService.php:37-51` (refund tidak otomatis — status masuk gateway, eksekusi manual via CS, DE-2026-10-17-05), `rebuild/app/Services/DigiflazzFixedPricePolicy` (cost/max_price), `rebuild/app/Services/PaymentRoutingService.php:278-282` + `rebuild/app/Services/IntegrationRuntimeConfig.php:210-222` (DOKU — environment inference saja; status disabled TIDAK terbukti dari repo, lihat §19), `rebuild/app/Services/AccountValidationConfig` (menu Validasi Akun), `rebuild/app/Services/MembershipService` (terpisah dari RBAC).

---

## Decision Register (dibuat pada FASE 2, hanya observasi — tidak mengubah keputusan PRD)

| ID | Tanggal | Keputusan / Observasi | Dampak | Sumber |
|---|---|---|---|---|
| DEC-2026-10-17-01 | 2026-10-17 | PRD v1.1 adalah master **kebijakan produk**; dokumen traceability ini adalah peta bukti, bukan sumber keputusan baru. Untuk perilaku implementasi aktual, bukti runtime terverifikasi menang atas teks PRD yang stale (CLAUDE.md §3). | Hierarki dokumen mengikuti AGENTS.md §0 & PRD §44; hierarki bukti runtime mengikuti CLAUDE.md §3. | Roadmap Audit, PR-C. |
| DEC-2026-10-17-02 | 2026-10-17 | Item yang belum diverifikasi (F-07 external backup, F-08 TESTING.md Playwright, F-04 retry-failover legacy belum dibersihkan, M13 E2E, mobile acceptance §46) tidak boleh diklaim "selesai" — harus punya bukti operasi/regression hijau. | Mencegah over-claim; baris DoD yang belum dicentang tetap terbuka. | PRD §41, Temuan F-*. |
| DEC-2026-10-17-03 | 2026-10-17 | Status `REFUNDED`/`PARTIAL_REFUND_REVIEW` pada state machine menerima status masuk dari gateway — **bukan** operasi refund otomatis. Refund = **keputusan manual via CS** (review kelayakan, lalu cair transfer balik/saldo). Pemisahan konsep ini penting agar tidak ada yang mengira sistem mengeksekusi refund. | Tidak menambah jalur refund otomatis; refund manual via CS. | PRD §13, §16, §29, §47; keputusan owner. |
| DEC-2026-10-17-04 | 2026-10-17 | Temuan yang **belum** dibereskan (F-03…F-09) tetap terbuka sampai tiap temuan punya bukti implementasi/regression (bukan hanya tangkapan layar). **F-01/F-02 sudah SOLVED via PR #279** dan tidak lagi terbuka. | PR-D/PR-E menangani dokumen drift; F-04/F-06 menunggu regression test; F-07 menunggu bukti eksekusi backup eksternal; F-01/F-02 tidak dijadwalkan ulang. | Roadmap Audit. |
| DEC-2026-10-17-05 | 2026-10-17 | **Keputusan owner:** penyelesaian pesanan bermasalah ditangani **manual via CS / tambah saldo**, bukan refund otomatis. Halaman legal `/refund`, `/terms`, `/privacy` yang masih menyebut refund **dibiarkan sebagaimana adanya** — bukan GAP yang ditindaklanjuti dalam roadmap ini. Perbedaan label state machine (`REFUNDED`/`PARTIAL_REFUND_REVIEW`) adalah adapter status masuk gateway, bukan jalur refund keluar. **Canonical policy sudah disinkronkan (PR #283 di-merge ke main, `be250100`):** AGENTS.md §3 & §41 dan PRD §47 kini menyatakan refund dilayani manual via CS, sistem tidak mengotomatiskan refund. Tidak ada lagi kontradiksi istruksi operasional pada register ini. | Menutup diskusi refund: tidak ada implementasi refund otomatis; tidak ada koreksi konten legal di PR-C/PR-D/PR-E. Hanya dicatat. | PRD §13, §16, §47; AGENTS.md §3, §41. |

Dokumen ini hanya observasi & traceability. Tidak ada perubahan runtime; tidak ada deploy yang dibutuhkan (AGENTS.md §6: dokumentasi murni).
