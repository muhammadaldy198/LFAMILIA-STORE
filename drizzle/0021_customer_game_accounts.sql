CREATE TABLE IF NOT EXISTS customer_game_accounts (
  id TEXT PRIMARY KEY NOT NULL,
  customer_id TEXT NOT NULL,
  product_slug TEXT NOT NULL,
  label TEXT NOT NULL,
  values_json TEXT NOT NULL DEFAULT '[]',
  nickname TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS customer_game_accounts_customer_product_idx
  ON customer_game_accounts(customer_id, product_slug, updated_at DESC);
