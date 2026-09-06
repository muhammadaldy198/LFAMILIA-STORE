import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";

export type WalletSettings = {
  minTopup: number;
  ipaymuTopupEnabled: boolean;
  ipaymuCheckoutEnabled: boolean;
};

const fallbackSettings: WalletSettings = {
  minTopup: 10_000,
  ipaymuTopupEnabled: false,
  ipaymuCheckoutEnabled: false,
};

export async function readWalletSettings(): Promise<WalletSettings> {
  try {
    await ensureLegacyDatabaseColumns();
    const row = await getD1()
      .prepare(`SELECT min_topup, ipaymu_topup_enabled, ipaymu_checkout_enabled
        FROM wallet_settings WHERE id = 1`)
      .first<{
        min_topup: number;
        ipaymu_topup_enabled: number;
        ipaymu_checkout_enabled: number;
      }>();
    if (!row) return fallbackSettings;
    return {
      minTopup: row.min_topup,
      ipaymuTopupEnabled: Boolean(row.ipaymu_topup_enabled),
      ipaymuCheckoutEnabled: Boolean(row.ipaymu_checkout_enabled),
    };
  } catch {
    return fallbackSettings;
  }
}

export async function saveWalletSettings(input: WalletSettings) {
  await ensureLegacyDatabaseColumns();
  await getD1()
    .prepare(
      `INSERT INTO wallet_settings (id, min_topup, ipaymu_topup_enabled, ipaymu_checkout_enabled, updated_at)
       VALUES (1, ?, ?, ?, CURRENT_TIMESTAMP)
       ON CONFLICT(id) DO UPDATE SET
         min_topup = excluded.min_topup,
         ipaymu_topup_enabled = excluded.ipaymu_topup_enabled,
         ipaymu_checkout_enabled = excluded.ipaymu_checkout_enabled,
         updated_at = CURRENT_TIMESTAMP`,
    )
    .bind(
      input.minTopup,
      input.ipaymuTopupEnabled ? 1 : 0,
      input.ipaymuCheckoutEnabled ? 1 : 0,
    )
    .run();
}

export async function createIpaymuWalletTopup(input: {
  customerId: string;
  amount: number;
  name: string;
  paymentMethod: string;
  paymentChannel: string;
  referenceId: string;
}) {
  await ensureLegacyDatabaseColumns();
  const id = crypto.randomUUID();
  await getD1()
    .prepare(
      `INSERT INTO wallet_topups (id, customer_id, amount, sender_name, payment_method, proof_url, source, reference_id)
       VALUES (?, ?, ?, ?, ?, '', 'ipaymu', ?)`,
    )
    .bind(
      id,
      input.customerId,
      input.amount,
      input.name,
      `${input.paymentMethod}:${input.paymentChannel}`,
      input.referenceId,
    )
    .run();
  return id;
}

export async function updateIpaymuWalletTopup(input: {
  referenceId: string;
  transactionId: string | null;
  paymentNo: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  fee: number;
  total: number;
}) {
  await ensureLegacyDatabaseColumns();
  await getD1()
    .prepare(
      `UPDATE wallet_topups SET ipaymu_transaction_id = ?, ipaymu_payment_no = ?, ipaymu_payment_name = ?,
       ipaymu_payment_url = ?, ipaymu_expired_at = ?, payment_fee = ?, payment_total = ?,
       updated_at = CURRENT_TIMESTAMP WHERE reference_id = ? AND source = 'ipaymu'`,
    )
    .bind(
      input.transactionId,
      input.paymentNo,
      input.paymentName,
      input.paymentUrl,
      input.expiredAt,
      input.fee,
      input.total,
      input.referenceId,
    )
    .run();
}

export async function getIpaymuWalletTopup(referenceId: string) {
  await ensureLegacyDatabaseColumns();
  return getD1()
    .prepare(
      `SELECT id, customer_id, amount, status, ipaymu_transaction_id FROM wallet_topups
       WHERE reference_id = ? AND source = 'ipaymu' LIMIT 1`,
    )
    .bind(referenceId)
    .first<{
      id: string;
      customer_id: string;
      amount: number;
      status: string;
      ipaymu_transaction_id: string | null;
    }>();
}

export async function applyIpaymuWalletTopup(input: {
  referenceId: string;
  status: "paid" | "pending" | "expired" | "failed";
  transactionId: string | null;
  callbackAmount: number;
}) {
  const topup = await getIpaymuWalletTopup(input.referenceId);
  if (!topup) return { found: false, credited: false };
  if (
    topup.ipaymu_transaction_id &&
    input.transactionId &&
    topup.ipaymu_transaction_id !== input.transactionId
  )
    return { found: true, credited: false, ignored: "transaction_mismatch" };
  if (
    input.status === "paid" &&
    input.callbackAmount > 0 &&
    input.callbackAmount < topup.amount
  )
    return { found: true, credited: false, ignored: "amount_mismatch" };

  const db = getD1();
  if (input.status === "paid") {
    const reference = `topup:${topup.id}`;
    await db.batch([
      db
        .prepare(
          `INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
           SELECT ?, t.customer_id, 'credit', t.amount, ledger.balance, ledger.balance + t.amount, ?, ?
           FROM wallet_topups t CROSS JOIN (
             SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance
             FROM wallet_transactions WHERE customer_id = ?
           ) ledger WHERE t.id = ? AND t.status = 'pending'`,
        )
        .bind(
          crypto.randomUUID(),
          reference,
          `Top up otomatis iPaymu ${topup.id.slice(0, 8).toUpperCase()}`,
          topup.customer_id,
          topup.id,
        ),
      db
        .prepare(
          "UPDATE wallet_topups SET status = 'approved', reviewed_by = 'ipaymu-callback', reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'",
        )
        .bind(topup.id),
      db
        .prepare(
          "UPDATE customer_users SET balance = (SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) FROM wallet_transactions WHERE customer_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        )
        .bind(topup.customer_id, topup.customer_id),
    ]);
    return { found: true, credited: topup.status === "pending" };
  }

  if (input.status === "expired" || input.status === "failed") {
    await db
      .prepare(
        "UPDATE wallet_topups SET status = 'rejected', admin_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'",
      )
      .bind(
        input.status === "expired"
          ? "Pembayaran iPaymu kedaluwarsa."
          : "Pembayaran iPaymu gagal.",
        topup.id,
      )
      .run();
  }
  return { found: true, credited: false };
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

export async function spendWallet(input: {
  customerId: string;
  orderId: string;
  amount: number;
  description: string;
}) {
  const db = getD1();
  const reference = `order:${input.orderId}`;
  await db.batch([
    db
      .prepare(
        `INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
       SELECT ?, ?, 'debit', ?, ledger.balance, ledger.balance - ?, ?, ?
       FROM (
         SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance
         FROM wallet_transactions WHERE customer_id = ?
       ) ledger
       WHERE ledger.balance >= ?`,
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
      ),
    db
      .prepare(
        `UPDATE customer_users SET balance = (
        SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
        FROM wallet_transactions WHERE customer_id = ?
       ), updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
      )
      .bind(input.customerId, input.customerId),
  ]);
  const transaction = await db
    .prepare(
      "SELECT balance_after FROM wallet_transactions WHERE reference = ? LIMIT 1",
    )
    .bind(reference)
    .first<{ balance_after: number }>();
  if (!transaction)
    throw new Error("Saldo tidak cukup. Silakan top up saldo terlebih dahulu.");
  return transaction.balance_after;
}
