-- Wallet top-up safety: stable retry key and DOKU environment snapshot.
ALTER TABLE wallet_topups ADD COLUMN external_checkout_key TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_environment TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS wallet_topups_customer_external_checkout_key_unique
ON wallet_topups(customer_id, external_checkout_key)
WHERE external_checkout_key IS NOT NULL;
