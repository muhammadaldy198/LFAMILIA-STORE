import { hashHex } from "@/lib/server/crypto";
import { mapIpaymuStatus, parseIpaymuCallback, validateIpaymuCallback } from "@/lib/server/ipaymu";
import { applyPaymentStatus, fulfillAutomaticOrder, getOrderByReference, recordOrderEvent } from "@/lib/server/orders";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type RuntimeEnv = { PUBLIC_BASE_URL?: string };

export async function POST(request: Request) {
  const rawBody = await request.text();
  let raw: Record<string, unknown>;
  try {
    raw = parseIpaymuCallback(rawBody, request.headers.get("content-type"));
  } catch {
    return Response.json({ error: "Payload callback tidak valid." }, { status: 400 });
  }

  try {
    const bodySignature = typeof raw.signature === "string" ? raw.signature : null;
    const validation = validateIpaymuCallback(raw, request.headers.get("x-signature") || bodySignature);
    if (!validation.valid) return Response.json({ error: "Signature callback tidak valid." }, { status: 401 });
    const referenceId = String(validation.normalized.reference_id ?? "");
    if (!referenceId) return Response.json({ error: "Reference ID tidak ada." }, { status: 400 });
    const order = await getOrderByReference(referenceId);
    if (!order) return Response.json({ ok: true, ignored: "order_not_found" });

    const callbackTransactionId = validation.normalized.trx_id == null ? null : String(validation.normalized.trx_id);
    if (order.ipaymu_transaction_id && callbackTransactionId && order.ipaymu_transaction_id !== callbackTransactionId) {
      return Response.json({ ok: true, ignored: "transaction_mismatch" });
    }
    const status = mapIpaymuStatus(validation.normalized);
    const callbackAmount = Number(validation.normalized.amount ?? validation.normalized.total ?? 0);
    if (status === "paid" && callbackAmount > 0 && callbackAmount !== order.total && callbackAmount !== order.subtotal) {
      return Response.json({ ok: true, ignored: "amount_mismatch" });
    }
    await recordOrderEvent({
      orderId: order.id,
      source: "ipaymu",
      eventId: request.headers.get("x-external-id") || hashHex("sha256", rawBody),
      status,
      payload: validation.normalized,
    });
    const firstPaid = await applyPaymentStatus(order, status);
    if (firstPaid && order.fulfillment_type === "automatic") {
      const configured = getRuntimeEnv<RuntimeEnv>().PUBLIC_BASE_URL?.trim();
      await fulfillAutomaticOrder(order.id, new URL(configured || request.url).origin);
    }
    return Response.json({ ok: true });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Callback gagal diproses." }, { status: 503 });
  }
}
