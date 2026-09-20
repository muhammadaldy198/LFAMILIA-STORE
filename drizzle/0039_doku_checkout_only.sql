-- Final pre-production routing cleanup.
-- DOKU credential profiles are migrated/sanitized by payment-mode-config.ts
-- because encrypted_config cannot be safely field-filtered in SQL.
UPDATE orders
SET payment_gateway_mode = 'checkout'
WHERE payment_gateway = 'doku' AND payment_gateway_mode = 'direct';
--> statement-breakpoint
UPDATE wallet_topups
SET payment_gateway_mode = 'checkout'
WHERE payment_gateway = 'doku' AND payment_gateway_mode = 'direct';
--> statement-breakpoint
UPDATE orders
SET payment_gateway_mode = 'snap'
WHERE payment_gateway = 'midtrans' AND payment_gateway_mode = 'bisnap';
--> statement-breakpoint
UPDATE wallet_topups
SET payment_gateway_mode = 'snap'
WHERE payment_gateway = 'midtrans' AND payment_gateway_mode = 'bisnap';
