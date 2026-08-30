import { getD1 } from "@/db";

export type WalletSettings = {
  isEnabled: boolean;
  methodName: string;
  accountName: string;
  accountNumber: string;
  instructions: string;
  minTopup: number;
};

const fallbackSettings: WalletSettings = {
  isEnabled: false,
  methodName: "Transfer Bank",
  accountName: "",
  accountNumber: "",
  instructions: "Kirim sesuai nominal lalu unggah bukti pembayaran.",
  minTopup: 10_000,
};

export async function readWalletSettings(): Promise<WalletSettings> {
  try {
    const row = await getD1().prepare("SELECT * FROM wallet_settings WHERE id = 1").first<{
      is_enabled: number; method_name: string; account_name: string; account_number: string; instructions: string; min_topup: number;
    }>();
    if (!row) return fallbackSettings;
    return {
      isEnabled: Boolean(row.is_enabled),
      methodName: row.method_name,
      accountName: row.account_name,
      accountNumber: row.account_number,
      instructions: row.instructions,
      minTopup: row.min_topup,
    };
  } catch {
    return fallbackSettings;
  }
}

export async function saveWalletSettings(input: WalletSettings) {
  await getD1().prepare(
    `INSERT INTO wallet_settings (id, is_enabled, method_name, account_name, account_number, instructions, min_topup, updated_at)
     VALUES (1, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
     ON CONFLICT(id) DO UPDATE SET is_enabled = excluded.is_enabled, method_name = excluded.method_name,
      account_name = excluded.account_name, account_number = excluded.account_number,
      instructions = excluded.instructions, min_topup = excluded.min_topup, updated_at = CURRENT_TIMESTAMP`,
  ).bind(input.isEnabled ? 1 : 0, input.methodName, input.accountName, input.accountNumber, input.instructions, input.minTopup).run();
}

export async function createWalletTopup(input: { customerId: string; amount: number; senderName: string; paymentMethod: string; proofUrl: string }) {
  const id = crypto.randomUUID();
  await getD1().prepare(
    `INSERT INTO wallet_topups (id, customer_id, amount, sender_name, payment_method, proof_url)
     VALUES (?, ?, ?, ?, ?, ?)`,
  ).bind(id, input.customerId, input.amount, input.senderName, input.paymentMethod, input.proofUrl).run();
  return id;
}

export async function listWalletTopups(limit = 200) {
  const result = await getD1().prepare(
    `SELECT t.id, t.customer_id, t.amount, t.sender_name, t.payment_method, t.proof_url,
      t.status, t.admin_notes, t.reviewed_by, t.reviewed_at, t.created_at,
      u.name AS customer_name, u.email AS customer_email, u.balance AS customer_balance
     FROM wallet_topups t JOIN customer_users u ON u.id = t.customer_id
     ORDER BY CASE t.status WHEN 'pending' THEN 0 ELSE 1 END, t.created_at DESC LIMIT ?`,
  ).bind(Math.min(Math.max(limit, 1), 500)).all();
  return result.results;
}

export async function reviewWalletTopup(input: { id: string; decision: "approved" | "rejected"; adminEmail: string; notes?: string }) {
  const db = getD1();
  const topup = await db.prepare("SELECT id, customer_id, amount, status FROM wallet_topups WHERE id = ? LIMIT 1")
    .bind(input.id).first<{ id: string; customer_id: string; amount: number; status: string }>();
  if (!topup) throw new Error("Permintaan top up tidak ditemukan.");
  if (topup.status !== "pending") throw new Error("Permintaan top up ini sudah ditinjau.");
  if (input.decision === "rejected") {
    await db.prepare("UPDATE wallet_topups SET status = 'rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'")
      .bind(input.notes || null, input.adminEmail, input.id).run();
    return;
  }

  const reference = `topup:${topup.id}`;
  await db.batch([
    db.prepare(
      `INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
       SELECT ?, t.customer_id, 'credit', t.amount, ledger.balance, ledger.balance + t.amount, ?, ?
       FROM wallet_topups t
       CROSS JOIN (
         SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance
         FROM wallet_transactions WHERE customer_id = ?
       ) ledger
       WHERE t.id = ? AND t.status = 'pending'`,
    ).bind(crypto.randomUUID(), reference, `Top up saldo ${topup.id.slice(0, 8).toUpperCase()}`, topup.customer_id, topup.id),
    db.prepare("UPDATE wallet_topups SET status = 'approved', admin_notes = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'")
      .bind(input.notes || null, input.adminEmail, topup.id),
    db.prepare(
      `UPDATE customer_users SET balance = (
        SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
        FROM wallet_transactions WHERE customer_id = ?
       ), updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    ).bind(topup.customer_id, topup.customer_id),
  ]);
}

export async function spendWallet(input: { customerId: string; orderId: string; amount: number; description: string }) {
  const db = getD1();
  const reference = `order:${input.orderId}`;
  await db.batch([
    db.prepare(
      `INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
       SELECT ?, ?, 'debit', ?, ledger.balance, ledger.balance - ?, ?, ?
       FROM (
         SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance
         FROM wallet_transactions WHERE customer_id = ?
       ) ledger
       WHERE ledger.balance >= ?`,
    ).bind(crypto.randomUUID(), input.customerId, input.amount, input.amount, reference, input.description, input.customerId, input.amount),
    db.prepare(
      `UPDATE customer_users SET balance = (
        SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
        FROM wallet_transactions WHERE customer_id = ?
       ), updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    ).bind(input.customerId, input.customerId),
  ]);
  const transaction = await db.prepare("SELECT balance_after FROM wallet_transactions WHERE reference = ? LIMIT 1").bind(reference).first<{ balance_after: number }>();
  if (!transaction) throw new Error("Saldo tidak cukup. Silakan top up saldo terlebih dahulu.");
  return transaction.balance_after;
}
