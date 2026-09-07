import {
  mapDokuStatus,
  validateDokuNotification,
} from "@/lib/server/doku";
import {
  applyPaymentStatus,
  fulfillAutomaticOrder,
  getOrderByReference,
  recordOrderEvent,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import {
  notifyOrderFulfillmentSuccessById,
  notifyWalletTopupSuccessById,
} from "@/lib/server/transaction-notifications";
import {
  applyDokuWalletTopup,
  getDokuWalletTopup,
} from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

function object(value: unknown) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};
}

export async function GET() {
  return Response.json(
    { ok: true, service: "doku-notification", method: "POST" },
    { headers: { "Cache-Control": "no-store" } },
  );
}

export async function POST(request: Request) {
  const rawBody = await request.text();
  let payload: Record<string, unknown>;
  try {
    payload = JSON.parse(rawBody) as Record<string, unknown>;
  } catch {
    return Response.json({ error: "Payload callback DOKU tidak valid." }, { status: 400 });
  }

  try {
    const target = new URL(request.url).pathname;
    const validation = validateDokuNotification({
      rawBody,
      requestTarget: target,
      clientId: request.headers.get("client-id"),
      requestId: request.headers.get("request-id"),
      requestTimestamp: request.headers.get("request-timestamp"),
      receivedSignature: request.headers.get("signature"),
    });
    if (!validation.valid) {
      return Response.json({ error: "Signature callback DOKU tidak valid." }, { status: 401 });
    }

    const orderPayload = object(payload.order);
    const transaction = object(payload.transaction);
    const referenceId = String(orderPayload.invoice_number ?? "").trim();
    if (!referenceId) {
      return Response.json({ error: "Invoice number DOKU tidak ada." }, { status: 400 });
    }

    const status = mapDokuStatus(payload);
    const callbackAmount = Number(orderPayload.amount ?? 0);
    const originalRequestId = transaction.original_request_id == null
      ? null
      : String(transaction.original_request_id);
    const eventId = request.headers.get("request-id") || crypto.randomUUID();

    const walletTopup = await getDokuWalletTopup(referenceId);
    if (walletTopup) {
      const result = await applyDokuWalletTopup({
        referenceId,
        status,
        originalRequestId,
        callbackAmount,
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(walletTopup.id, referenceId).catch(
          (error) => console.error("Notifikasi top up DOKU gagal:", error),
        );
      }
      return Response.json({ ok: true, walletTopup: result });
    }

    const order = await getOrderByReference(referenceId);
    if (!order) return Response.json({ ok: true, ignored: "order_not_found" });

    if (
      order.doku_request_id &&
      originalRequestId &&
      order.doku_request_id !== originalRequestId
    ) {
      return Response.json({ ok: true, ignored: "request_mismatch" });
    }

    if (
      status === "paid" &&
      (!Number.isFinite(callbackAmount) || callbackAmount !== order.total)
    ) {
      return Response.json({ ok: true, ignored: "amount_mismatch" });
    }

    await recordOrderEvent({
      orderId: order.id,
      source: "doku",
      eventId,
      status,
      payload,
    });

    const firstPaid = await applyPaymentStatus(order, status);
    if (firstPaid && order.fulfillment_type === "automatic") {
      await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
      await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
        console.error("Notifikasi pesanan selesai DOKU gagal:", error),
      );
    }

    return Response.json({ ok: true });
  } catch (error) {
    return Response.json(
      {
        error:
          error instanceof Error
            ? error.message
            : "Callback DOKU gagal diproses.",
      },
      { status: 503 },
    );
  }
}
