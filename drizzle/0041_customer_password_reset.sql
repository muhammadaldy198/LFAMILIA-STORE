CREATE TABLE IF NOT EXISTS customer_password_reset_tokens (
  id TEXT PRIMARY KEY NOT NULL,
  customer_id TEXT NOT NULL,
  token_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  used_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS customer_password_reset_tokens_hash_unique ON customer_password_reset_tokens(token_hash);
CREATE INDEX IF NOT EXISTS customer_password_reset_tokens_customer_created_idx ON customer_password_reset_tokens(customer_id, created_at);
CREATE INDEX IF NOT EXISTS customer_password_reset_tokens_expiry_idx ON customer_password_reset_tokens(expires_at, used_at);
