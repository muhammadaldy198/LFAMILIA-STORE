# Catalog and Admin Catalog Redesign — Historical Plan

> **Status:** Implementation was committed locally in `9c9ce4e6` on `feat/admin-total-redesign`. This file is a planning record, not a current completion report. Checkboxes remain historical and are not a verified completion ledger; use the commit, current code, and test results for status. No push, PR, merge, or deployment is authorized by this document.

> **For agentic workers:** Implement task-by-task using the executing-plans or subagent-driven-development skill. Checkboxes are tracked only after implementation is separately authorized.

**Goal:** Replace the current fragmented catalog/import/pricing workflow with an admin-friendly bulk-import and manual-product workflow, backed by explicit Digiflazz availability and price rules.

**Architecture:** Keep Laravel as the authority for product eligibility, pricing, imports, and checkout. Separate provider-reported seller price/status from the store's editable cost, and calculate customer sale price from the effective markup (nominal → game → global). Keep provider sync, admin activation, and temporary provider availability as distinct state sources; simplify the initial sellable model to one selected provider SKU per nominal, with no store-side automatic provider failover.

**Tech Stack:** Laravel 12 / PHP 8.4, MySQL 8, Vue 3 + Inertia v2, existing Digiflazz API integration, PHPUnit/Pest conventions and browser smoke tests already present in the repository.

**Spec:** Approved discussion decisions in `lfamilia-digiflazz-specialist` Hermes skill; catalog and admin redesign scope. This plan is a proposed implementation sequence, not authorization to modify code, VPS data, push, merge, or deploy.

## Global Constraints

- Site is still a development preview; owner confirms no buyers or orders exist.
- Bulk imports are reviewed, editable, grouped by suggested game, selectable per SKU and by game, and may publish immediately after save.
- Duplicate game/nominal suggestions must warn and require a choice; do not silently duplicate.
- Customer input fields and destination template are configured once per game/product.
- **Current owner-approved pricing rule:** default cost uplift is 2%; default sell markup is 10%. Both are configurable globally, per game, and per nominal; nominal overrides game, game overrides global. This rule applies to every game and supersedes the archived ML-only 6% rule. Checkout and previews must use the same effective store cost and markup; sale markup result is not rounded.
- Admin-edited store cost must survive successful sync. Sync refreshes provider reference price and provider state only.
- Transaction `max_price` equals store cost. If current seller price exceeds that cost, hide the SKU; restore availability when provider conditions recover.
- Full price-list sync runs every 5 minutes. Failed/invalid sync preserves the last successful data. A SKU absent from a successful complete sync is disabled; if it later returns, restore it automatically unless admin manually disabled it.
- Seller/SKU inactive, stock exhausted, cutoff, and price above max are temporary provider unavailability, separate from manual admin disablement.
- Manual products can be created with multiple nominal rows in one form and continue to be fulfilled by Admin/CS.
- Mobile lists use long horizontally scrollable rows rather than stacked cards; edit opens a dedicated nominal editor and offers return to the list.
- No real provider transactions; test through fixtures/simulation only. Do not touch VPS or deploy as part of implementation.
- Do not change payment/refund behavior or other Admin modules outside this catalog scope.

## Review Focus

- A failed or partial price-list response must never mark every SKU absent; test malformed, empty, and truncated responses.
- A legitimate seller/status/price change must not overwrite store cost or admin disablement; test each separately.
- A provider cutoff or temporary stock/status issue must hide temporarily and recover, while manual disable stays disabled.
- Duplicate SKU/game/nominal submissions and concurrent sync/import must not create duplicate packages or mappings.
- A changed modal/markup setting must update current catalog quotes but must not rewrite an already-created order snapshot.

---

### Task 1: Inventory current catalog behavior and lock migration boundaries

**Files:** Read `rebuild/ADMIN.md`, `PRODUCT.md`, `CHECKOUT.md`, `FULFILLMENT.md`; controllers/services/models, migrations, scheduler definitions, routes, and relevant `CatalogTest`, `CheckoutTest`, `AdminDigiflazzRestorationTest`, `AdminPanelRestorationTest`, `AdminPackagePriceGuardTest`, and browser tests. No implementation edits in this task.

- [ ] Trace every write/read path for package, mapping, product cost, markup, customer fields, sync absence, activation, checkout pricing, and fulfillment `max_price`.
- [ ] Document columns/state that can be reused, which existing branches become obsolete, and all destructive catalog rebuild steps.
- [ ] Define a reversible schema/data strategy for the zero-order development database, with a preflight backup and explicit local confirmation before any catalog-reset execution.
- [ ] Verify Digiflazz full-catalog response completeness semantics and exact scheduled-sync mechanism from the current code and official docs; never mark missing from per-SKU sync.
- [ ] Deliverable: reviewed behavior map and migration boundary; no data or source changes.

### Task 2: Store pricing rules and single-provider-per-nominal model

**Files:** migrations under `rebuild/database/migrations`; `Product`, `ProductPackage`, `ProviderMapping`; `CheckoutPricing`, `AdminPricingPreviewService`, `AdminCatalogController`; catalog/pricing/checkout tests.

- [ ] Write failing tests for global/game/nominal cost-uplift and sale-markup precedence, unrounded prices, and recomputation when admin edits cost.
- [ ] Write failing tests proving provider sync refreshes provider price but preserves editable store cost and manual activation state.
- [ ] Model separate provider reference price, store cost, computed/effective pricing fields, admin active state, sync-disabled state, and temporary eligibility without conflating them.
- [ ] Enforce one selected provider SKU per sellable nominal in the new Admin workflow; no automatic fallback across mappings.
- [ ] Ensure checkout calculates authoritatively from store cost and effective markup, sends exact store cost as Digiflazz `max_price`, and snapshots resulting price/cost at order creation.
- [ ] Run targeted pricing/catalog/checkout tests, then the full PHP suite and Pint before any commit.

### Task 3: Digiflazz sync, availability, and import-review backend

**Files:** `DigiflazzCatalogService`, `DigiflazzCatalogImport`, `AdminDigiflazzController`, scheduler/command/job definitions, supporting services, migrations, and `AdminDigiflazzRestorationTest` / `AdminPanelRestorationTest` / `CatalogTest`.

- [ ] Add tests for the five-minute full-list interval, partial/manual SKU refresh, successful complete-list absence, failed sync preservation, and automatic reappearance only for sync-disabled SKUs.
- [ ] Add transition tests for buyer status, seller status, finite stock, time-window cutoff (including midnight crossing), seller price above store cost, and manual admin disablement.
- [ ] Make complete-sync processing atomic and guarded against empty, malformed, or suspiciously truncated responses; only a validated successful full list can mark absent SKUs.
- [ ] Build trusted bulk-import validation and persistence for many selected SKU rows, new/existing game assignment, new/existing nominal assignment, editable suggestions, duplicate game+nominal warning/choice, default cost uplift, and immediate publish option.
- [ ] Preserve integration secrets and never include credential material in responses/logs.
- [ ] Test with simulated API fixtures only; no live price-list or transaction call as part of tests.

### Task 4: Bulk import and catalog admin UI

**Files:** `resources/js/Pages/Admin/Catalog.vue`, `resources/js/Pages/Admin/Digiflazz.vue`, focused reusable components, browser fixture/smoke tests.

- [x] Design mobile-first, horizontally scrollable long rows for provider SKU results and nominal list; keep row labels/actions accessible without stacked cards.
- [ ] Group candidate SKUs by editable suggested game; add per-row selection and per-game select-all, with a clear total selection count.
- [ ] Provide inline/admin-friendly create-game and nominal flows inside import review; expose seller price, editable store cost, both markup previews, effective price, provider availability, and why-unavailable state.
- [x] Add duplicate suggestion warning and require an explicit choice before save; allow reviewed rows to publish immediately.
- [ ] Exercise narrow mobile widths and desktop in browser tests; verify bulk selection survives pagination/filtering and validation errors preserve the reviewed draft.

### Task 5: Manual product bulk editor and customer-field ownership

**Files:** `AdminCatalogController`, `Catalog.vue`, `AdminCustomerFields.vue` if needed, `Product` / `ProductPackage` validation, catalog/browser tests.

- [x] Add one-form creation for a manual game/product and multiple nominal rows, each with editable store cost and effective markup preview.
- [x] Keep customer input fields defined once on the game/product and reused for every nominal.
- [x] Keep manual fulfillment workflow unchanged; test that product/nominal creation does not create provider mappings.
- [ ] Provide dedicated nominal editor with a return-to-list control and test keyboard/touch accessibility and draft state.

### Task 6: Development catalog rebuild, documentation, and final verification

**Files:** catalog rebuild command or explicitly scoped migration/import utility; `rebuild/ADMIN.md`, `PRODUCT.md`, `CHECKOUT.md`, `FULFILLMENT.md`; full PHP/frontend/browser suites.

- [ ] Dry-run catalog rebuild against a disposable local test database; print aggregate counts and conflicts before any write.
- [ ] Require explicit owner approval before resetting any VPS/catalog data; do not add destructive production execution to deployment hooks.
- [ ] Recreate automated catalog from reviewed import and manual catalog through the new editor; verify count/eligibility invariants with aggregate-only checks.
- [x] Update docs to describe the new owner-approved pricing, sync, availability, one-provider-per-nominal, import, and mobile workflows; retire contradictory legacy Admin instructions for this scope.
- [x] Run full CI-equivalent gates: full PHPUnit, whole-repo Pint, `npm run lint`, `npm run check`, `npm run build`, PHP syntax checks, browser smoke, and desktop/mobile screenshots required by repo rules. (Semua hijau di lokal kecuali `tests/Browser/checkout-visual.mjs`, yang butuh keluar internet ke lab Vercel — Chromium di sandbox ini diblokir proxy egress; tetap gate CI.)
- [ ] Stop at a reviewed PR with green CI. Merge and deployment require separate explicit owner instructions.

## Sequencing and blockers

- Tasks 1–3 define correctness before UI work; Tasks 4–5 can then proceed in small focused PRs; Task 6 closes with the separately authorized development-catalog rebuild.
- PR #288 was closed by owner instruction as superseded by this redesign. Its customer input/template behavior is part of this redesign: configure once per game/product and reuse for all nominal SKUs. Preserve applicable changes in this work; do not reopen or merge PR #288.
- The approved phrase “one product/nominal = one provider” is interpreted as one selected provider mapping in LFAMILIA. Digiflazz's internal seller routing remains provider-side. Exact use of `seller_name` and how a seller change affects imported SKU identity must be confirmed from the official API contract/current implementation during Task 1, not guessed.
- This plan does not authorize writing implementation code, resetting VPS catalog data, pushing, merging, or deploying.
