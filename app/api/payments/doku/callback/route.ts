import { getD1 } from "@/db";
import { hashHex } from "@/lib/server/crypto";
import {
  parseDokuCheckoutNotification,
  validateDokuCheckoutNotification,
  type DokuEnvironment,
} from "@/lib/server/doku-checkout";
import {
  parseDokuNotification,
  validateDokuNotification,
} from "@/lib/server/doku";
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
  applyDokuWalletTopup,
  getDokuWalletTopup,
} from "@/lib/server/wallet";
import {
  applyExternalWalletTopup,
  getExternalWalletTopup,
} from "@/lib/server/wallet-external";

export const dynamic = "force-dynamic";

function object(value: unknown) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};
}

async function prepareDokuRuntime() {
  const current = getRuntimeEnv<Record<string, unknown>>();
  setRuntimeEnv(await hydrateDokuCheckoutRuntimeEnv(current));
}

function notificationAck(payload: Record<string, unknown>, eventId: string) {
  const vaData = object(payload.virtualAccountData);
  if (Object.keys(vaData).length) {
    return {
      responseCode: "2002500",
      responseMessage: "Success",
      virtualAccountData: {
        partnerServiceId: vaData.partnerServiceId,
        customerNo: vaData.customerNo,
        virtualAccountNo: vaData.virtualAccountNo,
        virtualAccountName: vaData.virtualAccountName,
        trxId: vaData.trxId,
        paymentRequestId: vaData.paymentRequestId,
      },
    };
  }
  return {
    responseCode: "2005600",
    approvalCode: eventId.slice(0, 32),
    responseMessage: "Request has been processed successfully",
  };
}

function directNotificationResponse(
  scheme: "snap" | "non-snap",
  payload: Record<string, unknown>,
  eventId: string,
) {
  return scheme === "snap"
    ? Response.json(notificationAck(payload, eventId))
    : Response.json({ ok: true });
}

export async function GET() {
  return Response.json(
    { ok: true, service: "doku-payment-notification", method: "POST" },
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

    const checkoutNotification = parseDokuCheckoutNotification(payload);
    const directNotification = parseDokuNotification(payload);
    const referenceId = checkoutNotification.referenceId || directNotification.referenceId;
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
    const legacyWallet = expectedOrder || externalWallet ? null : await getDokuWalletTopup(referenceId);
    const legacyWalletRouting = expectedOrder || externalWallet || !legacyWallet
      ? null
      : await getD1().prepare(`SELECT doku_environment FROM wallet_topups
          WHERE reference_id = ? AND source = 'doku' LIMIT 1`)
          .bind(referenceId)
          .first<{ doku_environment: DokuEnvironment | null }>();

    const expectedMode = orderRouting?.payment_gateway_mode ?? externalWallet?.payment_gateway_mode ?? null;
    const expectedEnvironment = orderRouting?.payment_gateway_environment
      ?? externalWallet?.gateway_environment
      ?? expectedOrder?.doku_environment
      ?? legacyWalletRouting?.doku_environment
      ?? null;
    const target = new URL(request.url).pathname;

    if (expectedMode === "checkout") {
      const validation = validateDokuCheckoutNotification({
        rawBody,
        requestTarget: target,
        clientId: request.headers.get("client-id"),
        requestId: request.headers.get("request-id"),
        requestTimestamp: request.headers.get("request-timestamp"),
        receivedSignature: request.headers.get("signature"),
        expectedEnvironment,
      });
      if (!validation.valid) {
        return Response.json({ error: "Signature callback DOKU tidak valid." }, { status: 401 });
      }

      const notification = checkoutNotification;
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
      const status = notification.status;
      if (!canProcessDokuOrderCallback(order.payment_status, status)) {
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
        status === "paid" &&
        (!Number.isFinite(notification.amount) || notification.amount !== order.total)
      ) {
        return Response.json({ ok: true });
      }

      const transition = await applyExternalPaymentEvent({
        order,
        source: "doku",
        eventId,
        status,
        payload,
        authoritativePaid: status === "paid",
      });
      if (transition.firstPaid && order.fulfillment_type === "automatic") {
        await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi pesanan selesai DOKU gagal:", error),
        );
      }
      return Response.json({ ok: true });
    }

    // Drain already-issued Direct API invoices using their persisted mode.
    if (expectedMode === "direct" || legacyWallet) {
      const validation = validateDokuNotification({
        rawBody,
        requestTarget: target,
        partnerId: request.headers.get("x-partner-id"),
        requestTimestamp: request.headers.get("x-timestamp"),
        receivedSignature: request.headers.get("x-signature"),
        authorization: request.headers.get("authorization"),
        clientId: request.headers.get("client-id"),
        requestId: request.headers.get("request-id"),
        legacyTimestamp: request.headers.get("request-timestamp"),
        legacySignature: request.headers.get("signature"),
        expectedEnvironment,
      });
      if (!validation.valid || !validation.scheme) {
        return Response.json({ error: "Signature callback DOKU tidak valid." }, { status: 401 });
      }

      const notification = directNotification;
      const status = notification.status;
      const eventId = request.headers.get("x-external-id")
        || request.headers.get("request-id")
        || `body-${hashHex("sha256", rawBody)}`;

      if (externalWallet) {
        const result = await applyExternalWalletTopup({
          referenceId,
          gateway: "doku",
          status,
          originalRequestId: notification.originalRequestId,
          callbackAmount: notification.amount,
          authoritativePaid: status === "paid",
        });
        if (result.credited) {
          await notifyWalletTopupSuccessById(externalWallet.id, referenceId).catch((error) =>
            console.error("Notifikasi top up DOKU Direct gagal:", error),
          );
        }
        return directNotificationResponse(validation.scheme, payload, eventId);
      }

      if (legacyWallet) {
        const result = await applyDokuWalletTopup({
          referenceId,
          status,
          originalRequestId: notification.originalRequestId,
          callbackAmount: notification.amount,
        });
        if (result.credited) {
          await notifyWalletTopupSuccessById(legacyWallet.id, referenceId).catch((error) =>
            console.error("Notifikasi top up DOKU Direct gagal:", error),
          );
        }
        return directNotificationResponse(validation.scheme, payload, eventId);
      }

      const order = expectedOrder;
      if (!order) return directNotificationResponse(validation.scheme, payload, eventId);
      if (orderRouting?.payment_gateway !== "doku" || orderRouting.payment_gateway_mode !== "direct") {
        return directNotificationResponse(validation.scheme, payload, eventId);
      }
      if (!canProcessDokuOrderCallback(order.payment_status, status)) {
        return directNotificationResponse(validation.scheme, payload, eventId);
      }
      if (
        order.doku_request_id &&
        notification.originalRequestId &&
        order.doku_request_id !== notification.originalRequestId
      ) {
        return directNotificationResponse(validation.scheme, payload, eventId);
      }
      if (
        status === "paid" &&
        (!Number.isFinite(notification.amount) || notification.amount !== order.total)
      ) {
        return directNotificationResponse(validation.scheme, payload, eventId);
      }

      const transition = await applyExternalPaymentEvent({
        order,
        source: "doku",
        eventId,
        status,
        payload,
        authoritativePaid: status === "paid",
      });
      if (transition.firstPaid && order.fulfillment_type === "automatic") {
        await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi pesanan selesai DOKU Direct gagal:", error),
        );
      }
      return directNotificationResponse(validation.scheme, payload, eventId);
    }

    return Response.json({ ok: true });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Callback DOKU gagal diproses." },
      { status: 503 },
    );
  }
}
