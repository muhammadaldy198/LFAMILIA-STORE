-- Final pre-production cleanup: DOKU uses hosted Checkout only.
INSERT OR IGNORE INTO integration_profiles (
  provider, mode, environment, encrypted_config, created_at, updated_at
)
SELECT provider, 'checkout', environment, encrypted_config, created_at, updated_at
FROM integration_profiles
WHERE provider = 'doku' AND mode = 'direct';
--> statement-breakpoint
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
--> statement-breakpoint
ALTER TABLE wallet_topups ADD COLUMN gateway_status_checked_at TEXT;
