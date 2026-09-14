import { getD1 } from "@/db";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";

export type ExternalPaymentArtifacts = {
  gateway: PaymentGatewayName;
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
  const db = getD1();
  if (input.gateway === "doku") {
    await db.prepare(`UPDATE orders SET
      payment_gateway = 'doku',
      payment_gateway_environment = ?,
      gateway_request_id = ?,
      gateway_reference_no = ?,
      gateway_payment_no = ?,
      gateway_qr_content = ?,
      gateway_payment_url = ?,
      gateway_expired_at = ?,
      gateway_status_checked_at = NULL,
      doku_environment = ?,
      doku_request_id = ?,
      doku_token_id = NULL,
      doku_reference_no = ?,
      doku_payment_no = ?,
      doku_qr_content = ?,
      doku_payment_name = ?,
      doku_payment_url = ?,
      doku_expired_at = ?,
      doku_status_checked_at = NULL,
      admin_fee = 0,
      total = ?,
      updated_at = CURRENT_TIMESTAMP
      WHERE reference_id = ?`)
      .bind(
        input.environment,
        input.requestId,
        input.referenceNo,
        input.paymentNo,
        input.qrContent,
        input.paymentUrl,
        input.expiredAt,
        input.environment,
        input.requestId,
        input.referenceNo,
        input.paymentNo,
        input.qrContent,
        input.paymentName ?? null,
        input.paymentUrl,
        input.expiredAt,
        input.total,
        input.referenceId,
      ).run();
    return;
  }

  await db.prepare(`UPDATE orders SET
    payment_gateway = 'midtrans',
    payment_gateway_environment = ?,
    gateway_request_id = ?,
    gateway_reference_no = ?,
    gateway_payment_no = ?,
    gateway_qr_content = ?,
    gateway_payment_url = ?,
    gateway_expired_at = ?,
    gateway_status_checked_at = NULL,
    admin_fee = 0,
    total = ?,
    updated_at = CURRENT_TIMESTAMP
    WHERE reference_id = ?`)
    .bind(
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
    : order.doku_request_id
      ? "doku"
      : null;
  return {
    gateway,
    requestId: String(order.gateway_request_id || order.doku_request_id || ""),
    referenceNo: String(order.gateway_reference_no || order.doku_reference_no || "") || null,
    paymentNo: String(order.gateway_payment_no || order.doku_payment_no || "") || null,
    qrContent: String(order.gateway_qr_content || order.doku_qr_content || "") || null,
    paymentUrl: String(order.gateway_payment_url || order.doku_payment_url || "") || null,
    expiredAt: String(order.gateway_expired_at || order.doku_expired_at || "") || null,
  };
}
