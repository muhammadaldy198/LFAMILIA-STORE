import { getD1 } from "@/db";
import { hashHex } from "@/lib/server/crypto";
import { validateDokuCheckoutNotification } from "@/lib/server/doku-checkout";
import {
  parseDokuNotification,
  validateDokuNotification,
  type DokuEnvironment,
} from "@/lib/server/doku";
import { applyPendingDokuPaymentStatus } from "@/lib/server/doku-payment-transition";
import { canProcessDokuOrderCallback } from "@/lib/server/final-audit-rules";
import {
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

function notificationResponse(
  scheme: "snap" | "non-snap" | "checkout",
  payload: Record<string, unknown>,
  eventId: string,
) {
  return scheme === "snap"
    ? Response.json(notificationAck(payload, eventId))
    : Response.json({ ok: true });
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
    const notification = parseDokuNotification(payload);
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
    const legacyWallet = expectedOrder || externalWallet
      ? null
      : await getD1()
          .prepare(`SELECT doku_environment FROM wallet_topups
            WHERE reference_id = ? AND source = 'doku' LIMIT 1`)
          .bind(referenceId)
          .first<{ doku_environment: DokuEnvironment | null }>();

    const expectedMode = orderRouting?.payment_gateway_mode ?? externalWallet?.payment_gateway_mode ?? null;
    const expectedEnvironment = orderRouting?.payment_gateway_environment
      ?? externalWallet?.gateway_environment
      ?? expectedOrder?.doku_environment
      ?? legacyWallet?.doku_environment
      ?? null;
    const target = new URL(request.url).pathname;

    let scheme: "snap" | "non-snap" | "checkout" | null = null;
    if (expectedMode === "checkout" && expectedEnvironment) {
      const valid = await validateDokuCheckoutNotification({
        rawBody,
        requestTarget: target,
        clientId: request.headers.get("client-id"),
        requestId: request.headers.get("request-id"),
        requestTimestamp: request.headers.get("request-timestamp"),
        receivedSignature: request.headers.get("signature"),
        environment: expectedEnvironment,
      });
      if (valid) scheme = "checkout";
    } else {
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
      if (validation.valid) scheme = validation.scheme;
    }

    if (!scheme) {
      return Response.json({ error: "Signature callback DOKU tidak valid." }, { status: 401 });
    }

    const status = notification.status;
    const callbackAmount = notification.amount;
    const originalRequestId = notification.originalRequestId;
    const eventId = request.headers.get("x-external-id") || request.headers.get("request-id") || `body-${hashHex("sha256", rawBody)}`;

    if (externalWallet) {
      // DOKU Checkout may emit FAILED while its hosted page still permits retry.
      // Acknowledge it without rejecting the top-up; SUCCESS is authoritative.
      if (scheme === "checkout" && status === "failed") {
        return notificationResponse(scheme, payload, eventId);
      }
      const result = await applyExternalWalletTopup({
        referenceId,
        gateway: "doku",
        status,
        originalRequestId: scheme === "checkout" ? null : originalRequestId,
        callbackAmount,
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(externalWallet.id, referenceId).catch((error) =>
          console.error("Notifikasi top up DOKU gagal:", error),
        );
      }
      return notificationResponse(scheme, payload, eventId);
    }

    const legacyTopup = await getDokuWalletTopup(referenceId);
    if (legacyTopup) {
      const result = await applyDokuWalletTopup({
        referenceId,
        status,
        originalRequestId,
        callbackAmount,
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(legacyTopup.id, referenceId).catch((error) =>
          console.error("Notifikasi top up DOKU gagal:", error),
        );
      }
      return notificationResponse(scheme, payload, eventId);
    }

    const order = expectedOrder;
    if (!order) return notificationResponse(scheme, payload, eventId);
    if (orderRouting?.payment_gateway && orderRouting.payment_gateway !== "doku") {
      return notificationResponse(scheme, payload, eventId);
    }
    if (!canProcessDokuOrderCallback(order.payment_status)) {
      return notificationResponse(scheme, payload, eventId);
    }

    if (scheme !== "checkout" && order.doku_request_id && originalRequestId && order.doku_request_id !== originalRequestId) {
      return notificationResponse(scheme, payload, eventId);
    }
    if (status === "paid" && (!Number.isFinite(callbackAmount) || callbackAmount !== order.total)) {
      return notificationResponse(scheme, payload, eventId);
    }

    // Checkout FAILED is not final because DOKU can allow retry on the hosted page.
    if (scheme === "checkout" && status === "failed") {
      return notificationResponse(scheme, payload, eventId);
    }

    await recordOrderEvent({
      orderId: order.id,
      source: "doku",
      eventId,
      status,
      payload,
    });

    const firstPaid = await applyPendingDokuPaymentStatus(order, status);
    if (firstPaid && order.fulfillment_type === "automatic") {
      await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
      await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
        console.error("Notifikasi pesanan selesai DOKU gagal:", error),
      );
    }

    return notificationResponse(scheme, payload, eventId);
  } catch (error) {
    return Response.json(
      {
        error: error instanceof Error ? error.message : "Callback DOKU gagal diproses.",
      },
      { status: 503 },
    );
  }
}
