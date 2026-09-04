import { hashHex } from "@/lib/server/crypto";
import {
  mapIpaymuStatus,
  parseIpaymuCallback,
  validateIpaymuCallback,
} from "@/lib/server/ipaymu";
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
  applyIpaymuWalletTopup,
  getIpaymuWalletTopup,
} from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

export async function GET() {
  return Response.json(
    { ok: true, service: "ipaymu-notification", method: "POST" },
    { headers: { "Cache-Control": "no-store" } },
  );
}

export async function POST(request: Request) {
  const rawBody = await request.text();
  let raw: Record<string, unknown>;
  try {
    raw = parseIpaymuCallback(rawBody, request.headers.get("content-type"));
  } catch {
    return Response.json(
      { error: "Payload callback iPaymu tidak valid." },
      { status: 400 },
    );
  }

  try {
    const bodySignature =
      typeof raw.signature === "string" ? raw.signature : null;
    const validation = validateIpaymuCallback(
      raw,
      request.headers.get("x-signature") || bodySignature,
    );
    if (!validation.valid)
      return Response.json(
        { error: "Signature callback iPaymu tidak valid." },
        { status: 401 },
      );

    const referenceId = String(validation.normalized.reference_id ?? "");
    if (!referenceId)
      return Response.json(
        { error: "Reference ID iPaymu tidak ada." },
        { status: 400 },
      );

    const status = mapIpaymuStatus(validation.normalized);
    const callbackAmount = Number(
      validation.normalized.amount ?? validation.normalized.total ?? 0,
    );
    const transactionId =
      validation.normalized.trx_id == null
        ? null
        : String(validation.normalized.trx_id);

    const walletTopup = await getIpaymuWalletTopup(referenceId);
    if (walletTopup) {
      const result = await applyIpaymuWalletTopup({
        referenceId,
        status,
        transactionId,
        callbackAmount,
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(walletTopup.id, referenceId).catch(
          (error) => console.error("Notifikasi top up iPaymu gagal:", error),
        );
      }
      return Response.json({ ok: true, walletTopup: result });
    }

    const order = await getOrderByReference(referenceId);
    if (!order) return Response.json({ ok: true, ignored: "order_not_found" });

    if (
      order.ipaymu_transaction_id &&
      transactionId &&
      order.ipaymu_transaction_id !== transactionId
    )
      return Response.json({ ok: true, ignored: "transaction_mismatch" });

    if (
      status === "paid" &&
      (!Number.isFinite(callbackAmount) || callbackAmount !== order.total)
    )
      return Response.json({ ok: true, ignored: "amount_mismatch" });

    await recordOrderEvent({
      orderId: order.id,
      source: "ipaymu",
      eventId:
        request.headers.get("x-external-id") || hashHex("sha256", rawBody),
      status,
      payload: validation.normalized,
    });

    const firstPaid = await applyPaymentStatus(order, status);
    if (firstPaid && order.fulfillment_type === "automatic") {
      await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
      await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
        console.error("Notifikasi pesanan selesai iPaymu gagal:", error),
      );
    }

    return Response.json({ ok: true });
  } catch (error) {
    return Response.json(
      {
        error:
          error instanceof Error
            ? error.message
            : "Callback iPaymu gagal diproses.",
      },
      { status: 503 },
    );
  }
}
