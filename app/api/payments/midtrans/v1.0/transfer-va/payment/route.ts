import { getD1 } from "@/db";
import {
  getMidtransPartnerId,
  verifyMidtransNotification,
  type MidtransEnvironment,
} from "@/lib/server/midtrans";
import {
  applyPaymentStatus,
  fulfillAutomaticOrder,
  getOrderByReference,
} from "@/lib/server/orders";
import { recordExternalPaymentEvent } from "@/lib/server/external-payments";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import {
  notifyOrderFulfillmentSuccessById,
  notifyWalletTopupSuccessById,
} from "@/lib/server/transaction-notifications";
import { applyExternalWalletTopup, getExternalWalletTopup } from "@/lib/server/wallet-external";

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

type Routing = {
  payment_gateway: string | null;
  payment_gateway_mode: string | null;
  payment_gateway_environment: MidtransEnvironment | null;
  gateway_reference_no: string | null;
  gateway_payment_no: string | null;
};

function clean(value: unknown) {
  return typeof value === "string" ? value.trim() : "";
}

function rawText(value: unknown) {
  return typeof value === "string" ? value : "";
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
  // A refund notification must never move an already-paid transaction backwards.
  if (flag === "04") return "ignore";
  return "ignore";
}

function successResponse(body: NotificationBody) {
  return snapResponse({
    responseCode: "2002500",
    responseMessage: "Successful",
    virtualAccountData: {
      partnerServiceId: body.partnerServiceId,
      customerNo: body.customerNo,
      virtualAccountNo: body.virtualAccountNo,
      trxId: body.trxId,
    },
  });
}

function invalidAmountResponse() {
  return snapResponse(
    { responseCode: "4042513", responseMessage: "Invalid Amount" },
    404,
  );
}

function walletChannel(paymentMethod: string) {
  return paymentMethod.split(":", 2)[1]?.trim().toLowerCase() || "";
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
  const externalId = request.headers.get("x-external-id") || "";

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
    if (!/^\d+$/.test(externalId)) {
      return snapResponse(
        { responseCode: "4002502", responseMessage: "Invalid Mandatory Field X-EXTERNAL-ID" },
        400,
      );
    }

    const referenceId = clean(body.trxId);
    const partnerServiceId = rawText(body.partnerServiceId);
    const customerNo = clean(body.customerNo);
    const virtualAccountNo = rawText(body.virtualAccountNo);
    if (
      !referenceId ||
      partnerServiceId.length !== 8 ||
      !customerNo ||
      !virtualAccountNo ||
      virtualAccountNo !== `${partnerServiceId}${customerNo}`
    ) {
      return snapResponse(
        { responseCode: "4002502", responseMessage: "Invalid Mandatory Field" },
        400,
      );
    }

    const order = await getOrderByReference(referenceId);
    const walletTopup = order ? null : await getExternalWalletTopup(referenceId, "midtrans");
    const routing: Routing | null = order
      ? await getD1().prepare(
          `SELECT payment_gateway, payment_gateway_mode, payment_gateway_environment,
                  gateway_reference_no, gateway_payment_no
           FROM orders WHERE id = ? LIMIT 1`,
        ).bind(order.id).first<Routing>()
      : walletTopup
        ? {
            payment_gateway: walletTopup.payment_gateway,
            payment_gateway_mode: walletTopup.payment_gateway_mode,
            payment_gateway_environment: walletTopup.gateway_environment,
            gateway_reference_no: walletTopup.gateway_reference_no,
            gateway_payment_no: walletTopup.gateway_payment_no,
          }
        : null;
    const expectedEnvironment = routing?.payment_gateway_environment ?? null;

    if (!partnerId || partnerId !== (expectedEnvironment
      ? getMidtransPartnerId(expectedEnvironment)
      : getMidtransPartnerId())) {
      return snapResponse(
        { responseCode: "4012500", responseMessage: "Unauthorized" },
        401,
      );
    }

    // Midtrans BI-SNAP signs SHA-256(minify(RequestBody)). Parse + stringify
    // before verification so transport whitespace does not alter the digest.
    const minifiedBody = JSON.stringify(body);
    if (!verifyMidtransNotification({
      rawBody: minifiedBody,
      timestamp,
      signature,
      endpointPath: PROVIDER_ENDPOINT,
      expectedEnvironment,
    })) {
      return snapResponse(
        { responseCode: "4012500", responseMessage: "Unauthorized" },
        401,
      );
    }

    if (!order && !walletTopup) return successResponse(body);
    if (routing?.payment_gateway !== "midtrans" || routing.payment_gateway_mode !== "bisnap") {
      return successResponse(body);
    }
    if (routing.gateway_payment_no && routing.gateway_payment_no !== virtualAccountNo) {
      return snapResponse(
        { responseCode: "4002502", responseMessage: "Invalid Virtual Account" },
        400,
      );
    }

    const expectedChannel = order?.payment_channel.toLowerCase() || walletChannel(walletTopup?.payment_method || "");
    const bank = clean(body.additionalInfo?.bank).toLowerCase();
    if (bank && expectedChannel && bank !== expectedChannel) return successResponse(body);
    const callbackReference = clean(body.referenceNo);
    if (
      routing.gateway_reference_no &&
      callbackReference &&
      routing.gateway_reference_no !== callbackReference
    ) return successResponse(body);

    const flag = clean(body.additionalInfo?.paymentFlagStatus);
    const status = mappedStatus(flag);
    const callbackAmount = paymentAmount(body);

    if (walletTopup) {
      if (status === "paid" && callbackAmount !== walletTopup.amount) return invalidAmountResponse();
      const result = status === "ignore"
        ? { found: true, credited: false }
        : await applyExternalWalletTopup({
            referenceId,
            gateway: "midtrans",
            status,
            callbackAmount: callbackAmount ?? 0,
          });
      if (result.credited) {
        await notifyWalletTopupSuccessById(walletTopup.id, referenceId).catch((error) =>
          console.error("Notifikasi top up Midtrans BI-SNAP gagal:", error),
        );
      }
      return successResponse(body);
    }

    if (!order) return successResponse(body);
    if (status === "paid" && callbackAmount !== order.total) return invalidAmountResponse();

    await recordExternalPaymentEvent({
      orderId: order.id,
      gateway: "midtrans",
      eventId: `notification-${externalId}`,
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
