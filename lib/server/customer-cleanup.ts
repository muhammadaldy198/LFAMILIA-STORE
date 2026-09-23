import { getD1 } from "@/db";

export type CustomerCleanupSettings = {
  enabled: boolean;
  inactivityDays: number;
  lastRunAt: string | null;
  lastDeletedCount: number;
};

export type CustomerDeletionEligibility = {
  customerId: string;
  balance: number;
  orderCount: number;
  topupCount: number;
  walletTransactionCount: number;
  canDelete: boolean;
};

let schemaPromise: Promise<void> | null = null;

async function ensureCustomerCleanupSchema() {
  if (!schemaPromise) {
    schemaPromise = (async () => {
      const db = getD1();
      await db.prepare(`CREATE TABLE IF NOT EXISTS customer_cleanup_settings (
        id INTEGER PRIMARY KEY NOT NULL CHECK (id = 1),
        enabled INTEGER NOT NULL DEFAULT 1,
        inactivity_days INTEGER NOT NULL DEFAULT 30 CHECK (inactivity_days BETWEEN 7 AND 365),
        last_run_at TEXT,
        last_deleted_count INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )`).run();
      await db.prepare(`INSERT OR IGNORE INTO customer_cleanup_settings
        (id, enabled, inactivity_days, last_deleted_count)
        VALUES (1, 1, 30, 0)`).run();
    })().catch((error) => {
      schemaPromise = null;
      throw error;
    });
  }
  return schemaPromise;
}

export async function getCustomerCleanupSettings(): Promise<CustomerCleanupSettings> {
  await ensureCustomerCleanupSchema();
  const row = await getD1().prepare(`SELECT enabled, inactivity_days, last_run_at, last_deleted_count
    FROM customer_cleanup_settings WHERE id = 1 LIMIT 1`)
    .first<{ enabled: number; inactivity_days: number; last_run_at: string | null; last_deleted_count: number }>();
  return {
    enabled: Boolean(row?.enabled ?? 1),
    inactivityDays: Math.min(365, Math.max(7, Number(row?.inactivity_days ?? 30))),
    lastRunAt: row?.last_run_at ?? null,
    lastDeletedCount: Math.max(0, Number(row?.last_deleted_count ?? 0)),
  };
}

export async function saveCustomerCleanupSettings(input: { enabled: boolean; inactivityDays: number }) {
  await ensureCustomerCleanupSchema();
  const inactivityDays = Math.min(365, Math.max(7, Math.trunc(Number(input.inactivityDays) || 30)));
  await getD1().prepare(`UPDATE customer_cleanup_settings
    SET enabled = ?, inactivity_days = ?, updated_at = CURRENT_TIMESTAMP
    WHERE id = 1`)
    .bind(input.enabled ? 1 : 0, inactivityDays)
    .run();
  return getCustomerCleanupSettings();
}

async function readDeletionEligibility(customerId: string): Promise<CustomerDeletionEligibility | null> {
  const row = await getD1().prepare(`SELECT
      u.id AS customer_id,
      u.balance AS balance,
      (SELECT COUNT(*) FROM orders o WHERE o.customer_id = u.id) AS order_count,
      (SELECT COUNT(*) FROM wallet_topups t WHERE t.customer_id = u.id) AS topup_count,
      (SELECT COUNT(*) FROM wallet_transactions w WHERE w.customer_id = u.id) AS wallet_transaction_count
    FROM customer_users u
    WHERE u.id = ? AND u.email NOT LIKE '__lfadmin__:%'
    LIMIT 1`)
    .bind(customerId)
    .first<{
      customer_id: string;
      balance: number;
      order_count: number;
      topup_count: number;
      wallet_transaction_count: number;
    }>();
  if (!row) return null;
  const balance = Number(row.balance ?? 0);
  const orderCount = Number(row.order_count ?? 0);
  const topupCount = Number(row.topup_count ?? 0);
  const walletTransactionCount = Number(row.wallet_transaction_count ?? 0);
  return {
    customerId: row.customer_id,
    balance,
    orderCount,
    topupCount,
    walletTransactionCount,
    canDelete: balance === 0 && orderCount === 0 && topupCount === 0 && walletTransactionCount === 0,
  };
}

export async function permanentlyDeleteEmptyCustomer(customerId: string) {
  const before = await readDeletionEligibility(customerId);
  if (!before) return { deleted: false as const, reason: "not_found" as const, eligibility: null };
  if (!before.canDelete) return { deleted: false as const, reason: "has_history" as const, eligibility: before };

  const result = await getD1().prepare(`DELETE FROM customer_users
    WHERE id = ?
      AND email NOT LIKE '__lfadmin__:%'
      AND balance = 0
      AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customer_users.id)
      AND NOT EXISTS (SELECT 1 FROM wallet_topups t WHERE t.customer_id = customer_users.id)
      AND NOT EXISTS (SELECT 1 FROM wallet_transactions w WHERE w.customer_id = customer_users.id)`)
    .bind(customerId)
    .run();

  if (Number(result.meta.changes ?? 0) > 0) {
    return { deleted: true as const, reason: null, eligibility: before };
  }

  const after = await readDeletionEligibility(customerId);
  return {
    deleted: false as const,
    reason: after ? "changed" as const : "not_found" as const,
    eligibility: after,
  };
}

export async function cleanupDormantCustomerAccounts(options: { force?: boolean } = {}) {
  await ensureCustomerCleanupSchema();
  const settings = await getCustomerCleanupSettings();
  if (!settings.enabled && !options.force) {
    return { skipped: true as const, deleted: 0, settings };
  }

  const ageModifier = `-${settings.inactivityDays} days`;
  const result = await getD1().prepare(`DELETE FROM customer_users
    WHERE email NOT LIKE '__lfadmin__:%'
      AND balance = 0
      AND datetime(COALESCE(last_login_at, created_at)) <= datetime('now', ?)
      AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customer_users.id)
      AND NOT EXISTS (SELECT 1 FROM wallet_topups t WHERE t.customer_id = customer_users.id)
      AND NOT EXISTS (SELECT 1 FROM wallet_transactions w WHERE w.customer_id = customer_users.id)`)
    .bind(ageModifier)
    .run();

  const deleted = Math.max(0, Number(result.meta.changes ?? 0));
  await getD1().prepare(`UPDATE customer_cleanup_settings
    SET last_run_at = CURRENT_TIMESTAMP,
        last_deleted_count = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = 1`)
    .bind(deleted)
    .run();

  return {
    skipped: false as const,
    deleted,
    settings: await getCustomerCleanupSettings(),
  };
}
