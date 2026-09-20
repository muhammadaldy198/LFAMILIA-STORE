-- Legacy DOKU payment artifact columns kept for migration-history compatibility.
ALTER TABLE orders ADD COLUMN doku_reference_no TEXT;
ALTER TABLE orders ADD COLUMN doku_payment_no TEXT;
ALTER TABLE orders ADD COLUMN doku_qr_content TEXT;
ALTER TABLE orders ADD COLUMN doku_payment_name TEXT;
ALTER TABLE orders ADD COLUMN doku_status_checked_at TEXT;

ALTER TABLE wallet_topups ADD COLUMN doku_reference_no TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_payment_no TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_qr_content TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_payment_name TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_status_checked_at TEXT;
