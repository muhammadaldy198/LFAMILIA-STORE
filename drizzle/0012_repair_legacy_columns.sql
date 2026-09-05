-- Compatibility marker only.
-- These columns are already created by 0005_customer_experience.sql and
-- 0009_digiflazz_auto_pricing.sql on a fresh database.
-- Legacy production databases are repaired idempotently at runtime by
-- lib/server/database-repair.ts, so this migration must remain a no-op.
SELECT 1;
