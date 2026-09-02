import {
  applyPaymentStatus,
  fulfillAutomaticOrder,
  getOrderByReference,
  recordOrderEvent,
} from "@/lib/server/orders";
import {
  mapMidtransStatus,
  validateMidtransNotification,
} from "@/lib/server/midtrans";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import {
  applyMidtransWalletTopup,
  getMidtransWalletTopup,
} from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

export async function GET() {
  return Response.json(
    { ok: true, service: "midtrans-notification", method: "POST" },
    { headers: { "Cache-Control": "no-store" } },
  );
}

export async function POST(request: Request) {
  let payload: Record<string, unknown>;
  try {
    payload = (await request.json()) as Record<string, unknown>;
  } catch {
    return Response.json(
      { error: "Payload Midtrans tidak valid." },
      { status: 400 },
    );
  }
  try {
    const validation = validateMidtransNotification(payload);
    if (!validation.valid)
      return Response.json(
        { error: "Signature Midtrans tidak valid." },
        { status: 401 },
      );
    const status = mapMidtransStatus(payload);
    const amount = Number(validation.grossAmount);
    const walletTopup = await getMidtransWalletTopup(validation.orderId);
    if (walletTopup) {
      const result = await applyMidtransWalletTopup({
        referenceId: validation.orderId,
        status,
        transactionId:
          typeof payload.transaction_id === "string"
            ? payload.transaction_id
            : null,
        callbackAmount: amount,
      });
      return Response.json({ ok: true, walletTopup: result });
    }
    const order = await getOrderByReference(validation.orderId);
    if (!order) return Response.json({ ok: true, ignored: "order_not_found" });
    if (
      status === "paid" &&
      (!Number.isFinite(amount) || amount !== order.total)
    )
      return Response.json({ ok: true, ignored: "amount_mismatch" });
    await recordOrderEvent({
      orderId: order.id,
      source: "midtrans",
      eventId: String(
        payload.transaction_id ?? payload.status_code ?? crypto.randomUUID(),
      ),
      status,
      payload,
    });
    const firstPaid = await applyPaymentStatus(order, status);
    if (firstPaid && order.fulfillment_type === "automatic") {
      await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
    }
    return Response.json({ ok: true });
  } catch (error) {
    return Response.json(
      {
        error:
          error instanceof Error
            ? error.message
            : "Callback Midtrans gagal diproses.",
      },
      { status: 503 },
    );
  }
}
