# Payment (M7)

M7 adds payment-channel pricing, gateway routing, payment creation, wallet settlement, Manual QRIS and verified callbacks. Fulfillment is intentionally not started here; M8 consumes only orders whose payment state has already become PAID.

## Customer contract

- The customer selects only a payment channel such as QRIS, Virtual Account, E-Wallet, QRIS Manual or Saldo LFAMILIA.
- Internal gateway names and gateway credentials are never returned by public catalog, checkout, payment or order-status responses.
- The server resolves channel → route → gateway and freezes that route inside the order snapshot.
- Payment-channel fees are calculated by the backend before order creation. The immutable order total is cost + margin - voucher discount + payment fee.
- Client-supplied price, fee, gateway, provider code, provider SKU and total remain prohibited.

## Payment creation and idempotency

- An order is created in PENDING_PAYMENT with a frozen payment channel, route, fee and expiry.
- Payment creation creates the local payment_transactions row before any external request.
- A repeated idempotency key returns the same local payment transaction.
- Once an order has a non-REJECTED payment attempt, a different idempotency key cannot create another payment for that same order.
- A deterministic create rejection is stored as REJECTED and may be retried with a new key after configuration is corrected.
- A timeout, transport failure, 5xx or unverified response is stored as UNKNOWN. UNKNOWN is never blindly retried against the gateway; the same order returns the existing uncertain payment until reconciliation.
- Payment gateway redirect/return pages are never treated as proof of payment.

## Midtrans Snap

- Credential source: encrypted integration_credentials row with code midtrans.
- Required secret: server_key. is_production controls sandbox vs production Snap endpoint.
- Create uses server-side Basic Auth and the frozen merchant reference / gross amount.
- Callback verification uses Midtrans SHA-512 notification signature over order_id + status_code + gross_amount + server key.
- Callback amount must match the immutable local payment amount.
- Duplicate callbacks are stored/deduplicated by a stable event id.
- settlement and accepted capture become PAID; expire becomes EXPIRED; cancel becomes CANCELLED; deny/failure become FAILED; refund becomes REFUNDED.

## DOKU Direct API

- Credential source: encrypted integration_credentials row with code doku.
- Required secrets: client_id and secret_key. Optional base_url defaults to sandbox.
- M7 implements the DOKU Non-SNAP HMACSHA256 request signature using Client-Id, Request-Id, Request-Timestamp, Request-Target and SHA-256 Digest.
- The DOKU response signature is verified with Response-Timestamp before public payment instructions are accepted.
- Each route provides an api_path and may provide a request_template and whitelisted public_paths. This keeps DOKU channel-specific payloads configurable without placing credentials in route settings or source code.
- Callback verification uses the raw request body and notification request target. Client-Id, signature and amount must match.
- Only safe payment instructions (payment URL/code, VA number or QR string) may reach the customer response.

## Manual QRIS

- The QR image is the store_assets entry manual_qris and is managed through the existing media system.
- Manual QRIS is invisible to customers until its gateway, channel and asset are active.
- Creating payment returns the configured LFAMILIA QR image and leaves the order PENDING_PAYMENT.
- ADMIN or SUPER_ADMIN may confirm a pending Manual QRIS payment. The action is audit-logged.
- Confirmation changes the order to PAID only. M8 owns manual/automatic fulfillment.

## Wallet and wallet top-up

- The saldo channel is registered-customer only.
- Order payment locks the wallet row, rejects insufficient balance, writes one ledger debit and marks the order PAID in the same transaction.
- Wallet top-up uses external payment channels only. Minimum top-up is stored in system_settings and defaults to Rp10.000.
- A verified top-up callback credits only the requested balance amount; payment-channel fees are not credited.
- Wallet credit/debit uses idempotency keys and row locking so retries do not double-settle.

## Payment state rules

- PAID is monotonic: stale PENDING/FAILED/CANCELLED callbacks cannot downgrade a paid transaction/order.
- A late PAID callback for an already EXPIRED/FAILED/CANCELLED order is recorded as PAYMENT_LATE_VERIFIED and does not automatically reopen or fulfill the order.
- Successful payment redeems a RESERVED voucher atomically.
- Order expiry releases the voucher reservation.
- Callback claims and state application are transactionally grouped so a processing failure does not permanently consume the callback id.

## Administration boundary

M7 includes only the operational Payment workspace needed to configure gateway/channel activation, maintenance, channel fees, channel routing, minimum wallet top-up and Manual QRIS confirmation. Integration-secret editing, broader permissions, notifications and the complete Admin workspace remain M9. No production credential is stored in GitHub.

## M8 boundary

M7 never creates a fulfillment attempt and never calls Digiflazz. M8 must consume the PAID state idempotently and must preserve the no-double-fulfillment / reconciliation rules from the PRD.
