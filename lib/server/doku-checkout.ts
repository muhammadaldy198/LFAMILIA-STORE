import { createHash } from "node:crypto";
import { hmacBase64 } from "@/lib/server/crypto";
import { dokuCheckoutPaymentType } from "@/lib/server/hosted-payment-methods";
import { getDokuCheckoutConfig, type PaymentEnvironment } from "@/lib/server/payment-mode-config";

export type HostedPaymentResult = {
  requestId: string;
  referenceNo: string | null;
  paymentNo: string | null;
  qrContent: string | null;
  paymentUrl: string | null;
  paymentName: string;
  expiredAt: string | null;
  raw: unknown;
};

function digest(rawBody: string) {
  return createHash("sha256").update(rawBody, "utf8").digest("base64");
}

function signature(input: {
  clientId: string;
  requestId: string;
  requestTimestamp: string;
  requestTarget: string;
  rawBody: string;
  secretKey: string;
}) {
  const value = [
    `Client-Id:${input.clientId}`,
    `Request-Id:${input.requestId}`,
    `Request-Timestamp:${input.requestTimestamp}`,
    `Request-Target:${input.requestTarget}`,
    `Digest:${digest(input.rawBody)}`,
  ].join("\n");
  return `HMACSHA256=${hmacBase64("sha256", input.secretKey, value)}`;
}

function parseExpiredDate(value: unknown) {
  const text = typeof value === "string" ? value.trim() : "";
  if (!/^\d{14}$/.test(text)) return null;
  const year = text.slice(0, 4);
  const month = text.slice(4, 6);
  const day = text.slice(6, 8);
  const hour = text.slice(8, 10);
  const minute = text.slice(10, 12);
  const second = text.slice(12, 14);
  const date = new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}+07:00`);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function normalizePhone(value: string) {
  const digits = value.replace(/\D/g, "");
  if (digits.startsWith("0")) return `62${digits.slice(1)}`;
  return digits.startsWith("62") ? digits : digits;
}

export async function getDokuCheckoutReadiness() {
  try {
    const config = await getDokuCheckoutConfig();
    return { ready: true as const, environment: config.environment, reason: null };
  } catch (error) {
    return { ready: false as const, environment: null, reason: error instanceof Error ? error.message : "DOKU Checkout belum siap." };
  }
}

export async function createDokuCheckoutPayment(input: {
  referenceId: string;
  amount: number;
  productName: string;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  paymentMethod: string;
  paymentChannel: string;
  finishUrl: string;
}) : Promise<HostedPaymentResult> {
  const paymentType = dokuCheckoutPaymentType(input.paymentMethod, input.paymentChannel);
  if (!paymentType) throw new Error("Channel ini belum didukung DOKU Checkout.");
  if (!Number.isInteger(input.amount) || input.amount <= 0) throw new Error("Nominal DOKU Checkout tidak valid.");

  const config = await getDokuCheckoutConfig();
  const requestTarget = "/checkout/v1/payment";
  const requestId = crypto.randomUUID();
  const requestTimestamp = new Date().toISOString();
  const rawBody = JSON.stringify({
    order: {
      amount: input.amount,
      invoice_number: input.referenceId,
      currency: "IDR",
      callback_url: input.finishUrl,
      callback_url_result: input.finishUrl,
      language: "ID",
      auto_redirect: true,
      disable_retry_payment: true,
      line_items: [{ id: input.referenceId.slice(-32), name: input.productName.slice(0, 255), quantity: 1, price: input.amount }],
    },
    payment: {
      payment_due_date: 60,
      payment_method_types: [paymentType],
    },
    customer: {
      id: input.referenceId.slice(-50),
      name: input.buyerName.slice(0, 255),
      email: input.buyerEmail.slice(0, 128),
      phone: normalizePhone(input.buyerPhone).slice(0, 16),
      country: "ID",
    },
  });

  const response = await fetch(`${config.apiOrigin}${requestTarget}`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      "client-id": config.clientId,
      "request-id": requestId,
      "request-timestamp": requestTimestamp,
      signature: signature({ clientId: config.clientId, requestId, requestTimestamp, requestTarget, rawBody, secretKey: config.secretKey }),
    },
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });
  const payload = await response.json().catch(() => ({})) as Record<string, any>;
  const payment = payload?.response?.payment ?? {};
  const paymentUrl = typeof payment.url === "string" ? payment.url.trim() : "";
  if (!response.ok || !paymentUrl) {
    const message = Array.isArray(payload.message) ? payload.message.join("; ") : typeof payload.message === "string" ? payload.message : "DOKU Checkout gagal membuat pembayaran.";
    throw new Error(message || "DOKU Checkout gagal membuat pembayaran.");
  }

  return {
    requestId,
    referenceNo: typeof payment.token_id === "string" ? payment.token_id : null,
    paymentNo: null,
    qrContent: null,
    paymentUrl,
    paymentName: input.paymentChannel.toUpperCase(),
    expiredAt: parseExpiredDate(payment.expired_date),
    raw: payload,
  };
}

export async function validateDokuCheckoutNotification(input: {
  rawBody: string;
  requestTarget: string;
  clientId: string | null;
  requestId: string | null;
  requestTimestamp: string | null;
  receivedSignature: string | null;
  environment: PaymentEnvironment;
}) {
  try {
    const config = await getDokuCheckoutConfigForEnvironment(input.environment);
    if (!input.clientId || input.clientId !== config.clientId || !input.requestId || !input.requestTimestamp || !input.receivedSignature) return false;
    const expected = signature({
      clientId: config.clientId,
      requestId: input.requestId,
      requestTimestamp: input.requestTimestamp,
      requestTarget: input.requestTarget,
      rawBody: input.rawBody,
      secretKey: config.secretKey,
    });
    const left = Buffer.from(input.receivedSignature.trim());
    const right = Buffer.from(expected);
    if (left.length !== right.length) return false;
    return crypto.subtle ? await crypto.subtle.digest("SHA-256", left).then(async (a) => {
      const b = await crypto.subtle.digest("SHA-256", right);
      return Buffer.from(a).equals(Buffer.from(b));
    }) : input.receivedSignature === expected;
  } catch {
    return false;
  }
}

async function getDokuCheckoutConfigForEnvironment(environment: PaymentEnvironment) {
  const { getHostedGatewayProfileForEnvironment } = await import("@/lib/server/payment-mode-config");
  const values = await getHostedGatewayProfileForEnvironment("doku", "checkout", environment);
  if (!values?.clientId || !values.secretKey) throw new Error("Kredensial DOKU Checkout tidak lengkap.");
  return {
    clientId: values.clientId,
    secretKey: values.secretKey,
    apiOrigin: environment === "production" ? "https://api.doku.com" : "https://api-sandbox.doku.com",
  };
}
