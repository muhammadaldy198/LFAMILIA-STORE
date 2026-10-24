---
name: lfamilia-payment-guardian
description: "Guard payment work for LFAMILIA STORE (Laravel 12 + Midtrans): verify webhook/callback security, idempotency, UNKNOWN reconciliation, invoice/amount/status/wallet checks, payment-bug detection, and sandbox-only testing. Enforces the store's refund rule (manual via CS only, never automatic refund) and the rule that owner instructions (AGENTS.md, CLAUDE.md, explicit owner order) win over any skill, including integrate-midtrans-payments. Use when working on payment, checkout, wallet, webhook, callback, invoice, reconciliation, or any Midtrans task for LFAMILIA STORE."
license: MIT
metadata:
  author: lfamilia
  source: LFAMILIA-STORE AGENTS.md, CLAUDE.md, rebuild/PAYMENT.md
---

# LFAMILIA Payment Guardian

Guard payment work for LFAMILIA STORE. This skill adds store-specific
guardrails on top of the generic Midtrans skill. It does NOT replace
`integrate-midtrans-payments` for Midtrans product detail — it references
that skill and adds the rules that must never be violated for this store.

## Precedence — read this first (hard rules, non-negotiable)

1. Owner instructions win over any skill. Order: explicit owner instruction
   > `CLAUDE.md` (invariants) > `AGENTS.md` (how to work) > any skill.
   If a skill contradicts an owner rule, the owner rule applies. Note the
   conflict in the task/PR report — never silently rewrite a skill.
2. **REFUND = manual via CS, never automatic** (`AGENTS.md` §3,
   DE-2026-10-17-05). The store DOES serve refunds, but only manually through
   customer support, never automatically in the system. Refund is governed by
   the Kebijakan Pengembalian Dana (5 eligible conditions; request within ≤7
   calendar days via Hubungi Kami; CS review 1–7 business days; funds 1–14
   business days). Never build or trigger an AUTOMATIC refund path. Failed /
   expired payments are handled via retry, not refund. Problem paid orders:
   manual settlement via Super Admin wallet adjustment (members) or direct
   contact (guests). Never initiate automatic gateway refunds.
   - **The wallet adjustment is not a "refund" feature.** Name it penyesuaian
     saldo, gate it to SUPER_ADMIN only (no amount cap — the guards are the
     role, a mandatory reason, the ledger row and the audit entry), refuse a
     negative balance, and never let it decide eligibility. Guests have no
     wallet, so a guest settlement is a transfer made outside the system and
     the panel only records that it happened.
   - **The 7-day filing window has one exception: proven system fault** (delivery
     never ran, payment notification lost, order stuck on an internal error),
     and it must point at evidence in the order history. Provider slowness is
     not that exception — automatic re-checks stop after three attempts two
     minutes apart, so a provider cannot "confirm failure days later" through
     the normal path. Publisher withdrawals and blocked game accounts stay
     excluded.
3. The automatic-refund content of `integrate-midtrans-payments` is
   **NOT used** for this store (`AGENTS.md` §1, `CLAUDE.md` §41.1). Use only
   that skill's Snap / webhook / verification / status parts. Never implement
   its automated refund operations (`refund-operations.md`, `refund_key` /
   `partnerRefundNo` flows) into code for this store — refunds are manual via
   CS, so no automatic refund code should exist.
4. **Payment tests ONLY simulation/sandbox** (`AGENTS.md` §3). No real
   production payment transactions without explicit owner approval.
5. **Do NOT touch production** without explicit owner instruction
   (`AGENTS.md` §3). Never use real customer data as fixtures, screenshots,
   or public issue content (`CLAUDE.md` §29).
6. Never commit secrets. Never put real secrets in Markdown, screenshots,
   fixtures, logs, PR comments, issues, or CI output (`AGENTS.md` §3,
   `CLAUDE.md` §5).

## When to activate

- Any payment, checkout, wallet, webhook, callback, invoice, or
  reconciliation task for LFAMILIA STORE.
- Any Midtrans task (Snap, Core API, BI-SNAP, QRIS, VA, GoPay) for this
  store — load this skill AND `integrate-midtrans-payments`.
- Reviewing or testing payment code, payment state transitions, or
  payment-related migrations.
- Debugging a payment bug, a stuck order, a duplicate charge, or an
  UNKNOWN transaction.

## Payment invariants (must never be broken)

Source: `CLAUDE.md` §7, §8, §10, §32; `rebuild/PAYMENT.md`.

- **Idempotency.** Every order and wallet top-up uses a payment idempotency
  key + request fingerprint. A key for a different request is rejected.
  For one order/top-up, the service looks for an existing non-rejected
  payment so double-create cannot happen. Ledger credit/debit is idempotent
  where the flow requires it.
- **Single-send claim.** Before creating an external payment, claim
  single-send. Never create a second financial/fulfillment action merely
  because the first request timed out.
- **Internal creation states.** Lifecycle includes `CREATING` and `SENDING`.
  Public responses hide internal detail that is not needed.
- **UNKNOWN for ambiguity.** A timeout / network failure / ambiguous
  external result stays `UNKNOWN` — never convert it to "failed" merely
  to make recovery easier. Reconcile the existing transaction first.
- **Callback verification before state transition.** Webhook handlers
  verify the implemented signature/challenge before calling state
  transition. Callback is recorded with event/payload hash and unique
  provider event identity for idempotency / replay protection.
- **Deduplication + replay protection.** Callbacks/events are deduplicated.
  A late `pending` or `cancelled` callback must never overwrite a paid /
  fulfilled / refunded state.
- **Terminal-state + stale-callback protection.** `REFUNDED` is never
  reopened. `PAID` is never downgraded except through the refund flow
  (which is rejected for this store — see Precedence). An already
  FAILED / CANCELLED / EXPIRED payment is never returned to PENDING by an
  old callback. Late PAID after an order is terminal does not
  automatically reprocess the order; it goes to a review path.
- **Amount / reference consistency.** The callback amount must match the
  local amount. Verify provider results against stored `ref_id`,
  `buyer_sku_code`, and `customer_no` where applicable.
- **Browser redirect is never proof of payment.** Always verify by
  backend status lookup, never by trusting frontend callback data.
- **Concurrency.** Concurrent callbacks/requests must remain safe. Use DB
  transactions, row locks (`lockForUpdate`), and conditional updates as
  concurrency controls. Never replace them with naive read-then-write.
- **Race condition test coverage (M11).** Both financial wallet deductions
  (`WalletConcurrencyM11Test`) and voucher quota redemptions
  (`VoucherConcurrencyM11Test`) must be verified with forked process tests
  (`pcntl_fork`) and file barriers (`start`, `ready`, `result`). Two
  simultaneous requests competing for the last remaining unit (balance or
  voucher quota) must result in exactly one success (code 0) and one
  rejection (code 2), preserving invariants without overdraw or over-quota.
- **Payment retry must not create a double charge.**
- **Routing.** Customer chooses a public payment channel/method. Backend
  chooses the eligible gateway/route: channel active, route active,
  gateway active, gateway not in maintenance, supports the requested
  purpose, required credential readiness. Candidates ordered by smaller
  `payment_routes.priority` first, then ID. Pre-request route selection is
  NOT post-request failover. Once an external request is claimed/sent and
  the result is ambiguous, do NOT blindly create a payment with a
  different gateway — reconcile the existing transaction first.
- **Wallet.** Wallet/saldo is a financial ledger. Preserve MySQL
  transactions, `lockForUpdate`, ledger records, idempotent credit/debit,
  non-negative balance invariant, auditable before/after balances and
  references. Never use frontend balance as source of truth. Guest
  customers do not use wallet. A refund top-up that cannot be directly
  reversed because the balance was already spent goes to `REFUND_REVIEW`,
  never forces a negative balance. (Note: `REFUND_REVIEW` is a manual
  review state for an already-paid top-up — it is NOT an automatic
  gateway refund.)
- **Errors.** Do not hide errors. Never turn an exception path into a
  success response. Preserve explicit UNKNOWN / pending / review states
  where they protect transaction safety.
- **Fees.** Do not change prices or fees without an instruction. See
  `rebuild/PAYMENT.md` "Admin fees" for the current rules: public methods
  have merchant-editable base fee (`fee_flat_idr`, `fee_percent_bps`);
  a route may override one or both; `NULL` = inherit channel base fee;
  explicit `0` = no fee for that component. Where contractually
  permitted fees are passed to the buyer, the fee calculation grosses up
  percentage-based gateway fees so the net received after fee is at least
  the requested amount: `total = ceil((amount + flat) / (1 - rate))`. The
  public quote, stored order/topup total, and Midtrans payment amount
  must agree. LFAMILIA Cash payments and manual QRIS have exactly zero
  customer admin fee, even if a legacy DB record contains nonzero values.
  Automatic Midtrans QRIS uses a separately configured 0.7% route fee
  (70 basis points) with the same gross-up logic. A fee absent from admin
  settings stays zero — never estimate a fee. Only pass through VA /
  e-wallet fees when allowed by the relevant merchant agreement.
- **Supported Midtrans methods (October 2026, `rebuild/PAYMENT.md`).**
  Merchant dashboard evidence confirms Midtrans GoPay Dynamic QRIS,
  GoPay, BNI, BRI, Mandiri, CIMB Niaga, Permata, BSI. It does NOT
  confirm BCA, OVO, DANA, or ShopeePay. Keep unsupported channels
  disabled in Admin > Pembayaran > Metode & Urutan and disable the DOKU
  gateway in Admin > Pembayaran > Gateway. Server-owned Snap
  `enabled_payments`: QRIS `other_qris`; e-wallet `gopay` only; generic
  bank transfer `bni_va`, `bri_va`, `echannel`, `cimb_va`, `permata_va`,
  `bsi_va` only. BSI is Midtrans-only and created disabled until the
  merchant checks the production flow. BCA stays disabled until the
  account actually enables BCA.

## Working method

Follow `AGENTS.md` §2: READ → VERIFY → PLAN → CHANGE → TEST → REVIEW
DIFF → PR → CI → MERGE → DEPLOY → VERIFY. Never ASSUME → EDIT →
CLAIM DONE. Never claim success without tool output / evidence.



For payment changes specifically (`CLAUDE.md` §7, §25):

1. Read the current state machine, tests, and `rebuild/PAYMENT.md`
   before changing anything. Do not simplify, broadly refactor, or
   rename away payment states without understanding the current state
   machine and tests.

1. Read the current state machine, tests, and `rebuild/PAYMENT.md`
   before changing anything. Do not simplify, broadly refactor, or
   rename away payment states without understanding the current state
   machine and tests.
2. Reproduce with a test first whenever practical (payment, wallet,
   checkout, callbacks/webhooks, provider routing, financial state
   transitions). After the fix, the test must demonstrate the regression
   is closed.
3. Keep changes small, testable, and compatible with the existing
   payment architecture. Preserve protocol rules, state machines,
   transaction safety, validation semantics, callback verification,
   retry/reconciliation algorithms, security behavior, provider adapter
   behavior, concurrency controls, and authorization rules in code
   (`CLAUDE.md` §6).
4. Run the relevant full test gates. A payment change requires targeted
   regression coverage.
5. Bugs (even minor) must be audited and discussed with the owner
   BEFORE being fixed. Bring the full findings list, not only critical
   ones.

## Sandbox and audit execution workflow

For a backend audit without code changes, finish the agreed scope before reporting. Trace routes → controllers → services/state transitions → provider adapters/callbacks → jobs/fulfillment; inspect and execute relevant tests. Separate source safeguards, test coverage, test execution, provider sandbox evidence, and production runtime facts. Do not present test presence as passing evidence.

Before provider sandbox calls: (1) inspect the actual return shape of the app runtime resolver; do not assume readiness keys exist; (2) use the integration registry to get required field names and report only present/missing, never values; (3) confirm selected environment and actual endpoint are sandbox/test; (4) use provider-documented test vectors and the required test flag; (5) report only status/code and redact secrets, tokens, and PII. A mode flag alone is insufficient. Never call a provider if the resolved endpoint is production.

For end-to-end tests hosted by the production app, remember that sandbox credentials do not make the app database a sandbox. Get explicit approval before creating a synthetic order there; use synthetic contact details and provider-approved test inputs only. Never bypass real product validation with arbitrary fake IDs; if validation fails, request an owner-approved test identifier or use a documented sandbox test vector. Verify local order/payment state and provider state independently. Distinguish token creation, pending payment, completed sandbox payment, verified callback, and fulfillment as separate proof levels. When using a sandbox simulator, use its official flow and verify provider status plus the application callback/state independently; simulator `PAID` alone does not prove the app received the callback. A token or an early empty DOM snapshot does not prove the payment page is blank: wait for rendering, inspect body text and loaded resources. After settlement, inspect fulfillment attempt and block reason; a paid order with `BLOCKED` fulfillment is payment success, not end-to-end fulfillment success. Never fabricate settlement; require provider confirmation and backend callback/status verification.

Run PHPUnit in an isolated local checkout, not on production. Check actual HOME, locate PHP and the project vendor/bin/phpunit, and invoke both by absolute path. Run focused suites first and the full suite for broad audits; report counts and skipped tests. Never point tests at the VPS database or install test dependencies on production.

For VPS read-only inspection, use the documented relay wrapper, verify hostname, and query only necessary metadata. Use the actual runtime resolver plus registry to assess credential readiness; never inspect raw encrypted credential columns or print secret values. Prefer short, validated Laravel bootstrap projections. After a malformed read-only query, verify no side effect and stop escalating complicated shell/PHP quoting; switch to a simpler validated approach.

Keep progress updates sparse and concrete. If the user asks to continue/check all, complete safe checks before reporting; do not repeat findings or promise work instead of doing it. Final report must separate evidence types, state blockers and any DB/server writes, and never imply sandbox proof means production readiness.

## Integration with integrate-midtrans-payments

Load `integrate-midtrans-payments` for Midtrans product detail:

- Snap checkout (popup / redirect / embed / mobile WebView).
- Core API, BI-SNAP, QRIS, virtual accounts, GoPay, GoPay tokenization,
  GoPayLater, card and 3DS, Alfamart/Indomaret OTC, Payment Link,
  Subscription API.
- Webhook / notification handling, signature verification, status
  polling, settlement, sandbox testing, go-live cutover.

Do NOT duplicate that skill's Midtrans product content here. This skill
adds the LFAMILIA guardrails listed above.

Critical pairing rules from that skill that this guardian enforces:

- Snap `gross_amount`: integer when **creating** a Snap token; raw
  provider string when **verifying** the notification signature. Hashing
  a reformatted amount is the most common Snap signature bug.
- BI-SNAP signatures: three independent helpers (access-token RSA-SHA256,
  transaction HMAC-SHA512, notification verify with Midtrans public key).
  Never share one helper across the three.
- GoPay flows: one-time GoPay Direct Debit, tokenized GoPay wallet
  payment, GoPayLater, account linking, unlinking are **five different
  request shapes**.
- Live `docs.midtrans.com` content wins over the skill for endpoints,
  parameters, field names, and product availability; the skill wins for
  integration discipline (state model, idempotency, signature hygiene,
  verification).

## What this skill NEVER does

- Never implements, an automatic refund path. (Refunds are manual via CS,
  DE-2026-10-17-05; never code automatic refunds.)
- Never converts UNKNOWN / timeout / ambiguous results into "failed"
  to make recovery easier.
- Never trusts browser redirect, frontend callback data, frontend
  totals/prices, or customer-supplied provider mapping / SKU / route /
  cost / total.
- Never creates a second payment because the first request timed out.
- Never changes prices, fees, or the state machine without an explicit
  reviewed requirement.
- Never runs a real provider transaction without explicit authorization
  for that exact test.
- Never uses live credentials casually for development or CI.
- Never logs passwords, API secrets, access tokens, full credentials,
  private keys, raw payment secrets, payment tokens, password hashes,
  callback signatures, or raw sensitive provider payloads.

## Simulation testing

Payment tests are simulation/sandbox ONLY. See
`references/payment-checklist.md` for the verification checklist and the
four required simulation scenarios: (a) fake Midtrans webhook → verify
signature + security; (b) duplicate notification → idempotency (process
once, never double); (c) simulated UNKNOWN status → manual
reconciliation (never automatic refund); (d) an automatic refund request
→ REFUSE (never build an automatic refund path; refunds are manual via CS,
manual settlement only).

## Source map

| Rule | Source |
|---|---|
| Refund manual via CS, never automatic; simulation-only tests; no production touch; no secrets in output | `AGENTS.md` §3 |
| Mandatory skill lookup, Midtrans refund section rejected, owner wins over skills, vendored skills not edited | `AGENTS.md` §1 |
| Working method, never claim without evidence, bugs audited before fix | `AGENTS.md` §2 |
| Payment invariants (idempotency, single-send, UNKNOWN, callback verification, terminal-state protection, concurrency) | `CLAUDE.md` §7, §32 |
| Routing boundary, priority ordering, no blind failover | `CLAUDE.md` §8 |
| Wallet ledger, lockForUpdate, non-negative balance | `CLAUDE.md` §10 |
| Test-first for high-risk changes | `CLAUDE.md` §25 |
| Gateway/channel model, fees, gross-up, supported methods, state machine, callback authority, wallet, manual QRIS | `rebuild/PAYMENT.md` |
| Midtrans product detail (Snap, Core API, BI-SNAP, GoPay, signatures, pairing rules) | `integrate-midtrans-payments` skill (refund content rejected) |
