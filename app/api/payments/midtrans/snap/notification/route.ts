import { hashHex } from "@/lib/server/crypto";
import { recordExternalPaymentEvent } from "@/lib/server/external-payments";
import { mapMidtransSnapStatus, verifyMidtransSnapNotification } from "@/lib/server/midtrans-snap";
import { fulfillAutomaticOrder, getOrderByReference } from "@/lib/server/orders";
import { applyExternalPaymentEvent } from "@/lib/server/payment-transition";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import {
  notifyOrderFulfillmentSuccessById,
  notifyWalletTopupSuccessById,
} from "@/lib/server/transaction-notifications";
import { applyExternalWalletTopup, getExternalWalletTopup } from "@/lib/server/wallet-external";

export const dynamic = "force-dynamic";

type Notification = {
  order_id?: string;
  status_code?: string;
  gross_amount?: string;
  signature_key?: string;
  transaction_status?: string;
  fraud_status?: string;
  transaction_id?: string;
};

function clean(value: unknown) {
  return typeof value === "string" ? value.trim() : "";
}

export async function GET() {
  return Response.json(
    { ok: true, service: "midtrans-snap-notification", method: "POST" },
    { headers: { "Cache-Control": "no-store" } },
  );
}

export async function POST(request: Request) {
  const rawBody = await request.text();
  let body: Notification;
  try {
    body = JSON.parse(rawBody) as Notification;
  } catch {
    return Response.json({ error: "Payload Midtrans tidak valid." }, { status: 400 });
  }

  const referenceId = clean(body.order_id);
  const statusCode = clean(body.status_code);
  const grossAmount = clean(body.gross_amount);
  const signatureKey = clean(body.signature_key);
  if (!referenceId || !statusCode || !grossAmount || !signatureKey) {
    return Response.json({ error: "Payload Midtrans tidak lengkap." }, { status: 400 });
  }

  try {
    const order = await getOrderByReference(referenceId);
    const walletTopup = order ? null : await getExternalWalletTopup(referenceId, "midtrans");
    const environment = order
      ? ((order as unknown as { payment_gateway_environment?: "sandbox" | "production" | null }).payment_gateway_environment ?? null)
      : walletTopup?.gateway_environment ?? null;
    const mode = order
      ? ((order as unknown as { payment_gateway_mode?: string | null }).payment_gateway_mode ?? null)
      : walletTopup?.payment_gateway_mode ?? null;
    const gateway = order
      ? ((order as unknown as { payment_gateway?: string | null }).payment_gateway ?? null)
      : walletTopup?.payment_gateway ?? null;

    if (!order && !walletTopup) return Response.json({ ok: true });
    if (gateway !== "midtrans" || mode !== "snap" || !environment) {
      return Response.json({ ok: true });
    }

    const valid = await verifyMidtransSnapNotification({
      orderId: referenceId,
      statusCode,
      grossAmount,
      signatureKey,
      environment,
    });
    if (!valid) return Response.json({ error: "Signature Midtrans tidak valid." }, { status: 401 });

    const status = mapMidtransSnapStatus(clean(body.transaction_status), clean(body.fraud_status) || null);
    const callbackAmount = Number(grossAmount);
    if (status === "paid" && (!Number.isFinite(callbackAmount) || callbackAmount <= 0)) {
      return Response.json({ error: "Nominal Midtrans tidak valid." }, { status: 400 });
    }

    if (walletTopup) {
      const result = status === "ignore"
        ? { found: true, credited: false }
        : await applyExternalWalletTopup({
            referenceId,
            gateway: "midtrans",
            status,
            callbackAmount,
            authoritativePaid: status === "paid",
          });
      if (result.credited) {
        await notifyWalletTopupSuccessById(walletTopup.id, referenceId).catch((error) =>
          console.error("Notifikasi top up Midtrans gagal:", error),
        );
      }
      return Response.json({ ok: true });
    }

    if (!order) return Response.json({ ok: true });
    if (status === "paid" && callbackAmount !== order.total) {
      return Response.json({ error: "Nominal Midtrans tidak sesuai." }, { status: 400 });
    }

    const eventId = clean(body.transaction_id) || `snap-${hashHex("sha256", rawBody)}`;

    if (status === "ignore") {
      await recordExternalPaymentEvent({
        orderId: order.id,
        gateway: "midtrans",
        eventId,
        status,
        payload: body,
      });
    } else {
      const transition = await applyExternalPaymentEvent({
        order,
        source: "midtrans",
        eventId,
        status,
        payload: body,
        authoritativePaid: status === "paid",
      });
      if (transition.firstPaid && order.fulfillment_type === "automatic") {
        await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi pesanan Midtrans Snap gagal:", error),
        );
      }
    }

    return Response.json({ ok: true });
  } catch (error) {
    console.error("Callback Midtrans Snap gagal:", error);
    return Response.json(
      { error: error instanceof Error ? error.message : "Callback Midtrans Snap gagal diproses." },
      { status: 500 },
    );
  }
}
