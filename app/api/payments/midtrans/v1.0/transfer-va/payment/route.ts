import { getD1 } from "@/db";
import {
  getMidtransPartnerId,
  verifyMidtransNotification,
} from "@/lib/server/midtrans";
import {
  applyPaymentStatus,
  fulfillAutomaticOrder,
  getOrderByReference,
} from "@/lib/server/orders";
import { recordExternalPaymentEvent } from "@/lib/server/external-payments";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";

export const dynamic = "force-dynamic";

const PROVIDER_ENDPOINT = "/v1.0/transfer-va/payment";

type NotificationBody = {
  partnerServiceId?: string;
  customerNo?: string;
  virtualAccountNo?: string;
  virtualAccountName?: string;
  trxId?: string;
  paymentRequestId?: string;
  paidAmount?: { value?: string; currency?: string };
  referenceNo?: string;
  additionalInfo?: {
    bank?: string;
    merchantId?: string;
    paymentFlagStatus?: string;
  };
};

function clean(value: unknown) {
  return typeof value === "string" ? value.trim() : "";
}

function responseTimestamp(date = new Date()) {
  const shifted = new Date(date.getTime() + 7 * 60 * 60 * 1000);
  return `${shifted.toISOString().slice(0, 19)}+07:00`;
}

function snapResponse(payload: Record<string, unknown>, status = 200) {
  return Response.json(payload, {
    status,
    headers: {
      "Cache-Control": "no-store",
      "X-TIMESTAMP": responseTimestamp(),
    },
  });
}

function paymentAmount(body: NotificationBody) {
  const currency = clean(body.paidAmount?.currency).toUpperCase();
  const amount = Number(body.paidAmount?.value);
  if (currency !== "IDR" || !Number.isFinite(amount)) return null;
  return amount;
}

function mappedStatus(flag: string): "paid" | "pending" | "expired" | "failed" | "ignore" {
  if (flag === "00") return "paid";
  if (flag === "01" || flag === "02" || flag === "03") return "pending";
  if (flag === "08") return "expired";
  if (flag === "05" || flag === "06" || flag === "07" || flag === "09") return "failed";
  if (flag === "04") return "ignore";
  return "ignore";
}

function successResponse(body: NotificationBody) {
  return snapResponse({
    responseCode: "2002500",
    responseMessage: "Success",
    virtualAccountData: {
      partnerServiceId: body.partnerServiceId,
      customerNo: body.customerNo,
      virtualAccountNo: body.virtualAccountNo,
      virtualAccountName: body.virtualAccountName,
      trxId: body.trxId,
      paymentRequestId: body.paymentRequestId,
    },
  });
}

export async function GET() {
  return Response.json(
    { ok: true, service: "midtrans-bisnap-va-notification", method: "POST" },
    { headers: { "Cache-Control": "no-store" } },
  );
}

export async function POST(request: Request) {
  const rawBody = await request.text();
  const timestamp = request.headers.get("x-timestamp") || "";
  const signature = request.headers.get("x-signature") || "";
  const partnerId = request.headers.get("x-partner-id") || "";

  let body: NotificationBody;
  try {
    body = JSON.parse(rawBody) as NotificationBody;
  } catch {
    return snapResponse(
      { responseCode: "4002500", responseMessage: "Invalid request" },
      400,
    );
  }

  try {
    if (!partnerId || partnerId !== getMidtransPartnerId()) {
      return snapResponse(
        { responseCode: "4012500", responseMessage: "Unauthorized" },
        401,
      );
    }

    // Midtrans BI-SNAP signs the SHA-256 of the minified JSON body. Parse and
    // stringify before verification so harmless transport whitespace cannot
    // change the signature input.
    const minifiedBody = JSON.stringify(body);
    if (!verifyMidtransNotification({
      rawBody: minifiedBody,
      timestamp,
      signature,
      endpointPath: PROVIDER_ENDPOINT,
    })) {
      return snapResponse(
        { responseCode: "4012500", responseMessage: "Unauthorized" },
        401,
      );
    }

    const referenceId = clean(body.trxId);
    if (!referenceId) {
      return snapResponse(
        { responseCode: "4002500", responseMessage: "Missing transaction reference" },
        400,
      );
    }
    const order = await getOrderByReference(referenceId);
    if (!order) return successResponse(body);

    const routing = await getD1().prepare(
      "SELECT payment_gateway, gateway_reference_no FROM orders WHERE id = ? LIMIT 1",
    ).bind(order.id).first<{ payment_gateway: string | null; gateway_reference_no: string | null }>();
    if (routing?.payment_gateway !== "midtrans") return successResponse(body);

    const bank = clean(body.additionalInfo?.bank).toLowerCase();
    if (bank && bank !== order.payment_channel.toLowerCase()) return successResponse(body);
    const callbackReference = clean(body.referenceNo);
    if (
      routing.gateway_reference_no &&
      callbackReference &&
      routing.gateway_reference_no !== callbackReference
    ) return successResponse(body);

    const flag = clean(body.additionalInfo?.paymentFlagStatus);
    const status = mappedStatus(flag);
    const callbackAmount = paymentAmount(body);
    if (status === "paid" && callbackAmount !== order.total) return successResponse(body);

    const eventId = callbackReference || clean(body.paymentRequestId) || `${referenceId}-${flag || "unknown"}`;
    await recordExternalPaymentEvent({
      orderId: order.id,
      gateway: "midtrans",
      eventId: `notification-${eventId}`,
      status,
      payload: body,
    });

    if (status !== "ignore") {
      const firstPaid = await applyPaymentStatus(order, status);
      if (firstPaid && order.fulfillment_type === "automatic") {
        await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi pesanan selesai Midtrans gagal:", error),
        );
      }
    }

    return successResponse(body);
  } catch (error) {
    console.error("Callback Midtrans BI-SNAP gagal:", error);
    return snapResponse(
      { responseCode: "5002500", responseMessage: "Internal server error" },
      500,
    );
  }
}
