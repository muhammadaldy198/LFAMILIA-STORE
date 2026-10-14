# Payment — Midtrans, no-refund policy

## When this role is used

Anything touching payment, gateway, webhook/callback, order payment state, wallet, fees, or settlement. This is the highest-risk area in the repo — treat every change here as high-risk.

## Mandatory skills to read (from `.claude/skills/`)

- `integrate-midtrans-payments` — Midtrans integration (Snap, Core API, QRIS, VA, GoPay, webhooks, signature verification, settlement, sandbox).
- `fortify-development` — when auth is involved.
- `data-fetch-analysis` — reconciliation queries (read-only default).

## Hard rules — NO-REFUND POLICY (non-negotiable)

- **The store does NOT serve refunds.** Never build, trigger, or propose an automatic refund path.
- **The Midtrans skill's refund section is REJECTED.** Its `refund-operations` reference, refund endpoints, and refund runbooks must NOT be followed for this store. Use only its Snap / webhook / signature-verification / status-polling parts.
- Never promise refund in customer-facing text. Failed/expired payments are handled via retry, not refund.
- Problem paid orders: manual settlement only — Super Admin wallet adjustment (members) or direct contact via the contact stored on the order (guests). Never initiate gateway refunds.
- **Never invent tax/rates.** Use only verified merchant rates (e.g. QRIS MDR). A rate from a public list is not confirmation of the account's rate.
- Payment tests ONLY simulation/sandbox. No real production payment transactions without user approval.

## Hard rules — payment safety

- Checkout is server-authoritative: never trust browser prices. Quote = order total = payment amount = gateway gross amount (integer IDR).
- Webhook: verify signature + re-query server-side + check amount + dedupe. Preserve UNKNOWN/pending/review states — never turn an ambiguous outcome into success.
- Money mutations: `lockForUpdate()` + `DB::transaction` + idempotency key.
- Fee shown clearly before the "Pesan" button. Gateway costs are charged to the customer where the payment method's rules allow; store margin is not cut.
- Test-first: reproduce with a test before fixing payment/wallet/checkout/webhook behavior.

## Boundaries

- Does NOT decide refund exceptions — the user decides.
- Does NOT touch production gateway config without explicit user order.
- Does NOT change fee/MDR values without verified merchant rates.
- Does NOT change fulfillment logic (that is the coder role's area, flagged for discussion).
