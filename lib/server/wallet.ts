import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";

export type WalletSettings = {
  minTopup: number;
  automaticTopupEnabled: boolean;
};

const fallbackSettings: WalletSettings = {
  minTopup: 10_000,
  automaticTopupEnabled: false,
};

export async function readWalletSettings(
  options: { repairSchema?: boolean } = {},
): Promise<WalletSettings> {
  try {
    if (options.repairSchema !== false) await ensureLegacyDatabaseColumns();
    const row = await getD1()
      .prepare(`SELECT min_topup, doku_topup_enabled
        FROM wallet_settings WHERE id = 1`)
      .first<{
        min_topup: number;
        doku_topup_enabled: number;
      }>();
    if (!row) return fallbackSettings;
    return {
      minTopup: row.min_topup,
      automaticTopupEnabled: Boolean(row.doku_topup_enabled),
    };
  } catch {
    return fallbackSettings;
  }
}

export async function saveWalletSettings(input: WalletSettings) {
  await ensureLegacyDatabaseColumns();
  await getD1()
    .prepare(
      `INSERT INTO wallet_settings (id, min_topup, doku_topup_enabled, updated_at)
       VALUES (1, ?, ?, CURRENT_TIMESTAMP)
       ON CONFLICT(id) DO UPDATE SET
         min_topup = excluded.min_topup,
         doku_topup_enabled = excluded.doku_topup_enabled,
         updated_at = CURRENT_TIMESTAMP`,
    )
    .bind(
      input.minTopup,
      input.automaticTopupEnabled ? 1 : 0,
    )
    .run();
}

export async function listWalletTopups(limit = 200) {
  const result = await getD1()
    .prepare(
      `SELECT t.id, t.customer_id, t.amount, t.sender_name, t.payment_method, t.proof_url,
      t.status, t.admin_notes, t.reviewed_by, t.reviewed_at, t.created_at,
      u.name AS customer_name, u.email AS customer_email, u.balance AS customer_balance
     FROM wallet_topups t JOIN customer_users u ON u.id = t.customer_id
     ORDER BY CASE t.status WHEN 'pending' THEN 0 ELSE 1 END, t.created_at DESC LIMIT ?`,
    )
    .bind(Math.min(Math.max(limit, 1), 500))
    .all();
  return result.results;
}

export class WalletSettlementError extends Error {}

export async function settleWalletOrder(input: {
  customerId: string;
  orderId: string;
  amount: number;
  description: string;
  fulfillmentType: "automatic" | "manual";
  voucherCode: string | null;
  flashSaleId: number | null;
}) {
  const db = getD1();
  const reference = `order:${input.orderId}`;
  const now = new Date().toISOString();
  const statements = [
    db
      .prepare(
        `INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
       SELECT ?, ?, 'debit', ?, ledger.balance, ledger.balance - ?, ?, ?
       FROM (
         SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance
         FROM wallet_transactions WHERE customer_id = ?
       ) ledger
       WHERE ledger.balance >= ?
         AND EXISTS (
           SELECT 1 FROM orders
           WHERE id = ? AND customer_id = ? AND payment_method = 'wallet' AND payment_status = 'pending'
         )
         AND (? IS NULL OR EXISTS (
           SELECT 1 FROM discount_vouchers
           WHERE code = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
             AND (usage_limit IS NULL OR used_count + reserved_count < usage_limit)
         ))
         AND (? IS NULL OR EXISTS (
           SELECT 1 FROM flash_sales
           WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
             AND (stock_limit IS NULL OR sold_count + reserved_count < stock_limit)
         ))`,
      )
      .bind(
        crypto.randomUUID(),
        input.customerId,
        input.amount,
        input.amount,
        reference,
        input.description,
        input.customerId,
        input.amount,
        input.orderId,
        input.customerId,
        input.voucherCode,
        input.voucherCode,
        now,
        now,
        input.flashSaleId,
        input.flashSaleId,
        now,
        now,
      ),
    db
      .prepare(
        `UPDATE customer_users SET balance = (
        SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
        FROM wallet_transactions WHERE customer_id = ?
       ), updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`,
      )
      .bind(input.customerId, input.customerId, reference),
  ];
  if (input.voucherCode) {
    statements.push(db.prepare(
      `UPDATE discount_vouchers SET used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP
       WHERE code = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
         AND (usage_limit IS NULL OR used_count + reserved_count < usage_limit)
         AND EXISTS (SELECT 1 FROM orders WHERE id = ? AND payment_status = 'pending')
         AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`,
    ).bind(input.voucherCode, now, now, input.orderId, reference));
  }
  if (input.flashSaleId) {
    statements.push(db.prepare(
      `UPDATE flash_sales SET sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
         AND (stock_limit IS NULL OR sold_count + reserved_count < stock_limit)
         AND EXISTS (SELECT 1 FROM orders WHERE id = ? AND payment_status = 'pending')
         AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`,
    ).bind(input.flashSaleId, now, now, input.orderId, reference));
  }
  statements.push(db.prepare(
    `UPDATE orders SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'pending'
       AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`,
  ).bind(
    input.fulfillmentType === "manual" ? "manual_pending" : "processing",
    input.orderId,
    reference,
  ));
  statements.push(db.prepare(
    `INSERT OR IGNORE INTO order_events (order_id, source, event_id, status, payload_json)
     SELECT ?, 'wallet', ?, 'paid', ?
     WHERE EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`,
  ).bind(
    input.orderId,
    `wallet-${input.orderId}`,
    JSON.stringify({ amount: input.amount }),
    reference,
  ));
  await db.batch(statements);
  const transaction = await db
    .prepare(
      "SELECT balance_after FROM wallet_transactions WHERE reference = ? LIMIT 1",
    )
    .bind(reference)
    .first<{ balance_after: number }>();
  if (!transaction) {
    const balance = await db.prepare(
      `SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance
       FROM wallet_transactions WHERE customer_id = ?`,
    ).bind(input.customerId).first<{ balance: number }>();
    if (Number(balance?.balance ?? 0) < input.amount) {
      throw new WalletSettlementError("Saldo tidak cukup. Silakan top up saldo terlebih dahulu.");
    }
    throw new WalletSettlementError("Promo baru saja habis atau pesanan sudah diproses. Muat ulang checkout.");
  }
  return transaction.balance_after;
}

export async function getWalletOrderBalance(orderId: string) {
  const row = await getD1().prepare(
    "SELECT balance_after FROM wallet_transactions WHERE reference = ? LIMIT 1",
  ).bind(`order:${orderId}`).first<{ balance_after: number }>();
  return row?.balance_after ?? null;
}
