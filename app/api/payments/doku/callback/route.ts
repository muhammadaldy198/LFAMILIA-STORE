import { hashHex } from "@/lib/server/crypto";
import {
  parseDokuNotification,
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
    const expectedOrder = referenceId ? await getOrderByReference(referenceId) : null;
    const target = new URL(request.url).pathname;
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
      expectedEnvironment: expectedOrder?.doku_environment ?? null,
    });
    if (!validation.valid) {
      return Response.json({ error: "Signature callback DOKU tidak valid." }, { status: 401 });
    }

    const notification = parseDokuNotification(payload);
    const referenceId = notification.referenceId;
    if (!referenceId) {
      return Response.json({ error: "Referensi transaksi DOKU tidak ada." }, { status: 400 });
    }

    const status = notification.status;
    const callbackAmount = notification.amount;
    const originalRequestId = notification.originalRequestId;
    const eventId = request.headers.get("x-external-id") || request.headers.get("request-id") || "body-" + hashHex("sha256", rawBody);

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
      return notificationResponse(validation.scheme, payload, eventId);
    }

    const order = expectedOrder;
    if (!order) return notificationResponse(validation.scheme, payload, eventId);

    if (
      order.doku_request_id &&
      originalRequestId &&
      order.doku_request_id !== originalRequestId
    ) {
      return notificationResponse(validation.scheme, payload, eventId);
    }

    if (
      status === "paid" &&
      (!Number.isFinite(callbackAmount) || callbackAmount !== order.total)
    ) {
      return notificationResponse(validation.scheme, payload, eventId);
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

    return notificationResponse(validation.scheme, payload, eventId);
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
