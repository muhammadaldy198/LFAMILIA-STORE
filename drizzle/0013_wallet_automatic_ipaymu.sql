ALTER TABLE wallet_settings ADD COLUMN manual_qris_enabled INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE wallet_settings ADD COLUMN manual_qris_name TEXT DEFAULT 'QRIS Manual' NOT NULL;
ALTER TABLE wallet_settings ADD COLUMN manual_qris_image_url TEXT;
ALTER TABLE wallet_settings ADD COLUMN ipaymu_topup_enabled INTEGER DEFAULT 0 NOT NULL;

ALTER TABLE wallet_topups ADD COLUMN source TEXT DEFAULT 'manual' NOT NULL;
ALTER TABLE wallet_topups ADD COLUMN reference_id TEXT;
ALTER TABLE wallet_topups ADD COLUMN ipaymu_transaction_id TEXT;
ALTER TABLE wallet_topups ADD COLUMN ipaymu_payment_no TEXT;
ALTER TABLE wallet_topups ADD COLUMN ipaymu_payment_name TEXT;
ALTER TABLE wallet_topups ADD COLUMN ipaymu_payment_url TEXT;
ALTER TABLE wallet_topups ADD COLUMN ipaymu_expired_at TEXT;
ALTER TABLE wallet_topups ADD COLUMN payment_fee INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE wallet_topups ADD COLUMN payment_total INTEGER DEFAULT 0 NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS wallet_topups_reference_unique
ON wallet_topups(reference_id);
