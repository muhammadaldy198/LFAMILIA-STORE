CREATE TABLE IF NOT EXISTS customer_password_reset_tokens (
  id TEXT PRIMARY KEY NOT NULL,
  customer_id TEXT NOT NULL,
  token_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  consumed_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
);
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS customer_password_reset_token_unique
ON customer_password_reset_tokens (token_hash);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS customer_password_reset_customer_created_idx
ON customer_password_reset_tokens (customer_id, created_at);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS customer_password_reset_expiry_idx
ON customer_password_reset_tokens (expires_at, consumed_at);
