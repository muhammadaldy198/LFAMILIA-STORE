CREATE TABLE IF NOT EXISTS customer_oauth_accounts (
  id TEXT PRIMARY KEY NOT NULL,
  customer_id TEXT NOT NULL,
  provider TEXT NOT NULL,
  provider_subject TEXT NOT NULL,
  provider_email TEXT,
  avatar_url TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
);
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS customer_oauth_provider_subject_unique
  ON customer_oauth_accounts (provider, provider_subject);
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS customer_oauth_provider_customer_unique
  ON customer_oauth_accounts (provider, customer_id);
