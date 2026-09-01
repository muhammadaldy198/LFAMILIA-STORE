ALTER TABLE product_packages ADD COLUMN supplier_price INTEGER;
ALTER TABLE product_packages ADD COLUMN pricing_mode TEXT DEFAULT 'manual' NOT NULL;
ALTER TABLE product_packages ADD COLUMN margin_type TEXT DEFAULT 'fixed' NOT NULL;
ALTER TABLE product_packages ADD COLUMN margin_value INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE product_packages ADD COLUMN supplier_synced_at TEXT;

CREATE TABLE IF NOT EXISTS digiflazz_pricing_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  is_auto_sync INTEGER DEFAULT 1 NOT NULL,
  margin_type TEXT DEFAULT 'fixed' NOT NULL,
  margin_value INTEGER DEFAULT 0 NOT NULL,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL
);

INSERT OR IGNORE INTO digiflazz_pricing_settings (id, is_auto_sync, margin_type, margin_value)
VALUES (1, 1, 'fixed', 0);
