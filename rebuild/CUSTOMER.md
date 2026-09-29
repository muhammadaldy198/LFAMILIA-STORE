# Customer (M4)

- Account routes require a customer session and a phone. Profile changes, password changes, account deletion, wallet balance/ledger, tier, own order list/detail, and own support tickets are implemented.
- New customers get a zero-balance wallet. Existing customers obtain one on first account/API access. No customer-facing route credits or debits a wallet. Payment-verified top-ups, refund and checkout debits belong to M6/M7.
- Tier order and settings come from membership_tiers. Requirements and benefits are data, not hardcoded thresholds. Tier changes and Super Admin audit controls belong to M9.
- Guest order access uses a 64-character random code stored only as a SHA-256 hash. The code is returned once by GuestOrderAccess::issue($orderId), to be called by guest checkout in M6. The guest submits order number plus code via POST; a session then authorizes only that order status. No token is put into the URL. Guest has no wallet.
- Customer order history and status use existing orders. Checkout and order creation are M6. Support ticket creation/list/detail is live for registered customers; admin responses and ticket operations belong to M9.
- Self-delete anonymizes and soft-deletes accounts only if zero balance and no ledger entries, top-ups, orders, or tickets. The scheduled 30-day cleanup uses the same checks and rechecks activity inside the locked transaction. Historical business records remain protected.
- Guest reviews and signed review eligibility are introduced with fulfilled orders/reviews in later milestones.
