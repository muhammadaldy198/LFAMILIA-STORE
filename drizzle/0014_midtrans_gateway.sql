ALTER TABLE wallet_settings ADD COLUMN midtrans_topup_enabled INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE wallet_settings ADD COLUMN midtrans_checkout_enabled INTEGER DEFAULT 0 NOT NULL;

ALTER TABLE wallet_topups ADD COLUMN midtrans_transaction_id TEXT;
ALTER TABLE wallet_topups ADD COLUMN midtrans_payment_url TEXT;

ALTER TABLE orders ADD COLUMN midtrans_transaction_id TEXT;
ALTER TABLE orders ADD COLUMN midtrans_payment_url TEXT;
