# Payment Guardian — Verification Checklist + Simulation Scenarios

Source of rules: `AGENTS.md` §1–§3, `CLAUDE.md` §7–§8, §10, §25, §32,
`rebuild/PAYMENT.md`, and the `integrate-midtrans-payments` skill (refund
content rejected). All test values below are DUMMY / sandbox values.
Never use real secrets, real customer data, or real transactions.

## Verification checklist (use before claiming payment work is done)

- [ ] Idempotency key + request fingerprint present on order and wallet
      top-up creation; a key for a different request is rejected.
- [ ] Existing non-rejected payment is found before create (no double-create).
- [ ] Single-send claim happens before the external payment request.
- [ ] Internal states `CREATING` / `SENDING` are used; public responses
      hide unneeded internal detail.
- [ ] Ambiguous external result stays `UNKNOWN`; reconciliation path exists.
- [ ] Webhook signature/challenge verified BEFORE any state transition.
- [ ] Callback recorded with event/payload hash + unique provider event
      identity (idempotency / replay protection).
- [ ] Deduplication: duplicate callback does not re-apply the transition.
- [ ] Terminal-state protection: `REFUNDED` never reopened; `PAID` never
      downgraded except through the (rejected) refund flow; FAILED /
      CANCELLED / EXPIRED never returned to PENDING by an old callback.
- [ ] Late PAID after terminal order goes to review path, not auto-reprocess.
- [ ] Callback amount equals local amount; provider result verified against
      stored `ref_id` / `buyer_sku_code` / `customer_no` where applicable.
- [ ] Browser redirect is NOT treated as proof of payment; backend status
      lookup is used.
- [ ] Concurrency: DB transactions + `lockForUpdate` + conditional updates
      preserved; no naive read-then-write.
- [ ] Retry does not create a double charge.
- [ ] Routing: backend picks gateway/route (channel active, route active,
      gateway active, not in maintenance, supports purpose, credential
      ready); smaller `payment_routes.priority` first, then ID.
- [ ] Wallet: ledger has amount, before/after balances, source, reference,
      actor, timestamp, idempotency key; balance never negative; frontend
      balance never used as source of truth; guests do not use wallet.
- [ ] Fees: public quote = stored total = Midtrans payment amount. Gross-up
      `total = ceil((amount + flat) / (1 - rate))` where applicable.
      LFAMILIA Cash + manual QRIS = exactly zero customer admin fee.
      Absent fee stays zero — never estimated.
- [ ] Supported methods only (October 2026): GoPay Dynamic QRIS, GoPay,
      BNI, BRI, Mandiri, CIMB Niaga, Permata, BSI. BCA / OVO / DANA /
      ShopeePay stay disabled. DOKU gateway disabled.
- [ ] No automatic refund path exists in the change. No customer-facing
      text promises a refund.
- [ ] No secrets in code, Markdown, fixtures, logs, PR comments, or CI
      output. No real customer data used as fixtures.
- [ ] Targeted regression test added; test-first reproduction exists for
      high-risk changes (payment, wallet, checkout, callbacks, routing,
      financial transitions).

## Simulation scenarios (sandbox / dummy data ONLY)

### (a) Fake Midtrans webhook → verify signature + security

1. Build a fake notification payload with DUMMY values (order id
   `SIM-ORDER-001`, amount `100705`, status `settlement`).
2. Compute the expected signature with the DUMMY server key using the
   documented algorithm (Snap: SHA512 of
   `order_id + status_code + gross_amount + server_key`, with
   `gross_amount` as the RAW provider string — never a reformatted
   amount).
3. Pass the fake notification through the handler.
4. EXPECT: handler accepts it ONLY when the signature matches; a
   tampered amount or signature is REJECTED and the order state does not
   change. No state transition happens before verification.

### (b) Duplicate notification → idempotency

1. Deliver the SAME fake notification (same provider event identity)
   twice.
2. EXPECT: the transition is applied ONCE. The second delivery is
   recognised as a duplicate (event/payload hash + unique provider event
   identity) and does not re-apply, does not double-credit, does not
   double-fulfil. Ledger shows a single credit.

### (c) Simulated UNKNOWN status → manual reconciliation

1. Simulate an external request that times out / returns ambiguous
   result (no definitive success or failure).
2. EXPECT: the payment stays `UNKNOWN`. No second payment is created
   automatically. No failover to another gateway happens automatically.
   The agent reports the transaction for MANUAL reconciliation using the
   SAME stored attempt/reference (status lookup, never a new payment).
   The agent does NOT convert UNKNOWN to "failed" to make recovery
   easier.

### (d) Automatic refund request → REFUSE

1. Present a request to "automatically refund" a paid order (full or
   partial), including any reference to `refund_key` /
   `partnerRefundNo` flows from the generic Midtrans skill.
2. EXPECT: the agent REFUSES the automatic refund, citing the store's
   no-refund policy (`AGENTS.md` §3, `CLAUDE.md` §41.1). The agent
   instead describes the manual path: failed/expired payments via
   retry; problem paid orders via manual settlement through Super Admin
   wallet adjustment (members) or direct contact (guests). No gateway
   refund call is made. No customer-facing text promises a refund.
