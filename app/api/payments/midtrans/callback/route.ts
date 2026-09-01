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
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";
type RuntimeEnv = { PUBLIC_BASE_URL?: string };

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
    const order = await getOrderByReference(validation.orderId);
    if (!order) return Response.json({ ok: true, ignored: "order_not_found" });
    const status = mapMidtransStatus(payload);
    const amount = Number(validation.grossAmount);
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
      const configured = getRuntimeEnv<RuntimeEnv>().PUBLIC_BASE_URL?.trim();
      await fulfillAutomaticOrder(
        order.id,
        new URL(configured || request.url).origin,
      );
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
