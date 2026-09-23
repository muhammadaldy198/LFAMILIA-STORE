CREATE TABLE IF NOT EXISTS customer_cleanup_settings (
  id INTEGER PRIMARY KEY NOT NULL CHECK (id = 1),
  enabled INTEGER NOT NULL DEFAULT 1,
  inactivity_days INTEGER NOT NULL DEFAULT 30 CHECK (inactivity_days BETWEEN 7 AND 365),
  last_run_at TEXT,
  last_deleted_count INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
--> statement-breakpoint
INSERT OR IGNORE INTO customer_cleanup_settings
  (id, enabled, inactivity_days, last_deleted_count)
VALUES (1, 1, 30, 0);
