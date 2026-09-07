import {
  mapMidtransBisnapStatus,
  validateMidtransBisnapNotification,
} from "@/lib/server/midtrans-bisnap";
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
  applyMidtransWalletTopup,
  getMidtransWalletTopup,
} from "@/lib/server/wallet";

export type MidtransBisnapCallbackKind =
  | "direct-debit"
  | "qris"
  | "virtual-account";

function serviceCode(kind: MidtransBisnapCallbackKind) {
  if (kind === "direct-debit") return "56";
  if (kind === "qris") return "52";
  return "25";
}

function callbackResponse(
  body: Record<string, unknown>,
  status = 200,
) {
  return Response.json(body, {
    status,
    headers: {
      "Cache-Control": "no-store",
      "X-TIMESTAMP": new Date().toISOString(),
    },
  });
}

function errorResponse(
  kind: MidtransBisnapCallbackKind,
  status: 400 | 401 | 404,
  message: string,
) {
  const service = serviceCode(kind);
  const responseCode =
    status === 401
      ? `401${service}00`
      : status === 404
        ? `404${service}13`
        : `400${service}00`;
  return callbackResponse({ responseCode, responseMessage: message }, status);
}

function successPayload(
  kind: MidtransBisnapCallbackKind,
  payload: Record<string, unknown>,
) {
  if (kind === "direct-debit") {
    return {
      responseCode: "2005600",
      responseMessage: "Request has been processed successfully",
    };
  }

  if (kind === "qris") {
    return {
      responseCode: "2005200",
      responseMessage: "Request has been processed successfully",
    };
  }

  const nested = (payload.virtualAccountData ?? {}) as Record<string, unknown>;
  return {
    responseCode: "2002500",
    responseMessage: "Successful",
    virtualAccountData: {
      partnerServiceId:
        nested.partnerServiceId ?? payload.partnerServiceId ?? "",
      customerNo: nested.customerNo ?? payload.customerNo ?? "",
      virtualAccountNo:
        nested.virtualAccountNo ?? payload.virtualAccountNo ?? "",
      trxId: nested.trxId ?? payload.trxId ?? "",
    },
  };
}

function referenceId(
  kind: MidtransBisnapCallbackKind,
  payload: Record<string, unknown>,
) {
  if (kind === "virtual-account") {
    const nested = (payload.virtualAccountData ?? {}) as Record<string, unknown>;
    return String(nested.trxId ?? payload.trxId ?? "").trim();
  }
  return String(payload.originalPartnerReferenceNo ?? "").trim();
}

function providerTransactionId(
  kind: MidtransBisnapCallbackKind,
  payload: Record<string, unknown>,
) {
  if (kind === "virtual-account") {
    const nested = (payload.virtualAccountData ?? {}) as Record<string, unknown>;
    return String(nested.trxId ?? payload.trxId ?? "").trim() || null;
  }
  return String(payload.originalReferenceNo ?? "").trim() || null;
}

function transactionStatus(
  kind: MidtransBisnapCallbackKind,
  payload: Record<string, unknown>,
) {
  if (kind === "virtual-account") {
    const nested = (payload.virtualAccountData ?? {}) as Record<string, unknown>;
    const additionalInfo =
      (nested.additionalInfo ?? payload.additionalInfo ?? {}) as Record<
        string,
        unknown
      >;
    return additionalInfo.paymentFlagStatus ?? payload.paymentFlagStatus;
  }
  return payload.latestTransactionStatus;
}

function callbackAmount(
  kind: MidtransBisnapCallbackKind,
  payload: Record<string, unknown>,
) {
  const source =
    kind === "virtual-account"
      ? ((payload.paidAmount ??
          ((payload.virtualAccountData ?? {}) as Record<string, unknown>)
            .paidAmount) as Record<string, unknown> | undefined)
      : (payload.amount as Record<string, unknown> | undefined);
  const value = Number(source?.value ?? 0);
  return Number.isFinite(value) ? value : 0;
}

function eventId(
  kind: MidtransBisnapCallbackKind,
  payload: Record<string, unknown>,
  reference: string,
) {
  const providerId = providerTransactionId(kind, payload);
  const status = String(transactionStatus(kind, payload) ?? "unknown");
  return `bisnap-${kind}-${providerId || reference}-${status}`;
}

export async function handleMidtransBisnapCallback(
  request: Request,
  kind: MidtransBisnapCallbackKind,
) {
  const rawBody = await request.text();
  const requestUrl = new URL(request.url);
  const validation = validateMidtransBisnapNotification({
    path: requestUrl.pathname,
    rawBody,
    timestamp: request.headers.get("x-timestamp"),
    signature: request.headers.get("x-signature"),
  });

  if (!validation.valid) {
    return errorResponse(
      kind,
      401,
      "Unauthorized. Signature is invalid",
    );
  }

  let payload: Record<string, unknown>;
  try {
    payload = JSON.parse(rawBody) as Record<string, unknown>;
  } catch {
    return errorResponse(kind, 400, "Invalid JSON payload");
  }

  const reference = referenceId(kind, payload);
  if (!reference) {
    return errorResponse(
      kind,
      400,
      "Partner reference number is required",
    );
  }

  const status = mapMidtransBisnapStatus(transactionStatus(kind, payload));
  const amount = callbackAmount(kind, payload);
  const transactionId = providerTransactionId(kind, payload);

  const walletTopup = await getMidtransWalletTopup(reference);
  if (walletTopup) {
    const result = await applyMidtransWalletTopup({
      referenceId: reference,
      status,
      transactionId,
      callbackAmount: amount,
    });

    if ("ignored" in result && result.ignored === "amount_mismatch") {
      return errorResponse(kind, 404, "Invalid Amount");
    }

    if (result.credited) {
      await notifyWalletTopupSuccessById(walletTopup.id, reference).catch(
        (error) =>
          console.error("Notifikasi top up Midtrans BI-SNAP gagal:", error),
      );
    }

    return callbackResponse(successPayload(kind, payload));
  }

  const order = await getOrderByReference(reference);
  if (!order) {
    return callbackResponse(successPayload(kind, payload));
  }

  if (
    status === "paid" &&
    (!Number.isFinite(amount) || amount <= 0 || amount !== order.total)
  ) {
    return errorResponse(kind, 404, "Invalid Amount");
  }

  await recordOrderEvent({
    orderId: order.id,
    source: "midtrans",
    eventId: eventId(kind, payload, reference),
    status,
    payload: {
      environment: validation.environment,
      kind,
      notification: payload,
    },
  });

  const firstPaid = await applyPaymentStatus(order, status);
  if (firstPaid && order.fulfillment_type === "automatic") {
    await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
    await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
      console.error("Notifikasi pesanan Midtrans BI-SNAP gagal:", error),
    );
  }

  return callbackResponse(successPayload(kind, payload));
}
