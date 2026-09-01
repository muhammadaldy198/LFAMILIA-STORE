CREATE TABLE IF NOT EXISTS customer_support_requests (
  id TEXT PRIMARY KEY NOT NULL,
  customer_id TEXT NOT NULL,
  kind TEXT NOT NULL,
  order_reference TEXT,
  subject TEXT NOT NULL,
  message TEXT NOT NULL,
  status TEXT DEFAULT 'open' NOT NULL,
  staff_reply TEXT,
  handled_by TEXT,
  handled_at TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS customer_support_customer_created_idx
ON customer_support_requests(customer_id, created_at);

CREATE INDEX IF NOT EXISTS customer_support_status_created_idx
ON customer_support_requests(status, created_at);
