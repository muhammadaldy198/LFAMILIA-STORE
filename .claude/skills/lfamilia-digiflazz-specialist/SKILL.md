---
name: lfamilia-digiflazz-specialist
description: "Digiflazz specialist for LFAMILIA STORE: Digiflazz API integration, catalog sync, seller/SKU management, supplier selection per project rules, modal/manual price protection, Harga Max and sell-price management, nominal ordering, stock/cut-off/availability, fulfillment and reconciliation, supplier-integration debugging. Enforces the owner's Digiflazz rules and pricing formulas exactly as written in AGENTS.md / CLAUDE.md / rebuild docs — never invents pricing formulas or SKU policy. Use when working on Digiflazz, catalog sync, seller/SKU, pricing, fulfillment, or supplier debugging for LFAMILIA STORE."
license: MIT
metadata:
  author: lfamilia
  source: LFAMILIA-STORE AGENTS.md, CLAUDE.md, rebuild/FULFILLMENT.md, rebuild/PRODUCT.md
---

# LFAMILIA Digiflazz Specialist

Digiflazz specialist for LFAMILIA STORE. This skill encodes the owner's
Digiflazz rules and pricing formulas exactly as written in the repo and
owner instructions. It does NOT invent pricing formulas, SKU policy, or
supplier rules.

## Precedence — read this first (hard rules, non-negotiable)

1. Owner instructions win over any skill. Order: explicit owner instruction
   > `CLAUDE.md` (invariants) > `AGENTS.md` (how to work) > any skill.
   If a skill contradicts an owner rule, the owner rule applies. Note the
   conflict in the task/PR report — never silently rewrite a skill.
2. **Do NOT invent pricing formulas or SKU policy.** Ground rules in
   current owner decisions, project instructions, and verified provider
   documentation. A newer explicit owner decision—especially an
   explicitly approved redesign—supersedes older catalog/pricing rules
   within that scope; update this skill's applicable rule rather than
   silently applying stale formulas. If a rule remains undecided, ask the
   owner rather than guessing.
3. **Do NOT touch production** without explicit owner instruction
   (`AGENTS.md` §3). Never use real customer data as fixtures,
   screenshots, or public issue content (`CLAUDE.md` §29).
4. **Payment tests ONLY simulation/sandbox** (`AGENTS.md` §3). Never send
   a real Digiflazz transaction without explicit authorization for that
   exact test.
5. Never commit secrets. Never put real secrets in Markdown, screenshots,
   fixtures, logs, PR comments, issues, or CI output (`AGENTS.md` §3,
   `CLAUDE.md` §5).

## When to activate

- Any Digiflazz task: API integration, catalog sync, seller/SKU
  management, supplier selection, pricing, nominal ordering, stock /
  cut-off / availability, fulfillment, reconciliation, supplier
  debugging.
- Reviewing or testing Digiflazz-related code, mappings, catalog sync,
  or fulfillment logic.
- Working the Digiflazz seller / SKU spreadsheet (Buyer Area → Produk).

## Catalog rules

Distinguish legacy catalog procedures from the owner's explicitly
approved catalog redesign. For redesign work, the newer decisions below
supersede older seller-selection, one-by-one import, multi-provider, and
pricing rules; do not mix the old and new models.

### Owner-approved catalog redesign decisions

- Initial scope: one sellable product/nominal has exactly one provider;
  do not design automatic multi-provider failover as part of this model.
- Provider import is a bulk review workflow: select many provider SKUs,
  map them to an existing or newly created game and nominal, allow
  editable suggestions derived from provider names, then save reviewed
  selections. Reviewed selections may become active and sellable on save.
- Customer input fields and destination format belong to the game/product
  and are shared by every nominal, never configured per SKU.
- Default markup is 10%, editable by a non-programmer in Admin. Allow
  overrides globally, per game, and per nominal; precedence is nominal,
  then game, then global.
- Provider-reported price and the store's editable cost basis are
  distinct. Import the provider price as reference; Admin may edit the
  store cost. Subsequent catalog sync refreshes the provider reference
  but must preserve the Admin-edited store cost and catalog settings.
- Provider availability is not a single boolean: keep seller product
  status, buyer SKU status, stock, scheduled cut-off, provider price,
  and Digiflazz `max_price` semantics distinct. Hide/unavailable status
  during a scheduled cut-off is temporary; do not model it as permanent
  deactivation. Owner has directed that provider-disabled SKUs and SKUs
  whose latest seller price exceeds the store cost be disabled/hidden;
  distinguish provider status from the Admin's editable store cost when
  implementing this rule.
- These are approved design constraints, not permission to edit code,
  push, merge, or deploy. For remaining material behavior decisions,
  research provider docs first and ask only the unresolved question.

### Owner-approved end-to-end redesign decisions (discussion checkpoint)

These decisions were explicitly confirmed by the owner for the broader catalog/admin-panel redesign; preserve them across sessions. They are design notes only, not authorization to edit code, delete VPS/catalog data, push, merge, or deploy. The site is still a development preview and has no buyers/orders; do not invent legacy customer-order retention requirements.

- Rebuild the catalog from reviewed provider imports; recreate existing manual products through the new manual-product workflow. Do not frame this as preserving old customer orders.
- Import UI: bulk-select many Digiflazz SKUs, group by suggested game, allow selecting individual rows and “select all” per game; infer editable game/nominal suggestions from provider product names; allow creating a game and its nominal(s) within the import flow; save reviewed selections together and allow immediate sale after save.
- Duplicate suggestions: if two SKUs appear to map to the same game+nominal, warn and require admin choice; never silently create duplicates.
- Store one provider/SKU for each sellable nominal in this initial redesign. No store-side multi-provider mapping/failover choice for the nominal. This does not mean Digiflazz has only one seller; Digiflazz's seller network is provider-side.
- Game/customer input fields and destination template are configured once at game/product level and reused by every nominal/SKU.
- Markup on store cost: initial default 10%, editable globally, per game, and per nominal, precedence nominal → game → global. Do not round the computed sell price.
- Initial store cost proposal: provider seller price plus a separately configurable cost uplift, default 2%, also editable globally, per game, and per nominal, same specificity precedence. Example seller 12,000 + 2% = store cost / Digiflazz `max_price` 12,240; sell price at 10% = 13,464.
- Admin may edit store cost. Store cost is distinct from the latest Digiflazz seller price; successful sync refreshes seller reference/status but does not overwrite the admin-saved store cost. Recalculate sell price from store cost and effective markup when store cost changes.
- Send the store cost exactly as Digiflazz transaction `max_price`. If latest seller price exceeds store cost, the SKU is temporarily unavailable/hidden and becomes available again when price returns within the cap.
- Refresh Digiflazz full pricelist every 5 minutes. Preserve last successful catalog/status if sync fails; never infer missing/inactive from a failed request.
- On a successful complete sync, a previously known SKU absent from the response is disabled/hidden. If it later reappears, reactivate automatically unless admin manually disabled it. Seller/SKU inactive, stock unavailable, and active cut-off are temporary unavailability and recover automatically when provider state recovers; keep these distinct from manual admin disablement.
- Manual products: create the game/product and multiple nominal rows in one form; enter cost per nominal; use the same global/game/nominal markup rules; manual fulfillment remains with admin/CS.
- Mobile admin UX: minimize vertical scrolling; use long horizontal rows with sideways scrolling rather than stacked cards for nominal and import SKU lists. Edit opens a dedicated nominal editor with a return-to-list action. Per-game grouping and individual/bulk selection remain available.
- Owner approved this summarized design for continued specification work. It is not yet a fully reviewed implementation spec and is not code-change authorization by itself.

### Legacy catalog operations

The rules in `AGENTS.md` §3 and `references/digiflazz-rules.md` (seller
rating threshold, catalog Harga Max formula, one-by-one Buyer Area
updates, and existing multi-source fulfillment) describe the legacy
workflow. Apply them only when the task explicitly concerns that
workflow; do not carry them into the approved redesign without checking
scope.

### Where the precedence rule actually lives (check before "unused")

The nominal → game → global rule is real and active, but it is implemented inline in
several places rather than through one helper:

- store-cost uplift: `DigiflazzCatalogImport::initialStoreCost()` — `package.cost_uplift_percent ?? product.cost_uplift_percent ?? global`
- sell markup at checkout: `CheckoutPricing` — `package.sell_markup_percent ?? product.sell_markup_percent ?? global`
- admin preview: `Catalog.vue` `effectiveUpliftPercent` / `effectiveMarkupPercent`
- `CatalogPricingSettings::costUpliftFor` / `sellMarkupFor` — a fourth copy with no callers

So an uncalled pricing helper is **not** evidence the feature was never built. Before reporting
any pricing method as dead, grep the live paths for the *rule* (the `??` precedence chain and the
`intdiv(... * 1000000 + 999999, 1000000)` rounding), not the symbol name. Uncalled + rule
duplicated elsewhere = consolidation decision for the owner, not cleanup.

The same holds for percentage rounding: `CatalogPricingSettings::applyPercent` / `percentUnits`
are exercised only by tests, while `DigiflazzCatalogImport` and `CheckoutPricing::margin()` each
re-implement the same arithmetic. Changing rounding rules means changing all three sites at once —
treat that as a pricing change requiring tests at each boundary, never a refactor.

## Pricing (do not invent)

For the owner-approved catalog redesign, use the 10% editable global
markup and the nominal → game → global override precedence specified
above. The legacy pricing rules below apply only when the task explicitly
concerns the legacy workflow; do not let them override a newer redesign
decision.

Legacy source: `AGENTS.md` §3 (Pricing).

- **Legacy cost (`cost_idr`) = catalog Harga Max Digiflazz** (fixed, not
  fluctuating seller price).
- **Legacy ML sell price = cost × 1.06, not rounded.** Other legacy
  games' formulas remain governed by their explicit decisions.
- Do not change prices or fees without an applicable owner instruction.

## Provider contract (verified against developer.digiflazz.com)

Quote these from the docs, never from memory — they decide behaviour, not style.

| Hal | Nilai |
|---|---|
| Endpoint transaksi | `POST https://api.digiflazz.com/v1/transaction` |
| Parameter wajib | `username`, `buyer_sku_code`, `customer_no`, `ref_id`, `sign` |
| Signature transaksi | `md5(username + apiKey + ref_id)` |
| Sifat respons | **sinkron** — sukses / gagal / pending dalam satu response |
| Cek status prabayar | kirim ulang request yang sama dengan **`ref_id` yang sama**; tidak ada endpoint cek-status terpisah |
| Batas cek ulang | jangan lebih sering dari **1 menit** (race condition / duplikasi) |
| Batas mutlak | **jangan pernah cek transaksi lebih dari 90 hari** — Digiflazz membuat transaksi BARU |
| Daftar harga | `cmd=prepaid`, `sign = md5(username + apiKey + "pricelist")` |
| Data pricelist | telat 10–15 menit, ada limit pemanggilan → simpan di database sendiri |
| Webhook | `POST` JSON, `X-Hub-Signature: sha1=HMAC-SHA1(body, secret)`, `X-Digiflazz-Event` = `create` / `update` |
| Payload webhook | `ref_id`, `customer_no`, `buyer_sku_code`, `status`, `rc`, `sn`, `price`, `buyer_last_saldo` |

Consequences to design around:

- The 90-day rule means a reconciliation sweep that re-sends old `ref_id`s can **buy the product a
  second time**. Any retry loop must be bounded by order age, not only by attempt count.
- Because the response is synchronous, a timeout is a transport problem, not a provider verdict —
  keep the attempt `UNKNOWN` and re-check with the same `ref_id`.
- Webhook is an accelerator, never the only source: verified webhook + scheduled re-check for
  anything still uncertain.

## Cut-off / availability

Source: `AGENTS.md` §3 (via parent task brief, grounded in the
checkout cut-off design) + `rebuild/FULFILLMENT.md` (unsent mapping
skip).

- **Checkout cut-off: a SKU whose modal (cost) exceeds Harga Max, or
  whose seller is offline, is hidden ("Tidak tersedia").** This is
  intended behaviour, not a bug. Do not "fix" it by showing the SKU.
- Before an external request, a mapping may be declared unusable and
  skipped (no provider risk yet) when, for example: mapping/provider
  inactive; adapter unsupported; external SKU empty; Digiflazz catalog
  item inactive, out of stock, or cut-off; voucher-stock key/stock
  unavailable; provider cost invalid; cost exceeds the order's
  `max_price` snapshot. (`rebuild/FULFILLMENT.md`, "Unsent mapping skip".)

## Two different "Harga Max" — do NOT confuse them

There are TWO different limits, with different formulas and different
purposes. Never mix them up.

1. **Catalog / Buyer Area Harga Max** = seller price × 1.02, rounded to
   the nearest rupiah (`AGENTS.md` §3). Used for catalog / Buyer Area /
   sync work. Example: seller 10.000 → 10.200.
2. **Order snapshot `max_price` (transaction limit)** = highest modal
   of active + available Digiflazz SKUs in the SAME nominal + 1%,
   rounded UP (`rebuild/FULFILLMENT.md`, "Digiflazz max_price
   snapshot"). Stored as an order snapshot and sent as `max_price` on
   every Digiflazz attempt and reconciliation. This covers the most
   expensive backup SKU. If only one eligible SKU exists, use that
   SKU's price + 1%. Inactive / unavailable SKUs do not enlarge the
   limit. Example: highest modal 10.000 → snapshot 10.100; highest
   modal 10.047 → snapshot 10.148.
   - Sell price is still computed from the primary SKU and is NOT
     auto-raised when failover uses a more expensive seller; loss from
     discount/promo is an accepted business decision.
   - A SKU whose modal exceeds the snapshot limit must NOT be selected,
     e.g. after a price rise beyond tolerance.
   - Do NOT confuse `provider_mappings.max_price_idr` (administrative
     per-source limit at sync) with
     `order.snapshot.provider.max_price_idr` (transaction limit from
     all candidates at checkout).

## Fulfillment safety (must never be broken)

Source: `CLAUDE.md` §11; `rebuild/FULFILLMENT.md`.

- For the owner-approved catalog redesign, each sellable Digiflazz nominal has exactly one Digiflazz mapping/SKU; no store-side secondary SKU, priority ladder, or automatic switch after failure. Digiflazz may use its own seller network behind that SKU. Preserve legacy multi-mapping behavior only when the task explicitly targets an untouched legacy workflow; never let old admin/import tools silently reintroduce backups.
- When enforcing one-SKU-per-nominal, trace every creation path—not only the visible editor: bulk import grouping, attach-source endpoints, auto-source/bootstrap actions, catalog-sync hooks, and scheduled jobs. Test that duplicate suggestions are rejected and that routine sync cannot recreate an alternate mapping; UI-only removal leaves backend paths active.
- Provider mapping and internal SKU are never a customer choice.
- **Attempt states** include `CREATED`, `SENDING`, `PENDING`,
  `UNKNOWN`, `SUCCESS`, `FAILED_CONFIRMED`, plus relevant manual /
  blocked states.
- **`PENDING`, `SENDING`, or `UNKNOWN` must NOT trigger an immediate
  next-source fulfillment.** Timeout / network ambiguity is NOT proof
  the provider did not process the request.
- **Reconcile using the SAME stored attempt/reference.** Verify the
  provider result against stored `ref_id`, `buyer_sku_code`, and
  `customer_no`. Provider success → `SUCCESS`; definitive failure →
  `FAILED_CONFIRMED`; still pending → `PENDING`; unconfirmable →
  `UNKNOWN`.
- **Follow the currently approved owner/PRD failover policy; do not infer policy from generic safety rules.** When policy disables automatic SKU switching, a definitively failed attempt becomes blocked/manual-review and no second mapping is sent. If failover is approved, permit it only after the previous path is definitively safe (for example, `FAILED_CONFIRMED` with the required `safe_to_failover` condition) or a candidate is skipped before any external request; exclude attempted mapping IDs so the same source is never sent twice.
- **Primary objective: NO DOUBLE FULFILLMENT.** Do not retry the same
  attempted mapping blindly. Protections include: existing attempt
  checked before start; attempt/order locked in a transaction; unique
  external reference; latest attempt / terminal state checked;
  reconciliation creates no new reference; attempted mappings are not
  re-selected; success / confirmed-failure is not overwritten by an old
  callback/result.
- **Do NOT rewrite the order snapshot cost/total** merely because a
  later mapping is selected.
- **This is NOT universal failover for every provider.** Automatic
  adapters found in audit are Digiflazz and internal voucher-stock;
  manual fulfillment has its own Admin flow.
- **Scheduler/queue:** the scheduler enqueues new attempts and
  reconciliation for stale/uncertain attempts per the implemented
  command/job. Production queue worker + scheduler run as systemd
  services and are verified in the deployment healthcheck.

## Provider / external-request rules

Source: `CLAUDE.md` §12.

All external requests must account for: timeout, retry semantics,
idempotency / reference identity, signatures, response verification,
ambiguous network outcomes, safe logging, reconciliation. A network
timeout does NOT prove the external provider failed to process a
request. Never create a second financial/fulfillment action merely
because the first request timed out.

## Order safety

Source: `CLAUDE.md` §9.

- Server-side price calculation. Frontend totals/prices are NOT
  authoritative.
- Do NOT trust customer-supplied provider mapping, external SKU,
  `buyer_sku_code`, payment route, cost, or total.
- Do NOT mutate an old order snapshot because current catalog/provider
  pricing changed later. Order financial / catalog / provider snapshot
  is immutable after order creation.

## Nominal ordering

`PRODUCT.md`: product / category / nominal, ordering, media, provider
mappings, Digiflazz catalog/sync, and pricing are managed through the
matching Admin workspace. **Follow the existing Admin ordering.**
This skill does NOT define a sort formula — if no ordering rule is
found in the code/Admin workspace for a given case, say "tanya
pemilik" / "tidak ditentukan" instead of inventing one.

## Working method

Follow `AGENTS.md` §2: READ → VERIFY → PLAN → CHANGE → TEST → REVIEW DIFF. Treat PR/CI/merge/deploy as separate steps requiring the user's explicit request. After approval, continue without asking for the same permission again. Keep progress narration off; deliver one concise final report unless a real decision/blocker requires input. Before testing, inspect the actual runner and effective test DB configuration; confirm migrations and whether baseline rows invalidate empty-table assumptions. Run a failing test alone and inspect its fixtures before attributing it to code or environment; then verify on a genuinely isolated DB if the configured DB is contaminated. Never use production, `migrate:fresh`, deleted migration records, or manual schema-ledger edits to force a test pass. Never ASSUME → EDIT → CLAIM DONE.

For pricing changes, distinguish initialization/import cost uplift from checkout markup: never apply an import-only uplift while quoting, because checkout runs repeatedly and would compound the cost. Test the actual owning boundary (import/init for one-time uplift; checkout for sell markup and immutable order snapshots). When preserving legacy pricing, treat a global setting as active only if its value parses and passes the approved validation; a row's mere existence must not silently switch legacy records to redesigned pricing.

For catalog pricing schema changes, first check the dedicated test database's `migrate:status` before editing migration files. If a migration has already run anywhere shared, never change it; add a new timestamped forward migration and test it against that database. Use the repository's explicitly configured dedicated test database for tests and migration checks; first verify its name and migration status, then check whether it already contains baseline/seed rows that invalidate empty-catalog assumptions. Never point tests at production, run `migrate:fresh`, delete migration records, or manually patch schema/ledger to make a test pass. Keep schema and migration history aligned before adding downstream tests that depend on columns. If a focused test fails, rerun it alone and inspect its fixture assumptions before attributing the failure to code or shared test state.

When splitting provider seller reference from editable store cost, model both explicitly and update the entire data path: import initializes store cost once from seller reference plus effective uplift; full and per-SKU sync refresh only seller reference for redesigned records; checkout eligibility, ordering, quote, and transaction max_price use store cost; admin edits persist store cost; legacy records without the redesign marker retain existing behavior. Add tests for each boundary and repeat import/sync to prove uplift does not compound.

For catalog/provider redesign work, inspect current code, relevant tests, and the approved design rules before editing; verify provider-specific claims against official provider documentation when they affect behavior. Ask only about a genuinely unresolved business decision. When the owner has already approved the design and implementation, proceed without repeated questions or progress reports. Separate temporary seller/stock/cut-off availability from SKU activation and from editable store cost; provider fields with different meanings or lifetimes must not be collapsed into one flag or price.

For catalog/customer-number mapping tasks, trace the whole path before proposing a fix: product/game definition → input-field schema → package/SKU mappings → admin create/edit/import forms → fulfillment target builder → order snapshot and stored attempts. Confirm which level owns each setting from migrations, controller validation/persistence, UI payloads, and runtime reads. Treat the owner's input model as two cases: one customer-input field or two; keep field definitions and the shared customer-number rule at product/game level, then apply it to every package/SKU for that game. Never ask the owner to configure identical SKU rows individually. Distinguish the game's one/two input fields from the single `customer_no` Digiflazz accepts; verify the serialization (including order and separator) from project tests or provider evidence rather than assuming every two-field game concatenates the same way.

Keep implementation and verification batched; avoid progress messages unless a real decision/blocker needs input. Report once, concisely: changes, verified results, remaining gaps. Keep repo work separate from production: no push, merge, or deployment unless explicitly requested. For approved corrections, make the narrow verified change instead of restarting discussion about unrelated risks. If the owner explicitly rejects a particular IDE or editor, do not launch, invoke, or delegate work to it; use the repository's approved local command-line workflow instead.

Before implementing a broad redesign, inspect the local worktree and every open PR that overlaps the affected behavior. If an existing PR may be superseded, explain that closing it does not erase its branch/code and ask whether to close or finish it before creating overlapping work. Once the owner explicitly chooses closure, close it with a short supersession explanation, verify the remote state, and continue on an isolated branch/worktree; do not stop again to ask about unrelated preserved feature work unless the implementation materially depends on that choice. Separate implementation approval from push, merge, VPS reset, and deployment authorization.

## What this skill NEVER does

- Never invents a pricing formula, margin, SKU policy, or supplier
  rule.
- Never changes manual prices, catalog data, DB data, or order data
  during deploy (`AGENTS.md` §6).
- Never deletes catalog / DB / order data.
- Never sends a real Digiflazz transaction without explicit
  authorization for that exact test.
- Never treats a timeout / ambiguous result as proof the provider
  failed.
- Never creates a second fulfillment action because the first request
  timed out.
- Never shows a cut-off SKU ("Tidak tersedia") as available.
- Never uses real customer data as fixtures, screenshots, or public
  issue content.

## Source map

| Rule | Source |
|---|---|
| Seller ≥ 4.8, Harga Max = seller × 1.02 (nearest rupiah), save one-by-one, Buyer Area → Produk, single session, all ON, no seller ≥ 4.8 → delete | `AGENTS.md` §3 |
| Cost = Harga Max (fixed); ML sell = cost × 1.06 NOT rounded; other games not decided | `AGENTS.md` §3 |
| No production touch, simulation-only tests, no secrets in output | `AGENTS.md` §3 |
| Deploy rules (no manual price change, no data deletion) | `AGENTS.md` §6 |
| Working method, bugs audited before fix | `AGENTS.md` §2 |
| Fulfillment safety, candidate order, NO DOUBLE FULFILLMENT, failover | `CLAUDE.md` §11 |
| Provider / external-request rules | `CLAUDE.md` §12 |
| Order safety, server-side price, immutable snapshot | `CLAUDE.md` §9 |
| max_price snapshot (+1%, rounded UP), unsent skip, attempt states, reconciliation, double-fulfillment prevention | `rebuild/FULFILLMENT.md` |
| Nominal ordering via Admin workspace | `rebuild/PRODUCT.md` |
| Cut-off: SKU modal > Harga Max or seller offline → hidden ("Tidak tersedia") | Owner instruction (checkout cut-off design) |
| Transaction endpoint, `md5(username+apiKey+ref_id)`, synchronous response, same-`ref_id` status check, 1-minute floor, 90-day danger, pricelist signature, webhook HMAC | developer.digiflazz.com (buyer docs) |
