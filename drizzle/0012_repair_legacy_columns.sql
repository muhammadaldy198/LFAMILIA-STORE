-- Run once only if the production D1 database was created before the current schema.
-- The app also repairs these columns automatically after deployment.
ALTER TABLE store_settings ADD COLUMN discord_url TEXT;
ALTER TABLE products ADD COLUMN banner_url TEXT;
ALTER TABLE product_packages ADD COLUMN supplier_price INTEGER;
ALTER TABLE product_packages ADD COLUMN pricing_mode TEXT DEFAULT 'manual' NOT NULL;
ALTER TABLE product_packages ADD COLUMN margin_type TEXT DEFAULT 'fixed' NOT NULL;
ALTER TABLE product_packages ADD COLUMN margin_value INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE product_packages ADD COLUMN supplier_synced_at TEXT;
