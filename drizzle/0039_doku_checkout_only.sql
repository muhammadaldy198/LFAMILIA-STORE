-- Remove the abandoned DOKU Direct configuration before production launch.
DELETE FROM integration_profiles
WHERE provider = 'doku' AND mode = 'direct';
--> statement-breakpoint
UPDATE orders
SET payment_gateway_mode = 'checkout'
WHERE payment_gateway = 'doku' AND payment_gateway_mode = 'direct';
--> statement-breakpoint
UPDATE wallet_topups
SET payment_gateway_mode = 'checkout'
WHERE payment_gateway = 'doku' AND payment_gateway_mode = 'direct';
