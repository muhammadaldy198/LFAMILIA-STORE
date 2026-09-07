import { createHash, timingSafeEqual } from "node:crypto";
import { hmacBase64 } from "@/lib/server/crypto";
import {
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

export type DokuEnvironment = "sandbox" | "production";

type DokuRuntime = {
  DOKU_ENV?: string;
  DOKU_SANDBOX_CLIENT_ID?: string;
  DOKU_SANDBOX_SECRET_KEY?: string;
  DOKU_SANDBOX_API_URL?: string;
  DOKU_PRODUCTION_CLIENT_ID?: string;
  DOKU_PRODUCTION_SECRET_KEY?: string;
  DOKU_PRODUCTION_API_URL?: string;
};

type DokuCheckoutResponse = {
  response?: {
    payment?: {
      token_id?: string;
      url?: string;
      expired_date?: string;
    };
    message?: string[];
  };
  error?: {
    message?: string;
  };
};

export type DokuPaymentResult = {
  requestId: string;
  tokenId: string | null;
  paymentUrl: string;
  expiredAt: string | null;
  raw: unknown;
};

function runtime() {
  return getRuntimeEnv<DokuRuntime>();
}

export function getDokuEnvironment() {
  return requireRuntimeChoice(runtime().DOKU_ENV, "DOKU_ENV", [
    "sandbox",
    "production",
  ] as const);
}

function environmentConfig(environment: DokuEnvironment) {
  const config = runtime();
  if (environment === "sandbox") {
    return {
      environment,
      clientId: requireRuntimeValue(config.DOKU_SANDBOX_CLIENT_ID, "DOKU_SANDBOX_CLIENT_ID"),
      secretKey: requireRuntimeValue(config.DOKU_SANDBOX_SECRET_KEY, "DOKU_SANDBOX_SECRET_KEY"),
      apiUrl: config.DOKU_SANDBOX_API_URL?.trim() || "https://api-sandbox.doku.com/checkout/v1/payment",
    };
  }
  return {
    environment,
    clientId: requireRuntimeValue(config.DOKU_PRODUCTION_CLIENT_ID, "DOKU_PRODUCTION_CLIENT_ID"),
    secretKey: requireRuntimeValue(config.DOKU_PRODUCTION_SECRET_KEY, "DOKU_PRODUCTION_SECRET_KEY"),
    apiUrl: config.DOKU_PRODUCTION_API_URL?.trim() || "https://api.doku.com/checkout/v1/payment",
  };
}

function activeConfig() {
  const selected = environmentConfig(getDokuEnvironment());
  let url: URL;
  try {
    url = new URL(selected.apiUrl);
  } catch {
    throw new Error("DOKU API URL tidak valid.");
  }
  if (url.protocol !== "https:") throw new Error("DOKU API URL wajib HTTPS.");
  if (!/\/checkout\/v1\/payment\/?$/i.test(url.pathname)) {
    throw new Error("DOKU API URL harus mengarah ke /checkout/v1/payment.");
  }
  return { ...selected, apiUrl: url.toString() };
}

export function getDokuReadiness() {
  try {
    const config = activeConfig();
    return { ready: true as const, environment: config.environment, reason: null };
  } catch (error) {
    return {
      ready: false as const,
      environment: null,
      reason: error instanceof Error ? error.message : "Konfigurasi DOKU belum lengkap.",
    };
  }
}

const DOKU_METHODS: Record<string, string> = {
  "qris:mpm": "QRIS",
  "qris:qris": "QRIS",
  "ewallet:dana": "EMONEY_DANA",
  "ewallet:ovo": "EMONEY_OVO",
  "ewallet:shopeepay": "EMONEY_SHOPEE_PAY",
  "ewallet:linkaja": "EMONEY_LINKAJA",
  "ewallet:doku": "EMONEY_DOKU",
  "va:bca": "VIRTUAL_ACCOUNT_BCA",
  "va:mandiri": "VIRTUAL_ACCOUNT_BANK_MANDIRI",
  "va:bni": "VIRTUAL_ACCOUNT_BNI",
  "va:bri": "VIRTUAL_ACCOUNT_BRI",
  "va:bsi": "VIRTUAL_ACCOUNT_BANK_SYARIAH_MANDIRI",
  "va:cimb": "VIRTUAL_ACCOUNT_BANK_CIMB",
  "va:permata": "VIRTUAL_ACCOUNT_BANK_PERMATA",
  "va:danamon": "VIRTUAL_ACCOUNT_BANK_DANAMON",
  "va:btn": "VIRTUAL_ACCOUNT_BTN",
  "va:bnc": "VIRTUAL_ACCOUNT_BNC",
  "va:doku": "VIRTUAL_ACCOUNT_DOKU",
};

function paymentMethodType(method: string, channel: string) {
  return DOKU_METHODS[`${method}:${channel}`] ?? null;
}

export function isDokuChannelSupported(method: string, channel: string) {
  return Boolean(paymentMethodType(method, channel));
}

function sha256Base64(value: string) {
  return createHash("sha256").update(value, "utf8").digest("base64");
}

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, "Z");
}

function signatureComponent(input: {
  clientId: string;
  requestId: string;
  requestTimestamp: string;
  requestTarget: string;
  rawBody: string;
}) {
  return [
    `Client-Id:${input.clientId}`,
    `Request-Id:${input.requestId}`,
    `Request-Timestamp:${input.requestTimestamp}`,
    `Request-Target:${input.requestTarget}`,
    `Digest:${sha256Base64(input.rawBody)}`,
  ].join("\n");
}

function signature(secretKey: string, component: string) {
  return `HMACSHA256=${hmacBase64("sha256", secretKey, component)}`;
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
}) {
  const config = activeConfig();
  const methodType = paymentMethodType(input.paymentMethod, input.paymentChannel);
  if (!methodType) throw new Error("Channel pembayaran belum didukung DOKU Checkout.");

  const endpoint = new URL(config.apiUrl);
  const requestTarget = endpoint.pathname;
  const requestId = crypto.randomUUID();
  const requestTimestamp = timestamp();
  const body = {
    order: {
      amount: input.amount,
      invoice_number: input.referenceId,
      currency: "IDR",
      callback_url: input.finishUrl,
      callback_url_result: input.finishUrl,
      auto_redirect: true,
      line_items: [{
        id: input.referenceId.slice(0, 50),
        name: input.productName.slice(0, 255),
        price: input.amount,
        quantity: 1,
      }],
    },
    payment: {
      payment_due_date: 60,
      payment_method_types: [methodType],
    },
    customer: {
      name: input.buyerName.slice(0, 255),
      email: input.buyerEmail,
      phone: input.buyerPhone,
    },
  };
  const rawBody = JSON.stringify(body);
  const component = signatureComponent({
    clientId: config.clientId,
    requestId,
    requestTimestamp,
    requestTarget,
    rawBody,
  });

  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      "client-id": config.clientId,
      "request-id": requestId,
      "request-timestamp": requestTimestamp,
      signature: signature(config.secretKey, component),
    },
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });

  const payload = (await response.json().catch(() => ({}))) as DokuCheckoutResponse;
  const paymentUrl = payload.response?.payment?.url?.trim() || "";
  if (!response.ok || !paymentUrl) {
    throw new Error(
      payload.error?.message ||
      payload.response?.message?.join(" ") ||
      "DOKU menolak pembuatan pembayaran.",
    );
  }

  return {
    requestId,
    tokenId: payload.response?.payment?.token_id ?? null,
    paymentUrl,
    expiredAt: payload.response?.payment?.expired_date ?? null,
    raw: payload,
  } satisfies DokuPaymentResult;
}

function equalCaseSensitive(left: string | null, right: string) {
  if (!left) return false;
  const a = Buffer.from(left, "utf8");
  const b = Buffer.from(right, "utf8");
  return a.length === b.length && timingSafeEqual(a, b);
}

export function validateDokuNotification(input: {
  rawBody: string;
  requestTarget: string;
  clientId: string | null;
  requestId: string | null;
  requestTimestamp: string | null;
  receivedSignature: string | null;
}) {
  if (!input.clientId || !input.requestId || !input.requestTimestamp) {
    return { valid: false as const, environment: null };
  }
  const candidates = ["sandbox", "production"] as const;
  for (const environment of candidates) {
    let config: ReturnType<typeof environmentConfig>;
    try {
      config = environmentConfig(environment);
    } catch {
      continue;
    }
    if (config.clientId !== input.clientId) continue;
    const component = signatureComponent({
      clientId: input.clientId,
      requestId: input.requestId,
      requestTimestamp: input.requestTimestamp,
      requestTarget: input.requestTarget,
      rawBody: input.rawBody,
    });
    const expected = signature(config.secretKey, component);
    if (equalCaseSensitive(input.receivedSignature, expected)) {
      return { valid: true as const, environment };
    }
  }
  return { valid: false as const, environment: null };
}

export function mapDokuStatus(payload: Record<string, unknown>) {
  const transaction = payload.transaction;
  const status = transaction && typeof transaction === "object"
    ? String((transaction as Record<string, unknown>).status ?? "").toUpperCase()
    : "";
  if (status === "SUCCESS") return "paid" as const;
  if (status === "EXPIRED") return "expired" as const;
  // Checkout may emit FAILED while the same invoice can still be retried with
  // another payment method. Do not prematurely fail the order.
  return "pending" as const;
}
