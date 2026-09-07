ALTER TABLE wallet_topups ADD COLUMN source TEXT DEFAULT 'manual' NOT NULL;
ALTER TABLE wallet_topups ADD COLUMN reference_id TEXT;
ALTER TABLE wallet_topups ADD COLUMN payment_fee INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE wallet_topups ADD COLUMN payment_total INTEGER DEFAULT 0 NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS wallet_topups_reference_unique
ON wallet_topups(reference_id);
