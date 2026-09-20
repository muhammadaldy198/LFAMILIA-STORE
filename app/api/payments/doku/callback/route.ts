import { getD1 } from "@/db";
import { hashHex } from "@/lib/server/crypto";
import {
  parseDokuCheckoutNotification,
  validateDokuCheckoutNotification,
  type DokuEnvironment,
} from "@/lib/server/doku-checkout";
import { canProcessDokuOrderCallback } from "@/lib/server/final-audit-rules";
import { fulfillAutomaticOrder, getOrderByReference } from "@/lib/server/orders";
import { hydrateDokuCheckoutRuntimeEnv } from "@/lib/server/payment-mode-config";
import { applyExternalPaymentEvent } from "@/lib/server/payment-transition";
import { getPublicBaseUrl, getRuntimeEnv, setRuntimeEnv } from "@/lib/server/runtime-env";
import {
  notifyOrderFulfillmentSuccessById,
  notifyWalletTopupSuccessById,
} from "@/lib/server/transaction-notifications";
import {
  applyExternalWalletTopup,
  getExternalWalletTopup,
} from "@/lib/server/wallet-external";

export const dynamic = "force-dynamic";

async function prepareDokuRuntime() {
  const current = getRuntimeEnv<Record<string, unknown>>();
  setRuntimeEnv(await hydrateDokuCheckoutRuntimeEnv(current));
}

export async function GET() {
  return Response.json(
    { ok: true, service: "doku-checkout-notification", method: "POST" },
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
    await prepareDokuRuntime();
    const notification = parseDokuCheckoutNotification(payload);
    const referenceId = notification.referenceId;
    if (!referenceId) {
      return Response.json({ error: "Referensi transaksi DOKU tidak ada." }, { status: 400 });
    }

    const expectedOrder = await getOrderByReference(referenceId);
    const orderRouting = expectedOrder
      ? await getD1().prepare(`SELECT payment_gateway, payment_gateway_mode, payment_gateway_environment
          FROM orders WHERE id = ? LIMIT 1`)
          .bind(expectedOrder.id)
          .first<{
            payment_gateway: string | null;
            payment_gateway_mode: string | null;
            payment_gateway_environment: DokuEnvironment | null;
          }>()
      : null;
    const externalWallet = expectedOrder ? null : await getExternalWalletTopup(referenceId, "doku");
    const expectedMode = orderRouting?.payment_gateway_mode ?? externalWallet?.payment_gateway_mode ?? null;
    if (expectedMode && expectedMode !== "checkout") return Response.json({ ok: true });

    const expectedEnvironment = orderRouting?.payment_gateway_environment
      ?? externalWallet?.gateway_environment
      ?? expectedOrder?.doku_environment
      ?? null;

    const validation = validateDokuCheckoutNotification({
      rawBody,
      requestTarget: new URL(request.url).pathname,
      clientId: request.headers.get("client-id"),
      requestId: request.headers.get("request-id"),
      requestTimestamp: request.headers.get("request-timestamp"),
      receivedSignature: request.headers.get("signature"),
      expectedEnvironment,
    });
    if (!validation.valid) {
      return Response.json({ error: "Signature callback DOKU tidak valid." }, { status: 401 });
    }

    const eventId = request.headers.get("request-id") || `body-${hashHex("sha256", rawBody)}`;

    if (externalWallet) {
      const result = await applyExternalWalletTopup({
        referenceId,
        gateway: "doku",
        status: notification.status,
        originalRequestId: notification.originalRequestId,
        callbackAmount: notification.amount,
        authoritativePaid: notification.status === "paid",
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(externalWallet.id, referenceId).catch((error) =>
          console.error("Notifikasi top up DOKU gagal:", error),
        );
      }
      return Response.json({ ok: true });
    }

    const order = expectedOrder;
    if (!order) return Response.json({ ok: true });
    if (orderRouting?.payment_gateway !== "doku" || orderRouting.payment_gateway_mode !== "checkout") {
      return Response.json({ ok: true });
    }
    if (!canProcessDokuOrderCallback(order.payment_status, notification.status)) {
      return Response.json({ ok: true });
    }

    if (
      order.doku_request_id &&
      notification.originalRequestId &&
      order.doku_request_id !== notification.originalRequestId
    ) {
      return Response.json({ ok: true });
    }
    if (
      notification.status === "paid" &&
      (!Number.isFinite(notification.amount) || notification.amount !== order.total)
    ) {
      return Response.json({ ok: true });
    }

    const transition = await applyExternalPaymentEvent({
      order,
      source: "doku",
      eventId,
      status: notification.status,
      payload,
      authoritativePaid: notification.status === "paid",
    });
    if (transition.firstPaid && order.fulfillment_type === "automatic") {
      await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
      await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
        console.error("Notifikasi pesanan selesai DOKU gagal:", error),
      );
    }

    return Response.json({ ok: true });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Callback DOKU gagal diproses." },
      { status: 503 },
    );
  }
}
