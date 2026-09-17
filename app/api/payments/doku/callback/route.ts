import { getD1 } from "@/db";
import { parseDokuCheckoutNotification, validateDokuCheckoutNotification } from "@/lib/server/doku-checkout";
import { recordExternalPaymentEvent } from "@/lib/server/external-payments";
import { applyPendingExternalPaymentStatus } from "@/lib/server/payment-transition";
import { fulfillAutomaticOrder, getOrderByReference } from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { notifyOrderFulfillmentSuccessById, notifyWalletTopupSuccessById } from "@/lib/server/transaction-notifications";
import { applyExternalWalletTopup, getExternalWalletTopup } from "@/lib/server/wallet-external";

export const dynamic = "force-dynamic";

type Routing = {
  payment_gateway: string | null;
  payment_gateway_mode: string | null;
  payment_gateway_environment: "sandbox" | "production" | null;
};

export async function GET() {
  return Response.json({ ok: true, service: "doku-checkout-notification", method: "POST" }, { headers: { "Cache-Control": "no-store" } });
}

export async function POST(request: Request) {
  const rawBody = await request.text();
  let payload: Record<string, unknown>;
  try { payload = JSON.parse(rawBody) as Record<string, unknown>; }
  catch { return Response.json({ error: "Payload callback DOKU tidak valid." }, { status: 400 }); }

  const notification = parseDokuCheckoutNotification(payload);
  if (!notification.referenceId) return Response.json({ error: "Invoice DOKU tidak ada." }, { status: 400 });
  const order = await getOrderByReference(notification.referenceId);
  const topup = order ? null : await getExternalWalletTopup(notification.referenceId, "doku");
  if (!order && !topup) return Response.json({ ok: true });

  const routing = order
    ? await getD1().prepare("SELECT payment_gateway, payment_gateway_mode, payment_gateway_environment FROM orders WHERE id = ? LIMIT 1")
      .bind(order.id).first<Routing>()
    : { payment_gateway: topup?.payment_gateway ?? null, payment_gateway_mode: topup?.payment_gateway_mode ?? null, payment_gateway_environment: topup?.gateway_environment ?? null };
  if (routing?.payment_gateway !== "doku" || routing.payment_gateway_mode !== "checkout" || !routing.payment_gateway_environment) return Response.json({ ok: true });

  const valid = await validateDokuCheckoutNotification({
    rawBody,
    requestTarget: new URL(request.url).pathname,
    clientId: request.headers.get("client-id"),
    requestId: request.headers.get("request-id"),
    requestTimestamp: request.headers.get("request-timestamp"),
    receivedSignature: request.headers.get("signature"),
    environment: routing.payment_gateway_environment,
  });
  if (!valid) return Response.json({ error: "Signature callback DOKU tidak valid." }, { status: 401 });

  if (notification.status === "pending") return Response.json({ ok: true });
  if (topup) {
    const result = await applyExternalWalletTopup({ referenceId: notification.referenceId, gateway: "doku", status: notification.status, callbackAmount: notification.amount });
    if (result.credited) await notifyWalletTopupSuccessById(topup.id, notification.referenceId).catch(() => undefined);
    return Response.json({ ok: true });
  }
  if (!order) return Response.json({ ok: true });
  if (notification.status === "paid" && (!Number.isFinite(notification.amount) || notification.amount !== order.total)) return Response.json({ ok: true });

  const eventId = request.headers.get("request-id") || `checkout-${crypto.randomUUID()}`;
  await recordExternalPaymentEvent({ orderId: order.id, gateway: "doku", eventId, status: notification.status, payload });
  const firstPaid = await applyPendingExternalPaymentStatus(order, notification.status);
  if (firstPaid && order.fulfillment_type === "automatic") {
    await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
    await notifyOrderFulfillmentSuccessById(order.id).catch(() => undefined);
  }
  return Response.json({ ok: true });
}
