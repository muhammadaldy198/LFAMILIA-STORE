CREATE TABLE IF NOT EXISTS security_rate_limits (
  scope TEXT NOT NULL,
  bucket_start INTEGER NOT NULL,
  key_hash TEXT NOT NULL,
  hits INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (scope, bucket_start, key_hash)
);

CREATE TABLE IF NOT EXISTS admin_activity_logs (
  id TEXT PRIMARY KEY NOT NULL,
  admin_id INTEGER NOT NULL,
  admin_name TEXT NOT NULL,
  admin_role TEXT NOT NULL,
  action TEXT NOT NULL,
  target TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS admin_activity_logs_created_idx
ON admin_activity_logs(created_at DESC);
