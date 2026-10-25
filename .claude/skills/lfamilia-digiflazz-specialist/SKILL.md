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

## Precedence — read this first

**CURRENT SOURCE OF TRUTH: `docs/PRD.md` v2.1 (24 October 2026).**
Then `AGENTS.md` for work rules, `rebuild/PRODUCT.md` and
`rebuild/FULFILLMENT.md` for implementation, this skill last.
The owner's current instructions outrank all documents.
Archived rules, historical `CLAUDE.md` sections and old plans are
not product requirements.

- Never infer a backup SKU `_B`, automatic failover, price tolerance,
  an ML-specific markup or game-level markup from older instructions.
- Never alter production, send actual provider transactions, merge or
  deploy without explicit owner permission.
- Never put credentials or production customer data in tests or reports.
- Confirm external API protocol against current official documentation.

## When to activate

- Any Digiflazz task: API integration, catalog sync, seller/SKU
  management, supplier selection, pricing, nominal ordering, stock /
  cut-off / availability, fulfillment, reconciliation, supplier
  debugging.
- Reviewing or testing Digiflazz-related code, mappings, catalog sync,
  or fulfillment logic.
- Working the Digiflazz seller / SKU spreadsheet (Buyer Area → Produk).

## Current catalog, pricing, and fulfillment contract

Current decisions supersede historical 2026-10-12 through 2026-10-22
catalog rules. **Exactly one Digiflazz SKU is selected for each sellable
nominal.** Supplier selection inside Digiflazz does not create a backup
SKU at LFAMILIA.

- **Three prices:** seller reference -> store cost -> sell price.
- Store cost is initialized once during import with a configurable uplift
  (default **2%**), rounded up to rupiah. Sync only updates seller
  reference and supplier availability; store cost is preserved.
- Sell price uses store cost × nominal markup (default **10%**),
  rounded up. Markup is stored on NOMINAL, not game/product; fixed
  sell price is also supported per nominal. ML ×1.06 is obsolete.
- Digiflazz transaction **`max_price` = selected SKU store cost exactly**:
  no additional 1%, no maximum across backups. Keep it in order snapshot.
- A SKU with seller price above store cost is temporarily unavailable.
  Hide the nominal; make it available again when provider data recovers.
- Full Digiflazz sync interval defaults to **15 minutes**; failed sync
  responses cannot be interpreted as removed SKUs.
- Every import path, historical migration, attach action, admin activation
  and checkout must obey one SKU per nominal. Old extra mappings are
  historic data, not permission to substitute another SKU.
- Block unusable chosen SKU. Never automatically choose another SKU.
  Confirmed failure stops for human review; PENDING/UNKNOWN rechecks
  the same transaction with exactly the same `ref_id`.
- Keep customer input schema and provider destination template at the
  product/game level; each required field must be represented.

Use `references/digiflazz-rules.md` for current examples and tests.

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

## Cut-off, response codes, exact price cap

- Verify seller/buyer active, stock, cutoff, freshness, identity, and
  seller price <= store cost before authorizing checkout or fulfillment.
- Do not substitute another SKU on any blocked or uncertain attempt.
- `Sukses` with `rc=00` is success. `Pending` with `rc=03/99`
  remains pending. `rc=01/70` are timeout results whose transactions
  may already exist; reconcile rather than confirming failure.
- Contradictory status and response code are `UNKNOWN`.
- Digiflazz docs:
  https://developer.digiflazz.com/api/buyer/topup/
  https://developer.digiflazz.com/api/buyer/response-code/
  https://developer.digiflazz.com/api/buyer/cek-status/

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

| Current rule | Owner |
|---|---|
| SKU policy, three prices, max_price and sync | `docs/PRD.md` §4, §8, §19 |
| Coding, testing, staging and deployment | `AGENTS.md` |
| Store cost, sell price and checkout | `rebuild/PRODUCT.md`, `rebuild/CHECKOUT.md` |
| One-SKU fulfillment and reconciliation | `rebuild/FULFILLMENT.md` |
| Provider HTTP protocol | Official Digiflazz buyer documentation |
