import { createHash, timingSafeEqual } from "node:crypto";
import { hostedPaymentType } from "@/lib/server/hosted-payment-methods";
import { getHostedGatewayProfileForEnvironment, getMidtransSnapConfig, type PaymentEnvironment } from "@/lib/server/payment-mode-config";

type HostedPaymentResult = {
  requestId: string;
  referenceNo: string | null;
  paymentNo: string | null;
  qrContent: string | null;
  paymentUrl: string | null;
  paymentName: string;
  expiredAt: string | null;
  raw: unknown;
};

function wibTimestamp(date = new Date()) {
  const shifted = new Date(date.getTime() + 7 * 60 * 60 * 1000);
  return `${shifted.toISOString().slice(0, 19).replace("T", " ")} +0700`;
}

function future(minutes: number) {
  return new Date(Date.now() + minutes * 60_000).toISOString();
}

export async function getMidtransSnapReadiness() {
  try {
    const config = await getMidtransSnapConfig();
    return { ready: true as const, environment: config.environment, reason: null };
  } catch (error) {
    return { ready: false as const, environment: null, reason: error instanceof Error ? error.message : "Midtrans Snap belum siap." };
  }
}

export async function createMidtransSnapPayment(input: {
  referenceId: string;
  amount: number;
  productName: string;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  paymentMethod: string;
  paymentChannel: string;
  gatewayConfig?: Record<string, string>;
  finishUrl: string;
}): Promise<HostedPaymentResult> {
  const enabledPayment = hostedPaymentType("midtrans", input.paymentMethod, input.paymentChannel, input.gatewayConfig);
  if (!enabledPayment) throw new Error("Channel ini belum memiliki kode Midtrans Snap yang valid.");
  if (!Number.isInteger(input.amount) || input.amount <= 0) throw new Error("Nominal Midtrans tidak valid.");
  const config = await getMidtransSnapConfig();
  const payload = {
    transaction_details: { order_id: input.referenceId, gross_amount: input.amount },
    item_details: [{ id: input.referenceId.slice(-40), price: input.amount, quantity: 1, name: input.productName.slice(0, 50) }],
    customer_details: { first_name: input.buyerName.slice(0, 50), email: input.buyerEmail, phone: input.buyerPhone },
    enabled_payments: [enabledPayment],
    callbacks: { finish: input.finishUrl },
    expiry: { start_time: wibTimestamp(), unit: "minutes", duration: 60 },
    page_expiry: { unit: "minutes", duration: 60 },
  };
  const response = await fetch(`${config.snapOrigin}/snap/v1/transactions`, {
    method: "POST",
    headers: {
      accept: "application/json",
      "content-type": "application/json",
      authorization: `Basic ${Buffer.from(`${config.serverKey}:`).toString("base64")}`,
    },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(15_000),
  });
  const body = await response.json().catch(() => ({})) as Record<string, unknown>;
  const token = typeof body.token === "string" ? body.token.trim() : "";
  const redirectUrl = typeof body.redirect_url === "string" ? body.redirect_url.trim() : "";
  if (!response.ok || !token || !redirectUrl) {
    const message = Array.isArray(body.error_messages) ? body.error_messages.join("; ") : "Midtrans Snap gagal membuat pembayaran.";
    throw new Error(message || "Midtrans Snap gagal membuat pembayaran.");
  }
  return {
    requestId: token,
    referenceNo: token,
    paymentNo: null,
    qrContent: null,
    paymentUrl: redirectUrl,
    paymentName: input.paymentChannel.toUpperCase(),
    expiredAt: future(60),
    raw: body,
  };
}

export async function verifyMidtransSnapNotification(input: {
  orderId: string;
  statusCode: string;
  grossAmount: string;
  signatureKey: string;
  environment: PaymentEnvironment;
}) {
  const values = await getHostedGatewayProfileForEnvironment("midtrans", "snap", input.environment);
  const serverKey = values?.serverKey?.trim() || "";
  if (!serverKey || !input.signatureKey) return false;
  const expected = createHash("sha512")
    .update(`${input.orderId}${input.statusCode}${input.grossAmount}${serverKey}`, "utf8")
    .digest("hex");
  const left = Buffer.from(input.signatureKey.trim().toLowerCase());
  const right = Buffer.from(expected);
  return left.length === right.length && timingSafeEqual(left, right);
}

export class MidtransTransactionNotFoundError extends Error {
  constructor(message = "Transaksi Midtrans tidak ditemukan.") {
    super(message);
    this.name = "MidtransTransactionNotFoundError";
  }
}

export async function queryMidtransSnapStatus(input: {
  orderId: string;
  environment: PaymentEnvironment;
}) {
  const values = await getHostedGatewayProfileForEnvironment("midtrans", "snap", input.environment);
  const serverKey = values?.serverKey?.trim() || "";
  if (!serverKey) throw new Error(`Server Key Midtrans Snap ${input.environment} belum tersedia.`);

  const apiOrigin = input.environment === "production"
    ? "https://api.midtrans.com"
    : "https://api.sandbox.midtrans.com";
  const response = await fetch(`${apiOrigin}/v2/${encodeURIComponent(input.orderId)}/status`, {
    method: "GET",
    headers: {
      accept: "application/json",
      authorization: `Basic ${Buffer.from(`${serverKey}:`).toString("base64")}`,
    },
    signal: AbortSignal.timeout(15_000),
  });
  const raw = await response.json().catch(() => ({})) as Record<string, unknown>;
  if (!response.ok) {
    const message = typeof raw.status_message === "string"
      ? raw.status_message.trim()
      : "Midtrans belum dapat mengembalikan status transaksi.";
    if (response.status === 404) {
      throw new MidtransTransactionNotFoundError(message || "Transaksi Midtrans tidak ditemukan.");
    }
    throw new Error(message || "Midtrans belum dapat mengembalikan status transaksi.");
  }

  const responseOrderId = typeof raw.order_id === "string" ? raw.order_id.trim() : "";
  if (responseOrderId && responseOrderId !== input.orderId) {
    throw new Error("Order ID dari Midtrans tidak sesuai.");
  }

  const transactionStatus = typeof raw.transaction_status === "string" ? raw.transaction_status : "";
  const fraudStatus = typeof raw.fraud_status === "string" ? raw.fraud_status : null;
  const grossAmount = typeof raw.gross_amount === "string" || typeof raw.gross_amount === "number"
    ? Number(raw.gross_amount)
    : Number.NaN;
  const transactionId = typeof raw.transaction_id === "string" ? raw.transaction_id.trim() : "";

  return {
    status: mapMidtransSnapStatus(transactionStatus, fraudStatus),
    amount: grossAmount,
    transactionId,
    raw,
  };
}

export function mapMidtransSnapStatus(transactionStatus: string, fraudStatus: string | null) {
  const status = transactionStatus.trim().toLowerCase();
  const fraud = (fraudStatus || "").trim().toLowerCase();
  if (status === "settlement") return "paid" as const;
  if (status === "capture") return fraud === "deny" ? "failed" as const : "paid" as const;
  if (status === "pending" || status === "authorize") return "pending" as const;
  if (status === "expire") return "expired" as const;
  if (status === "cancel" || status === "deny" || status === "failure") return "failed" as const;
  return "ignore" as const;
}
