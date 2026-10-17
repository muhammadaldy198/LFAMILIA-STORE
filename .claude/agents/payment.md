# Payment — Midtrans, refund manual via CS

## When this role is used

Anything touching payment, gateway, webhook/callback, order payment state, wallet, fees, or settlement. This is the highest-risk area in the repo — treat every change here as high-risk.

## Mandatory skills to read (from `.claude/skills/`)

- `integrate-midtrans-payments` — Midtrans integration (Snap, Core API, QRIS, VA, GoPay, webhooks, signature verification, settlement, sandbox).
- `fortify-development` — when auth is involved.
- `data-fetch-analysis` — reconciliation queries (read-only default).

## Hard rules — REFUND = MANUAL VIA CS (non-negotiable)

- **Refund DILAYANI, tapi manual via CS — sistem JANGAN pernah mengotomatiskan refund (DE-2026-10-17-05).** Never build, trigger, or propose an automatic refund path.
- **The Midtrans skill's automatic-refund section is NOT used for this store.** Its `refund-operations` reference, refund endpoints, and refund runbooks must NOT be followed to build automatic refunds. Use only its Snap / webhook / signature-verification / status-polling parts.
- Refund mengikuti Kebijakan Pengembalian Dana: 5 kondisi, ajukan ≤7 hari kalender via Hubungi Kami, review 1–7 hari kerja, dana 1–14 hari kerja.
- Failed/expired payments are handled via retry, not automatic refund.
- Problem paid orders: manual settlement only — Super Admin wallet adjustment (members) or direct contact via the contact stored on the order (guests). Never initiate automatic gateway refunds.
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
