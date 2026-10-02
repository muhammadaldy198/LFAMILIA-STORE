# Admin panel restoration — 2026-10-02

The Laravel/Vue admin uses shadcn-vue controls and an accessible Sheet on mobile. Customer-facing controls and design remain separate.

| Menu | Available operations |
| --- | --- |
| Dashboard | Metrics, recent orders, notifications, global search |
| Pesanan | Invoice search, status filter, pagination, detail, payment history, fulfillment history |
| Produk | Categories, product editor, images/banner/notices, customer fields with preview, nominal table and editor, provider SKU import, individual sync, product/global margin, per-nominal percent/fixed/final price, drag order and mobile order buttons |
| Manual | Antrean proses terbaru per pesanan, metrik, pencarian, filter, pagination, penanganan manual, hasil/kegagalan, pemeriksaan status, dan retry/failover aman |
| Banner & Konten | Banner desktop/mobile, satu pop-up, logo & gambar storefront, blok bantuan/footer, news, moderasi reviews, FAQ, dan halaman kebijakan; kontak toko tetap di Pengaturan agar tidak ganda |
| Digiflazz | Dedicated operational monitor, connection state, owner-only balance, normal/warning/critical summary, search/category/product/brand/health filters, mapped/all scope, stock, buyer/seller status, cut-off, multi, baseline, recent transactions, per-SKU/all sync, configurable auto-sync interval and warning thresholds |
| Validasi Akun | Game codes, game nickname, MLBB region, PLN checks |
| Provider | Daftar provider dengan nama/keterangan/urutan/status yang dapat diubah, ringkasan kesehatan dan transaksi, serta inventaris mapping nominal tanpa menggandakan editor SKU/harga/margin di menu Produk |
| Pembayaran | Manual QRIS and confirmation, page editor, gateway/channel fees/routing, minimum wallet topup |
| Pelanggan | Search, pagination, membership, owner-only balance adjustments, order/ledger history |
| Promo | Vouchers with scope/quota/dates, popular products |
| Layanan Pelanggan | Conversation/status/reply, editable quick replies |
| Laporan | Date range, daily totals, best-selling products, provider error rate |
| Admin & Akses | Super Admin/Admin, permissions, account status/password, last-owner protection |
| Pengaturan | Store identity, contact/business hours, membership settings, safe configuration export |
| Integrasi | Encrypted credentials, password-protected reveal, connection test, current XSRF cookie |
| System Health | App, DB, Redis, worker/scheduler, integrations |
| Audit Log | Audited changes and pagination |

## Important behavior

- Imported nominal and mapping stay inactive until reviewed.
- Sync takes SKU and modal from provider response, never from customer input. Max price equals synced modal.
- Existing order snapshots are not rewritten by pricing or synchronization changes.
- Menu Manual hanya mengizinkan tindakan pada proses terbaru setiap pesanan; proses lama ditolak untuk mencegah double fulfillment.
- Pemeriksaan status transaksi tidak pasti memakai referensi proses yang sama dan tidak membuat transaksi baru.
- Buyer/seller inactive, finite stock zero, missing SKU after full sync, and cut-off prevent checkout.
- Fixed sell price below modal is rejected and is blocked at checkout if modal later rises.
- Input keys used by nickname or delivery templates cannot be removed until their references are changed.
- Provider responses expose buyer catalog data; seller rating/SLA and marketplace seller selection are not provided by this endpoint and are not fabricated.
- Activation links are time-limited and single-use; the owner selects a password. No public self-registration grants admin privileges.
- Live payment/provider transactions require configured integrations and a separate live test. HTTP fakes in regression tests do not prove external service health.

## Verification

Feature regressions use a separate MySQL database. Browser verification covers 360, 390, 768, and 1440 widths, all 18 menus, populated catalog/order/customer views, mobile Sheet, activation-to-login-to-panel, current XSRF handling after activation, quick replies, and persisted nominal/customer-field changes. No test fixture credentials belong in production.
