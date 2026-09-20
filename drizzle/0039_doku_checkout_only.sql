-- Final payment-routing cleanup.
-- DOKU Direct is not an active runtime mode. Historical Direct rows are kept
-- untouched as audit data: this migration does not relabel, expire, reject, or
-- erase provider artifacts for transactions created by another protocol.
-- Only transactions created through DOKU Checkout may use mode = 'checkout'.
--
-- DOKU credential profiles are migrated/sanitized by payment-mode-config.ts
-- because encrypted_config cannot be safely field-filtered in SQL.

UPDATE orders
SET payment_gateway_mode = 'snap'
WHERE payment_gateway = 'midtrans'
  AND payment_gateway_mode = 'bisnap';
--> statement-breakpoint

UPDATE wallet_topups
SET payment_gateway_mode = 'snap'
WHERE payment_gateway = 'midtrans'
  AND payment_gateway_mode = 'bisnap';
