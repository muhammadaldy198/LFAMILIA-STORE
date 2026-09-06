CREATE TABLE IF NOT EXISTS digiflazz_seller_monitor (
  package_id INTEGER PRIMARY KEY,
  seller_name TEXT,
  current_price INTEGER,
  baseline_price INTEGER,
  buyer_product_status INTEGER,
  seller_product_status INTEGER,
  unlimited_stock INTEGER,
  stock INTEGER,
  multi INTEGER,
  start_cut_off TEXT,
  end_cut_off TEXT,
  description TEXT,
  health TEXT DEFAULT 'unknown' NOT NULL,
  alert_reason TEXT,
  last_checked_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS digiflazz_seller_monitor_health_idx
ON digiflazz_seller_monitor (health, last_checked_at);
