import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";

export type WalletSettings = {
  minTopup: number;
  midtransTopupEnabled: boolean;
  midtransCheckoutEnabled: boolean;
  ipaymuTopupEnabled: boolean;
  ipaymuCheckoutEnabled: boolean;
};

const fallbackSettings: WalletSettings = {
  minTopup: 10_000,
  midtransTopupEnabled: false,
  midtransCheckoutEnabled: false,
  ipaymuTopupEnabled: false,
  ipaymuCheckoutEnabled: false,
};

export async function readWalletSettings(): Promise<WalletSettings> {
  try {
    await ensureLegacyDatabaseColumns();
    const row = await getD1()
      .prepare(`SELECT min_topup, midtrans_topup_enabled, midtrans_checkout_enabled,
        ipaymu_topup_enabled, ipaymu_checkout_enabled FROM wallet_settings WHERE id = 1`)
      .first<{
        min_topup: number;
        midtrans_topup_enabled: number;
        midtrans_checkout_enabled: number;
        ipaymu_topup_enabled: number;
        ipaymu_checkout_enabled: number;
      }>();
    if (!row) return fallbackSettings;
    return {
      minTopup: row.min_topup,
      midtransTopupEnabled: Boolean(row.midtrans_topup_enabled),
      midtransCheckoutEnabled: Boolean(row.midtrans_checkout_enabled),
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
      `INSERT INTO wallet_settings (id, min_topup, midtrans_topup_enabled, midtrans_checkout_enabled, ipaymu_topup_enabled, ipaymu_checkout_enabled, updated_at)
       VALUES (1, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
       ON CONFLICT(id) DO UPDATE SET
         min_topup = excluded.min_topup,
         midtrans_topup_enabled = excluded.midtrans_topup_enabled,
         midtrans_checkout_enabled = excluded.midtrans_checkout_enabled,
         ipaymu_topup_enabled = excluded.ipaymu_topup_enabled,
         ipaymu_checkout_enabled = excluded.ipaymu_checkout_enabled,
         updated_at = CURRENT_TIMESTAMP`,
    )
    .bind(
      input.minTopup,
      input.midtransTopupEnabled ? 1 : 0,
      input.midtransCheckoutEnabled ? 1 : 0,
      input.ipaymuTopupEnabled ? 1 : 0,
      input.ipaymuCheckoutEnabled ? 1 : 0,
    )
    .run();
}

export async function createAutomaticWalletTopup(input: {
  customerId: string;
  amount: number;
  name: string;
  paymentMethod: string;
  paymentChannel: string;
  referenceId: string;
  gateway: "ipaymu" | "midtrans";
}) {
  await ensureLegacyDatabaseColumns();
  const id = crypto.randomUUID();
  await getD1()
    .prepare(
      `INSERT INTO wallet_topups (id, customer_id, amount, sender_name, payment_method, proof_url, source, reference_id)
       VALUES (?, ?, ?, ?, ?, '', ?, ?)`,
    )
    .bind(
      id,
      input.customerId,
      input.amount,
      input.name,
      `${input.paymentMethod}:${input.paymentChannel}`,
      input.gateway,
      input.referenceId,
    )
    .run();
  return id;
}

export async function switchAutomaticWalletTopupGateway(
  referenceId: string,
  gateway: "ipaymu" | "midtrans",
) {
  await ensureLegacyDatabaseColumns();
  const result = await getD1()
    .prepare(
      `UPDATE wallet_topups
       SET source = ?,
           midtrans_transaction_id = NULL,
           midtrans_payment_no = NULL,
           midtrans_payment_name = NULL,
           midtrans_payment_url = NULL,
           midtrans_expired_at = NULL,
           ipaymu_transaction_id = NULL,
           ipaymu_payment_no = NULL,
           ipaymu_payment_name = NULL,
           ipaymu_payment_url = NULL,
           ipaymu_expired_at = NULL,
           payment_fee = 0,
           payment_total = 0,
           updated_at = CURRENT_TIMESTAMP
       WHERE reference_id = ? AND status = 'pending'`,
    )
    .bind(gateway, referenceId)
    .run();
  return Number(result.meta.changes ?? 0) > 0;
}

export async function markAutomaticWalletTopupCreationFailed(
  referenceId: string,
  message: string,
) {
  await ensureLegacyDatabaseColumns();
  await getD1()
    .prepare(
      `UPDATE wallet_topups
       SET status = 'rejected',
           admin_notes = ?,
           updated_at = CURRENT_TIMESTAMP
       WHERE reference_id = ? AND status = 'pending'`,
    )
    .bind(message.slice(0, 500), referenceId)
    .run();
}

export async function createMidtransWalletTopup(input: {
  customerId: string;
  amount: number;
  name: string;
  paymentMethod: string;
  paymentChannel: string;
  referenceId: string;
}) {
  return createAutomaticWalletTopup({ ...input, gateway: "midtrans" });
}

export async function updateMidtransWalletTopup(input: {
  referenceId: string;
  mode: "snap";
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
      `UPDATE wallet_topups SET midtrans_transaction_id = ?, midtrans_payment_no = ?,
       midtrans_payment_name = ?, midtrans_payment_url = ?, midtrans_expired_at = ?,
       midtrans_mode = ?, payment_fee = ?, payment_total = ?, updated_at = CURRENT_TIMESTAMP
       WHERE reference_id = ? AND source = 'midtrans'`,
    )
    .bind(
      input.transactionId,
      input.paymentNo,
      input.paymentName,
      input.paymentUrl,
      input.expiredAt,
      input.mode,
      input.fee,
      input.total,
      input.referenceId,
    )
    .run();
}

export async function getMidtransWalletTopup(referenceId: string) {
  await ensureLegacyDatabaseColumns();
  return getD1()
    .prepare(
      `SELECT id, customer_id, amount, status, midtrans_transaction_id FROM wallet_topups
     WHERE reference_id = ? AND source = 'midtrans' LIMIT 1`,
    )
    .bind(referenceId)
    .first<{
      id: string;
      customer_id: string;
      amount: number;
      status: string;
      midtrans_transaction_id: string | null;
    }>();
}

export async function applyMidtransWalletTopup(input: {
  referenceId: string;
  status: "paid" | "pending" | "expired" | "failed";
  transactionId: string | null;
  callbackAmount: number;
}) {
  const topup = await getMidtransWalletTopup(input.referenceId);
  if (!topup) return { found: false, credited: false };
  if (
    topup.midtrans_transaction_id &&
    input.transactionId &&
    topup.midtrans_transaction_id !== input.transactionId
  )
    return { found: true, credited: false, ignored: "transaction_mismatch" };
  if (
    input.status === "paid" &&
    (!Number.isFinite(input.callbackAmount) ||
      input.callbackAmount <= 0 ||
      input.callbackAmount !== topup.amount)
  )
    return { found: true, credited: false, ignored: "amount_mismatch" };
  const db = getD1();
  if (input.status === "paid") {
    const reference = `topup:${topup.id}`;
    const results = await db.batch([
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
          `Top up otomatis Midtrans ${topup.id.slice(0, 8).toUpperCase()}`,
          topup.customer_id,
          topup.id,
        ),
      db
        .prepare(
          "UPDATE wallet_topups SET status = 'approved', reviewed_by = 'midtrans-callback', reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'",
        )
        .bind(topup.id),
      db
        .prepare(
          "UPDATE customer_users SET balance = (SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) FROM wallet_transactions WHERE customer_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        )
        .bind(topup.customer_id, topup.customer_id),
    ]);
    const inserted = Number(results[0]?.meta.changes ?? 0) > 0;
    const approved = Number(results[1]?.meta.changes ?? 0) > 0;
    return { found: true, credited: inserted && approved };
  }
  if (input.status === "expired" || input.status === "failed") {
    await db
      .prepare(
        "UPDATE wallet_topups SET status = 'rejected', admin_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'",
      )
      .bind(
        input.status === "expired"
          ? "Pembayaran Midtrans kedaluwarsa."
          : "Pembayaran Midtrans gagal.",
        topup.id,
      )
      .run();
  }
  return { found: true, credited: false };
}


export async function createIpaymuWalletTopup(input: {
  customerId: string;
  amount: number;
  name: string;
  paymentMethod: string;
  paymentChannel: string;
  referenceId: string;
}) {
  return createAutomaticWalletTopup({ ...input, gateway: "ipaymu" });
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
    (!Number.isFinite(input.callbackAmount) ||
      input.callbackAmount <= 0 ||
      input.callbackAmount !== topup.amount)
  )
    return { found: true, credited: false, ignored: "amount_mismatch" };

  const db = getD1();
  if (input.status === "paid") {
    const reference = `topup:${topup.id}`;
    const results = await db.batch([
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
    const inserted = Number(results[0]?.meta.changes ?? 0) > 0;
    const approved = Number(results[1]?.meta.changes ?? 0) > 0;
    return { found: true, credited: inserted && approved };
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
             AND (usage_limit IS NULL OR used_count < usage_limit)
         ))
         AND (? IS NULL OR EXISTS (
           SELECT 1 FROM flash_sales
           WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
             AND (stock_limit IS NULL OR sold_count < stock_limit)
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
         AND (usage_limit IS NULL OR used_count < usage_limit)
         AND EXISTS (SELECT 1 FROM orders WHERE id = ? AND payment_status = 'pending')
         AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`,
    ).bind(input.voucherCode, now, now, input.orderId, reference));
  }
  if (input.flashSaleId) {
    statements.push(db.prepare(
      `UPDATE flash_sales SET sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
         AND (stock_limit IS NULL OR sold_count < stock_limit)
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
      throw new Error("Saldo tidak cukup. Silakan top up saldo terlebih dahulu.");
    }
    throw new Error("Promo baru saja habis atau pesanan sudah diproses. Muat ulang checkout.");
  }
  return transaction.balance_after;
}

export async function getWalletOrderBalance(orderId: string) {
  const row = await getD1().prepare(
    "SELECT balance_after FROM wallet_transactions WHERE reference = ? LIMIT 1",
  ).bind(`order:${orderId}`).first<{ balance_after: number }>();
  return row?.balance_after ?? null;
}
