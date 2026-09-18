ALTER TABLE customer_users ADD COLUMN phone_verified_at TEXT;
--> statement-breakpoint
UPDATE customer_users
SET phone = CASE
  WHEN REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', '') LIKE '08%'
    THEN '+62' || SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', ''), 2)
  WHEN REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', '') LIKE '628%'
    THEN '+' || REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', '')
  ELSE REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', '')
END
WHERE TRIM(phone) <> '';
--> statement-breakpoint
UPDATE customer_users
SET phone_verified_at = CURRENT_TIMESTAMP
WHERE TRIM(phone) <> ''
  AND phone IN (
    SELECT phone
    FROM customer_users
    WHERE TRIM(phone) <> ''
    GROUP BY phone
    HAVING COUNT(*) = 1
  );
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS customer_users_verified_phone_unique
ON customer_users (phone)
WHERE phone_verified_at IS NOT NULL AND TRIM(phone) <> '';
--> statement-breakpoint
CREATE TABLE customer_phone_otp_challenges (
  id TEXT PRIMARY KEY NOT NULL,
  customer_id TEXT NOT NULL,
  phone TEXT NOT NULL,
  otp_hash TEXT NOT NULL,
  otp_salt TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  attempt_count INTEGER DEFAULT 0 NOT NULL,
  sent_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL,
  consumed_at TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
);
--> statement-breakpoint
CREATE INDEX customer_phone_otp_customer_created_idx
ON customer_phone_otp_challenges (customer_id, created_at);
--> statement-breakpoint
CREATE INDEX customer_phone_otp_expiry_idx
ON customer_phone_otp_challenges (expires_at, consumed_at);
--> statement-breakpoint
ALTER TABLE product_reviews RENAME TO product_reviews_legacy_0037;
--> statement-breakpoint
DROP INDEX IF EXISTS product_reviews_customer_product_unique;
--> statement-breakpoint
DROP INDEX IF EXISTS product_reviews_product_visible_idx;
--> statement-breakpoint
CREATE TABLE product_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  customer_id TEXT,
  order_id TEXT,
  reviewer_name TEXT NOT NULL DEFAULT 'Pelanggan',
  product_slug TEXT NOT NULL,
  rating INTEGER NOT NULL,
  title TEXT,
  body TEXT NOT NULL,
  is_verified_purchase INTEGER DEFAULT true NOT NULL,
  is_visible INTEGER DEFAULT true NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE SET NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);
--> statement-breakpoint
INSERT INTO product_reviews (
  id, customer_id, order_id, reviewer_name, product_slug, rating, title, body,
  is_verified_purchase, is_visible, created_at, updated_at
)
SELECT
  r.id,
  r.customer_id,
  NULL,
  COALESCE(NULLIF(TRIM(u.name), ''), 'Pelanggan'),
  r.product_slug,
  r.rating,
  r.title,
  r.body,
  r.is_verified_purchase,
  r.is_visible,
  r.created_at,
  r.updated_at
FROM product_reviews_legacy_0037 r
LEFT JOIN customer_users u ON u.id = r.customer_id;
--> statement-breakpoint
DROP TABLE product_reviews_legacy_0037;
--> statement-breakpoint
CREATE UNIQUE INDEX product_reviews_customer_product_unique
ON product_reviews (customer_id, product_slug)
WHERE customer_id IS NOT NULL;
--> statement-breakpoint
CREATE UNIQUE INDEX product_reviews_order_unique
ON product_reviews (order_id)
WHERE order_id IS NOT NULL;
--> statement-breakpoint
CREATE INDEX product_reviews_product_visible_idx
ON product_reviews (product_slug, is_visible, created_at);
