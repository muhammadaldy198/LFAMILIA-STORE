# Checkout Lab → LFAMILIA STORE

Visual baseline: LFAMILIA-CHECKOUT-LAB `a42f97d3a6d5a8a5cb59bd651ce9ffe1a8c928f2`. Production base: `cc0e9d3c9b4f3cf3e13dd81d71a7828f7be7dcfb`.

Owner correction: the new palette applies only to customer pages. Admin stays white. The customer palette editor lives in Admin → Banner & Konten → Teks & Tampilan → Warna Website.

## Runtime integration

`Catalog/Show.vue` retains production catalog props, account validation, saved accounts, reviews, merchant notices and existing checkout/payment endpoints. No Lab mock, pricing simulator, dummy voucher or success page is imported. Guest email remains required by the existing server contract. Products without account fields skip the account section. Package tabs/grouping remain catalog-driven.

Optional `include_channel_totals` on `/checkout/quote` returns preview amounts calculated by the existing checkout/pricing/routing services. It avoids a separate rate-limited request per channel. Unavailable guest wallet is excluded. The selected channel quote remains authoritative. Confirmation re-quotes and renews consent when amounts change. Checkout/payment idempotency is preserved. Financial payloads contain no client prices.

LFAMILIA Coin is a display label using the configured channel logo or existing official wallet asset. Ledger, fee rules, gateway, refund, SKU and fulfillment policy are unchanged.

## CSS boundary

- `tokens.css`: semantic customer colors only, no geometry.
- `app.css`: legacy selectors use the zero-specificity `:where(:not(.lfx, .lfx *))` boundary, including their `!important` rules. It works in browsers without CSS `@scope`.
- `checkout.css`: approved Lab geometry in the `.lfx` namespace; measured baseline sizes belong to checkout, independent of admin typography settings.
- Payment/voucher components: local scoped styles.
- `admin.css`: original white admin rules in an independent Vite entry, loaded only on admin requests. Customer color settings do not change admin tokens. Client navigation disables the admin stylesheet on customer pages and re-enables it on admin routes; entering admin without its entry requests the fully styled document.

The checkout action gradient derives from the saved primary palette while retaining the exact approved default stops.

Sticky controls share one height variable: 54 px below 768, 62 px at 768–1023, 70 px from 1024. Artwork is square before perspective. Desktop/tablet banners contain the full image; phones crop horizontally only. Missing banners show the background. Content padding/support drag bounds reserve the action bar on every device.

## Admin management

| Setting | Workspace |
|---|---|
| Product title, publisher, description, account fields | Produk → product editor |
| Product image/banner | Produk → media upload |
| Nominal groups, tabs, ordering, artwork | Produk → nominal editor |
| Active payment methods, logos, ordering | Pembayaran → Metode & Urutan |
| Voucher eligibility | Promo |
| Product notices/operating hours | Produk → Informasi |
| Support/contact/website content | Banner & Konten / Pengaturan |
| Checkout account/help text | Teks & Tampilan → Teks halaman |
| Customer palette, preview, default reset | Teks & Tampilan → Warna Website |

Palette edits require registered HEX keys, server contrast validation, `content.manage`, existing `system_settings` storage and `AdminAuditService`. Reset is previewed before saving. No arbitrary layout parameters or credential editors are added.

## Data

No schema migration. `store.theme` is written only when an authorized admin saves. Existing merchant prices/settings remain intact. Defaults apply when the setting is absent or corrupted.

## Validation

Existing lint, Vue check, build, Pint, PHPUnit and browser smoke remain gates. `checkout-visual.mjs` compares live Lab and real Laravel checkout at all ten required viewports, measures geometry/alignment/overflow, captures screenshots and verifies server quote/confirmation/voucher states without financial submissions. CI uploads `checkout-lab-comparison`.

Comparable data/media exist only in `tests/Browser/fixture.php`, which rejects production. Reference PNGs come from the specified Lab source, not Oura. Local source-baseline comparison supplements the live check; it does not replace it. Failed/unavailable checks keep the PR unready.

## Deployment after approval

Merge only after required CI/review are resolved. Follow `deploy/README.md`: encrypted backup and restore verification, preflight, official deploy, health checks. Verify customer catalog, quote, confirmation, payment page and white admin theme editor. Confirm production media/channel availability. Payment acceptance uses sandbox unless the owner authorizes a live financial test. No VPS, Nginx or Cloudflare configuration is modified by this implementation. Roll back using the documented prior release procedure if needed; this change adds no schema rollback.
