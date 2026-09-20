import { getD1 } from "@/db";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";
import { releaseExternalPromotion } from "@/lib/server/promotions";
import type { RoutedPaymentMode } from "@/lib/server/payment-router";

export type ExternalPaymentArtifacts = {
  gateway: PaymentGatewayName;
  mode: RoutedPaymentMode | null;
  environment: "sandbox" | "production" | null;
  requestId: string;
  referenceNo: string | null;
  paymentNo: string | null;
  qrContent: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  total: number;
};

export async function updateExternalPayment(input: {
  referenceId: string;
  gateway: PaymentGatewayName;
  mode: RoutedPaymentMode;
  environment: "sandbox" | "production" | null;
  requestId: string;
  referenceNo: string | null;
  paymentNo: string | null;
  qrContent: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  total: number;
  paymentName?: string | null;
}) {
  await getD1().prepare(`UPDATE orders SET
    payment_gateway = ?,
    payment_gateway_mode = ?,
    payment_gateway_environment = ?,
    gateway_request_id = ?,
    gateway_reference_no = ?,
    gateway_payment_no = ?,
    gateway_qr_content = ?,
    gateway_payment_url = ?,
    gateway_expired_at = ?,
    gateway_status_checked_at = NULL,
    total = ?,
    updated_at = CURRENT_TIMESTAMP
    WHERE reference_id = ?`)
    .bind(
      input.gateway,
      input.mode,
      input.environment,
      input.requestId,
      input.referenceNo,
      input.paymentNo,
      input.qrContent,
      input.paymentUrl,
      input.expiredAt,
      input.total,
      input.referenceId,
    ).run();
}

export async function recordExternalPaymentEvent(input: {
  orderId: string;
  gateway: PaymentGatewayName;
  eventId: string;
  status: string;
  payload: unknown;
}) {
  return getD1().prepare(`INSERT OR IGNORE INTO order_events (order_id, source, event_id, status, payload_json)
    VALUES (?, ?, ?, ?, ?)`)
    .bind(input.orderId, input.gateway, input.eventId, input.status, JSON.stringify(input.payload))
    .run();
}

export function externalArtifactsFromOrder(order: Record<string, unknown>) {
  const gateway = order.payment_gateway === "midtrans" || order.payment_gateway === "doku"
    ? order.payment_gateway
    : null;
  const mode = typeof order.payment_gateway_mode === "string" ? order.payment_gateway_mode : null;
  return {
    gateway,
    mode,
    requestId: String(order.gateway_request_id || ""),
    referenceNo: String(order.gateway_reference_no || "") || null,
    paymentNo: String(order.gateway_payment_no || "") || null,
    qrContent: String(order.gateway_qr_content || "") || null,
    paymentUrl: String(order.gateway_payment_url || "") || null,
    expiredAt: String(order.gateway_expired_at || "") || null,
  };
}


export async function expireConfirmedMissingMidtransOrder(orderId: string) {
  const db = getD1();
  const result = await db.prepare(`
    UPDATE orders
    SET payment_status = 'expired',
        provider_message = COALESCE(provider_message, 'Transaksi tidak ditemukan di Midtrans setelah batas verifikasi.'),
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
      AND payment_status = 'pending'
      AND payment_gateway = 'midtrans'
      AND gateway_request_id IS NULL
      AND created_at <= datetime('now', '-70 minutes')
  `).bind(orderId).run();
  const changed = Number(result.meta.changes ?? 0) > 0;
  if (changed) await releaseExternalPromotion(orderId);
  return changed;
}

export async function expireUninitializedExternalOrders(limit = 100) {
  const db = getD1();
  const safeLimit = Math.min(Math.max(limit, 1), 500);
  const rows = await db.prepare(`
    SELECT id
    FROM orders
    WHERE payment_status = 'pending'
      AND payment_method <> 'wallet'
      AND external_checkout_key IS NOT NULL
      AND (payment_gateway IS NULL OR payment_gateway <> 'midtrans')
      AND gateway_request_id IS NULL
      AND created_at <= datetime('now', '-70 minutes')
    ORDER BY created_at ASC
    LIMIT ?
  `).bind(safeLimit).all<{ id: string }>();

  let expired = 0;
  for (const row of rows.results) {
    const result = await db.prepare(`
      UPDATE orders
      SET payment_status = 'expired',
          provider_message = COALESCE(provider_message, 'Pembuatan pembayaran tidak dapat dipastikan dan melewati batas aman.'),
          updated_at = CURRENT_TIMESTAMP
      WHERE id = ? AND payment_status = 'pending'
        AND gateway_request_id IS NULL
        AND doku_request_id IS NULL
    `).bind(row.id).run();
    if (Number(result.meta.changes ?? 0) > 0) {
      expired += 1;
      await releaseExternalPromotion(row.id);
    }
  }
  return { expired };
}
