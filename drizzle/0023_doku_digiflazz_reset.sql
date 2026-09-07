-- DOKU is the single external payment gateway.
ALTER TABLE wallet_settings ADD COLUMN doku_topup_enabled INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE wallet_settings ADD COLUMN doku_checkout_enabled INTEGER DEFAULT 0 NOT NULL;

ALTER TABLE wallet_topups ADD COLUMN doku_request_id TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_token_id TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_payment_url TEXT;
ALTER TABLE wallet_topups ADD COLUMN doku_expired_at TEXT;

ALTER TABLE orders ADD COLUMN doku_request_id TEXT;
ALTER TABLE orders ADD COLUMN doku_token_id TEXT;
ALTER TABLE orders ADD COLUMN doku_payment_url TEXT;
ALTER TABLE orders ADD COLUMN doku_expired_at TEXT;

UPDATE wallet_settings
SET doku_topup_enabled = 0,
    doku_checkout_enabled = 0,
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1;

DELETE FROM integration_profiles
WHERE provider NOT IN ('doku', 'digiflazz', 'melostore', 'resend', 'relay', 'security');

DELETE FROM integration_settings
WHERE setting_key NOT IN ('doku_environment', 'digiflazz_environment', 'doku_migration_completed');

UPDATE faq_entries
SET answer = 'Virtual Account bank, dompet digital, dan QRIS tersedia melalui DOKU sesuai channel yang sedang aktif.'
WHERE question = 'Metode pembayaran apa yang tersedia?';

-- Store has not launched yet: remove pre-launch transactional state before DOKU go-live.
DELETE FROM order_events;
DELETE FROM voucher_deliveries;
DELETE FROM orders;
DELETE FROM wallet_transactions;
DELETE FROM wallet_topups;
