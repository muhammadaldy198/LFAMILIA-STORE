import { createHash, timingSafeEqual } from "node:crypto";
import { hmacBase64 } from "@/lib/server/crypto";
import { dokuCheckoutPaymentType, isDokuCheckoutPaymentTypeCompatible } from "@/lib/server/doku-checkout-payment-types.mjs";
import { getRuntimeEnv, requireRuntimeChoice, requireRuntimeValue } from "@/lib/server/runtime-env";
import { safeHttpsOrigin } from "@/lib/server/outbound-url";

export type DokuEnvironment = "sandbox" | "production";

type Runtime = {
  DOKU_ENV?: string;
  DOKU_CHECKOUT_SANDBOX_CLIENT_ID?: string;
  DOKU_CHECKOUT_SANDBOX_SECRET_KEY?: string;
  DOKU_CHECKOUT_SANDBOX_API_URL?: string;
  DOKU_CHECKOUT_PRODUCTION_CLIENT_ID?: string;
  DOKU_CHECKOUT_PRODUCTION_SECRET_KEY?: string;
  DOKU_CHECKOUT_PRODUCTION_API_URL?: string;
};

type CheckoutConfig = {
  environment: DokuEnvironment;
  clientId: string;
  secretKey: string;
  apiOrigin: string;
};

type CheckoutResponse = {
  message?: string[];
  error_messages?: string[];
  response?: {
    order?: {
      invoice_number?: string;
      session_id?: string;
    };
    payment?: {
      url?: string;
      expired_date?: string;
    };
  };
};

type StatusPayload = {
  order?: {
    invoice_number?: string;
    amount?: number | string;
    status?: string;
  };
  transaction?: {
    status?: string;
    original_request_id?: string;
  };
  [key: string]: unknown;
};

export type DokuCheckoutPaymentResult = {
  requestId: string;
  referenceNo: string | null;
  paymentNo: string | null;
  qrContent: string | null;
  paymentUrl: string | null;
  paymentName: string;
  expiredAt: string | null;
  raw: unknown;
};

export { dokuCheckoutPaymentType, isDokuCheckoutPaymentTypeCompatible };

function runtime() {
  return getRuntimeEnv<Runtime>();
}

function normalizeOrigin(value: string | undefined, fallback: string) {
  return safeHttpsOrigin(value?.trim() || fallback, "URL API DOKU Checkout");
}

function environmentConfig(environment: DokuEnvironment): CheckoutConfig {
  const current = runtime();
  const sandbox = environment === "sandbox";
  const prefix = sandbox ? "DOKU_CHECKOUT_SANDBOX" : "DOKU_CHECKOUT_PRODUCTION";
  return {
    environment,
    clientId: requireRuntimeValue(
      sandbox ? current.DOKU_CHECKOUT_SANDBOX_CLIENT_ID : current.DOKU_CHECKOUT_PRODUCTION_CLIENT_ID,
      `${prefix}_CLIENT_ID`,
    ),
    secretKey: requireRuntimeValue(
      sandbox ? current.DOKU_CHECKOUT_SANDBOX_SECRET_KEY : current.DOKU_CHECKOUT_PRODUCTION_SECRET_KEY,
      `${prefix}_SECRET_KEY`,
    ),
    apiOrigin: normalizeOrigin(
      sandbox ? current.DOKU_CHECKOUT_SANDBOX_API_URL : current.DOKU_CHECKOUT_PRODUCTION_API_URL,
      sandbox ? "https://api-sandbox.doku.com" : "https://api.doku.com",
    ),
  };
}

export function getDokuEnvironment() {
  return requireRuntimeChoice(runtime().DOKU_ENV, "DOKU_ENV", ["sandbox", "production"] as const);
}

function activeConfig() {
  return environmentConfig(getDokuEnvironment());
}

function digest(rawBody: string) {
  return createHash("sha256").update(rawBody, "utf8").digest("base64");
}

function signature(input: {
  clientId: string;
  secretKey: string;
  requestId: string;
  requestTimestamp: string;
  requestTarget: string;
  rawBody?: string;
}) {
  const components = [
    `Client-Id:${input.clientId}`,
    `Request-Id:${input.requestId}`,
    `Request-Timestamp:${input.requestTimestamp}`,
    `Request-Target:${input.requestTarget}`,
  ];
  if (input.rawBody !== undefined) components.push(`Digest:${digest(input.rawBody)}`);
  return `HMACSHA256=${hmacBase64("sha256", input.secretKey, components.join("\n"))}`;
}

function equalSignature(left: string | null, right: string) {
  if (!left) return false;
  const a = Buffer.from(left.trim(), "utf8");
  const b = Buffer.from(right, "utf8");
  return a.length === b.length && timingSafeEqual(a, b);
}

function checkoutExpiry(value: string | undefined) {
  const raw = value?.trim() || "";
  if (!/^\d{14}$/.test(raw)) return null;
  const iso = `${raw.slice(0, 4)}-${raw.slice(4, 6)}-${raw.slice(6, 8)}T${raw.slice(8, 10)}:${raw.slice(10, 12)}:${raw.slice(12, 14)}+07:00`;
  const time = Date.parse(iso);
  return Number.isFinite(time) ? new Date(time).toISOString() : null;
}

function status(
  value: unknown,
  orderStatus?: unknown,
): "paid" | "pending" | "expired" | "failed" {
  const normalized = typeof value === "string" ? value.trim().toUpperCase() : "";
  const normalizedOrder = typeof orderStatus === "string" ? orderStatus.trim().toUpperCase() : "";
  if (normalized === "SUCCESS") return "paid";
  if (normalized === "EXPIRED" || normalizedOrder === "ORDER_EXPIRED") return "expired";
  // For DOKU Checkout, DOKU explicitly instructs merchants to ignore a
  // transaction.status FAILED because the customer may retry or change the
  // payment method. TIMEOUT and REDIRECT are also non-final.
  return "pending";
}

export function isDokuCheckoutChannelSupported(
  method: string,
  channel: string,
  gatewayConfig?: Record<string, string>,
) {
  return Boolean(dokuCheckoutPaymentType(method, channel, gatewayConfig));
}

export function getDokuCheckoutReadiness() {
  try {
    const config = activeConfig();
    return { ready: true as const, environment: config.environment, reason: null };
  } catch (error) {
    return {
      ready: false as const,
      environment: null,
      reason: error instanceof Error ? error.message : "Konfigurasi DOKU Checkout belum lengkap.",
    };
  }
}

export async function createDokuCheckoutPayment(input: {
  referenceId: string;
  amount: number;
  paymentMethod: string;
  paymentChannel: string;
  gatewayConfig?: Record<string, string>;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  productName: string;
  finishUrl: string;
}) {
  const config = activeConfig();
  const paymentType = dokuCheckoutPaymentType(
    input.paymentMethod,
    input.paymentChannel,
    input.gatewayConfig,
  );
  if (!paymentType) throw new Error("Channel belum didukung DOKU Checkout.");

  if (!Number.isInteger(input.amount) || input.amount <= 0) {
    throw new Error("Nominal pembayaran DOKU tidak valid.");
  }

  const endpointPath = "/checkout/v1/payment";
  const paymentDueMinutes = 60;
  const requestId = crypto.randomUUID();
  const requestTimestamp = new Date().toISOString();
  const returnUrl = new URL(input.finishUrl);
  const notificationUrl = `${returnUrl.origin}/api/payments/doku/callback`;
  const rawBody = JSON.stringify({
    order: {
      amount: input.amount,
      invoice_number: input.referenceId,
      currency: "IDR",
      callback_url: input.finishUrl,
      callback_url_result: input.finishUrl,
      language: "ID",
      auto_redirect: true,
    },
    payment: {
      payment_due_date: paymentDueMinutes,
      payment_method_types: [paymentType],
    },
    additional_info: {
      override_notification_url: notificationUrl,
    },
  });

  const response = await fetch(`${config.apiOrigin}${endpointPath}`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      "client-id": config.clientId,
      "request-id": requestId,
      "request-timestamp": requestTimestamp,
      signature: signature({
        clientId: config.clientId,
        secretKey: config.secretKey,
        requestId,
        requestTimestamp,
        requestTarget: endpointPath,
        rawBody,
      }),
    },
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });

  const payload = await response.json().catch(() => ({})) as CheckoutResponse;
  const paymentUrl = payload.response?.payment?.url?.trim() || "";
  if (!response.ok || !paymentUrl) {
    const messages = Array.isArray(payload.error_messages)
      ? payload.error_messages
      : Array.isArray(payload.message)
        ? payload.message
        : [];
    throw new Error(messages.filter(Boolean).join("; ") || "DOKU gagal membuat Checkout.");
  }

  return {
    requestId,
    referenceNo: payload.response?.order?.session_id?.trim() || null,
    paymentNo: null,
    qrContent: null,
    paymentUrl,
    paymentName: input.paymentMethod === "qris"
      ? "QRIS"
      : input.paymentMethod === "va"
        ? `Virtual Account ${input.paymentChannel.toUpperCase()}`
        : input.paymentChannel.toUpperCase(),
    expiredAt: checkoutExpiry(payload.response?.payment?.expired_date)
      ?? new Date(Date.now() + paymentDueMinutes * 60_000).toISOString(),
    raw: payload,
  } satisfies DokuCheckoutPaymentResult;
}

export async function queryDokuCheckoutStatus(input: {
  referenceId: string;
  environment: DokuEnvironment;
}) {
  const config = environmentConfig(input.environment);
  const endpointPath = `/orders/v1/status/${encodeURIComponent(input.referenceId)}`;
  const requestId = crypto.randomUUID();
  const requestTimestamp = new Date().toISOString();
  const response = await fetch(`${config.apiOrigin}${endpointPath}`, {
    method: "GET",
    headers: {
      accept: "application/json",
      "client-id": config.clientId,
      "request-id": requestId,
      "request-timestamp": requestTimestamp,
      signature: signature({
        clientId: config.clientId,
        secretKey: config.secretKey,
        requestId,
        requestTimestamp,
        requestTarget: endpointPath,
      }),
    },
    signal: AbortSignal.timeout(15_000),
  });
  const payload = await response.json().catch(() => ({})) as StatusPayload;
  if (!response.ok) throw new Error("Status DOKU Checkout belum dapat diperiksa.");
  const amount = Number(payload.order?.amount);
  return {
    requestId,
    status: status(payload.transaction?.status, payload.order?.status),
    amount: Number.isFinite(amount) ? amount : 0,
    originalRequestId: payload.transaction?.original_request_id?.trim() || null,
    raw: payload,
  };
}

export function parseDokuCheckoutNotification(payload: Record<string, unknown>) {
  const order = payload.order && typeof payload.order === "object" && !Array.isArray(payload.order)
    ? payload.order as Record<string, unknown>
    : {};
  const transaction = payload.transaction && typeof payload.transaction === "object" && !Array.isArray(payload.transaction)
    ? payload.transaction as Record<string, unknown>
    : {};
  const amount = Number(order.amount);
  return {
    referenceId: typeof order.invoice_number === "string" ? order.invoice_number.trim() : "",
    amount: Number.isFinite(amount) ? amount : 0,
    originalRequestId: typeof transaction.original_request_id === "string"
      ? transaction.original_request_id.trim()
      : null,
    status: status(transaction.status, order.status),
  };
}

export function validateDokuCheckoutNotification(input: {
  rawBody: string;
  requestTarget: string;
  clientId: string | null;
  requestId: string | null;
  requestTimestamp: string | null;
  receivedSignature: string | null;
  expectedEnvironment?: DokuEnvironment | null;
}) {
  const environments: DokuEnvironment[] = input.expectedEnvironment
    ? [input.expectedEnvironment]
    : ["sandbox", "production"];

  for (const environment of environments) {
    try {
      const config = environmentConfig(environment);
      if (!input.clientId || input.clientId !== config.clientId) continue;
      if (!input.requestId || !input.requestTimestamp) continue;
      const expected = signature({
        clientId: config.clientId,
        secretKey: config.secretKey,
        requestId: input.requestId,
        requestTimestamp: input.requestTimestamp,
        requestTarget: input.requestTarget,
        rawBody: input.rawBody,
      });
      if (equalSignature(input.receivedSignature, expected)) {
        return { valid: true as const, environment };
      }
    } catch {
      // Try another hydrated environment when available.
    }
  }

  return { valid: false as const, environment: null };
}
