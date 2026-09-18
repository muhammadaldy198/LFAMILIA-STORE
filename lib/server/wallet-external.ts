import { getD1 } from "@/db";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";
import type { RoutedPaymentMode } from "@/lib/server/payment-router";

export type ExternalWalletTopup = {
  id: string;
  customer_id: string;
  amount: number;
  payment_fee: number;
  payment_total: number;
  payment_method: string;
  reference_id: string;
  status: string;
  payment_gateway: PaymentGatewayName | null;
  payment_gateway_mode: RoutedPaymentMode | null;
  gateway_environment: "sandbox" | "production" | null;
  gateway_request_id: string | null;
  gateway_reference_no: string | null;
  gateway_payment_no: string | null;
  gateway_qr_content: string | null;
  gateway_payment_name: string | null;
  gateway_payment_url: string | null;
  gateway_expired_at: string | null;
};

const columns = `id, customer_id, amount, payment_fee, payment_total, payment_method, reference_id, status,
  payment_gateway, payment_gateway_mode, gateway_environment, gateway_request_id,
  gateway_reference_no, gateway_payment_no, gateway_qr_content, gateway_payment_name,
  gateway_payment_url, gateway_expired_at`;

export async function findExternalTopupByKey(customerId: string, idempotencyKey: string) {
  return getD1().prepare(`SELECT ${columns} FROM wallet_topups
    WHERE customer_id = ? AND external_checkout_key = ? AND source IN ('doku','midtrans') LIMIT 1`)
    .bind(customerId, idempotencyKey).first<ExternalWalletTopup>();
}

export async function findMatchingExternalTopup(customerId: string, amount: number, paymentMethod: string) {
  return getD1().prepare(`SELECT ${columns} FROM wallet_topups
    WHERE customer_id = ? AND amount = ? AND payment_method = ?
      AND source IN ('doku','midtrans') AND status = 'pending'
    ORDER BY created_at DESC LIMIT 1`)
    .bind(customerId, amount, paymentMethod).first<ExternalWalletTopup>();
}

export async function insertExternalWalletTopup(input: {
  id: string;
  customerId: string;
  amount: number;
  paymentFee: number;
  paymentTotal: number;
  customerName: string;
  paymentMethodKey: string;
  referenceId: string;
  idempotencyKey: string | null;
  gateway: PaymentGatewayName;
  mode: RoutedPaymentMode;
  environment: "sandbox" | "production" | null;
}) {
  return getD1().prepare(`INSERT INTO wallet_topups (
      id, customer_id, amount, payment_fee, payment_total, sender_name, payment_method, proof_url,
      source, reference_id, external_checkout_key,
      payment_gateway, payment_gateway_mode, gateway_environment,
      doku_environment
    )
    SELECT ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?
    WHERE NOT EXISTS (
      SELECT 1 FROM wallet_topups
      WHERE customer_id = ? AND amount = ? AND payment_method = ?
        AND source IN ('doku','midtrans') AND status = 'pending'
    )`)
    .bind(
      input.id,
      input.customerId,
      input.amount,
      input.paymentFee,
      input.paymentTotal,
      input.customerName,
      input.paymentMethodKey,
      input.gateway,
      input.referenceId,
      input.idempotencyKey,
      input.gateway,
      input.mode,
      input.environment,
      input.gateway === "doku" ? input.environment : null,
      input.customerId,
      input.amount,
      input.paymentMethodKey,
    ).run();
}

export async function updateExternalWalletTopup(input: {
  referenceId: string;
  gateway: PaymentGatewayName;
  mode: RoutedPaymentMode;
  environment: "sandbox" | "production" | null;
  requestId: string;
  referenceNo: string | null;
  paymentNo: string | null;
  qrContent: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  total: number;
}) {
  const db = getD1();
  if (input.gateway === "doku") {
    await db.prepare(`UPDATE wallet_topups SET
      payment_gateway = 'doku', payment_gateway_mode = ?, gateway_environment = ?,
      gateway_request_id = ?, gateway_reference_no = ?, gateway_payment_no = ?,
      gateway_qr_content = ?, gateway_payment_name = ?, gateway_payment_url = ?, gateway_expired_at = ?,
      doku_environment = ?, doku_request_id = ?, doku_token_id = NULL,
      doku_reference_no = ?, doku_payment_no = ?, doku_qr_content = ?, doku_payment_name = ?,
      doku_payment_url = ?, doku_expired_at = ?, doku_status_checked_at = NULL,
      payment_total = ?, updated_at = CURRENT_TIMESTAMP
      WHERE reference_id = ? AND source = 'doku'`)
      .bind(
        input.mode, input.environment, input.requestId, input.referenceNo, input.paymentNo,
        input.qrContent, input.paymentName, input.paymentUrl, input.expiredAt,
        input.environment, input.requestId, input.referenceNo, input.paymentNo,
        input.qrContent, input.paymentName, input.paymentUrl, input.expiredAt,
        input.total, input.referenceId,
      ).run();
    return;
  }
  await db.prepare(`UPDATE wallet_topups SET
    payment_gateway = 'midtrans', payment_gateway_mode = ?, gateway_environment = ?,
    gateway_request_id = ?, gateway_reference_no = ?, gateway_payment_no = ?,
    gateway_qr_content = ?, gateway_payment_name = ?, gateway_payment_url = ?, gateway_expired_at = ?,
    payment_total = ?, updated_at = CURRENT_TIMESTAMP
    WHERE reference_id = ? AND source = 'midtrans'`)
    .bind(
      input.mode, input.environment, input.requestId, input.referenceNo, input.paymentNo,
      input.qrContent, input.paymentName, input.paymentUrl, input.expiredAt,
      input.total, input.referenceId,
    ).run();
}

export async function getExternalWalletTopup(referenceId: string, gateway?: PaymentGatewayName) {
  const filter = gateway ? " AND payment_gateway = ?" : "";
  const statement = getD1().prepare(`SELECT ${columns} FROM wallet_topups WHERE reference_id = ?${filter} LIMIT 1`);
  return (gateway ? statement.bind(referenceId, gateway) : statement.bind(referenceId)).first<ExternalWalletTopup>();
}

export async function markExternalWalletTopupCreationFailed(referenceId: string, message: string) {
  // A provider can accept a payment while our response/persistence fails. Keep
  // the top-up pending long enough for a signed callback to settle it instead
  // of turning a potentially payable transaction into an unrecoverable reject.
  await getD1().prepare(`UPDATE wallet_topups SET
      admin_notes = ?,
      gateway_expired_at = COALESCE(gateway_expired_at, datetime('now', '+70 minutes')),
      doku_expired_at = CASE
        WHEN payment_gateway = 'doku' THEN COALESCE(doku_expired_at, datetime('now', '+70 minutes'))
        ELSE doku_expired_at
      END,
      updated_at = CURRENT_TIMESTAMP
    WHERE reference_id = ? AND status = 'pending'`)
    .bind(`Status pembuatan pembayaran belum dapat dipastikan: ${message.slice(0, 420)}`, referenceId).run();
}

export async function expireUninitializedExternalWalletTopups() {
  return getD1().prepare(`UPDATE wallet_topups SET
      status = 'rejected',
      admin_notes = COALESCE(admin_notes, 'Pembayaran tidak selesai dibuat dan sudah melewati batas aman.'),
      updated_at = CURRENT_TIMESTAMP
    WHERE status = 'pending'
      AND source IN ('doku', 'midtrans')
      AND gateway_request_id IS NULL
      AND gateway_expired_at IS NOT NULL
      AND datetime(gateway_expired_at) <= datetime('now')`).run();
}

export async function applyExternalWalletTopup(input: {
  referenceId: string;
  gateway: PaymentGatewayName;
  status: "paid" | "pending" | "expired" | "failed";
  originalRequestId?: string | null;
  callbackAmount: number;
}) {
  const topup = await getExternalWalletTopup(input.referenceId, input.gateway);
  if (!topup) return { found: false, credited: false };
  if (topup.gateway_request_id && input.originalRequestId && topup.gateway_request_id !== input.originalRequestId) {
    return { found: true, credited: false, ignored: "request_mismatch" };
  }
  if (input.status === "paid" && (!Number.isFinite(input.callbackAmount) || input.callbackAmount <= 0 || input.callbackAmount !== (topup.payment_total || topup.amount))) {
    return { found: true, credited: false, ignored: "amount_mismatch" };
  }

  const db = getD1();
  if (input.status === "paid") {
    const reference = `topup:${topup.id}`;
    const results = await db.batch([
      db.prepare(`INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
        SELECT ?, t.customer_id, 'credit', t.amount, ledger.balance, ledger.balance + t.amount, ?, ?
        FROM wallet_topups t CROSS JOIN (
          SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance
          FROM wallet_transactions WHERE customer_id = ?
        ) ledger
        WHERE t.id = ? AND t.status = 'pending'
          AND (t.gateway_expired_at IS NULL OR datetime(t.gateway_expired_at) > datetime('now'))`)
        .bind(crypto.randomUUID(), reference, `Top up otomatis ${input.gateway.toUpperCase()} ${topup.id.slice(0, 8).toUpperCase()}`, topup.customer_id, topup.id),
      db.prepare(`UPDATE wallet_topups SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status = 'pending' AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`)
        .bind(`${input.gateway}-callback`, topup.id, reference),
      db.prepare(`UPDATE customer_users SET balance = (
          SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)
          FROM wallet_transactions WHERE customer_id = ?
        ), updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference = ?)`)
        .bind(topup.customer_id, topup.customer_id, reference),
    ]);
    const credited = Number(results[0]?.meta.changes ?? 0) > 0 && Number(results[1]?.meta.changes ?? 0) > 0;
    if (credited) return { found: true, credited: true };
    const expired = await db.prepare(`UPDATE wallet_topups SET status = 'rejected', admin_notes = 'Pembayaran kedaluwarsa.', updated_at = CURRENT_TIMESTAMP
      WHERE id = ? AND status = 'pending' AND gateway_expired_at IS NOT NULL AND datetime(gateway_expired_at) <= datetime('now')`)
      .bind(topup.id).run();
    return { found: true, credited: false, ignored: Number(expired.meta.changes ?? 0) > 0 ? "expired" : undefined };
  }

  if (input.status === "expired" || input.status === "failed") {
    await db.prepare("UPDATE wallet_topups SET status = 'rejected', admin_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'")
      .bind(input.status === "expired" ? "Pembayaran kedaluwarsa." : "Pembayaran gagal.", topup.id).run();
  }
  return { found: true, credited: false };
}
