import { hashHex, hmacHex, safeEqual } from "@/lib/server/crypto";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import { randomUUID } from "node:crypto";

type IpaymuEnv = {
  IPAYMU_ENV?: string;
  IPAYMU_VA?: string;
  IPAYMU_API_KEY?: string;
  IPAYMU_API_BASE_URL?: string;
  IPAYMU_RELAY_SECRET?: string;
};

export type IpaymuDirectResult = {
  transactionId: string | null;
  referenceId: string;
  paymentNo: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  fee: number;
  total: number;
  expiredAt: string | null;
  raw: unknown;
};

type DirectResponse = {
  Status?: number;
  Success?: boolean;
  Message?: string;
  Data?: {
    TransactionId?: string | number;
    ReferenceId?: string;
    PaymentNo?: string | number;
    PaymentName?: string;
    Total?: string | number;
    Fee?: string | number;
    Expired?: string;
    Url?: string;
  };
};

function runtimeConfig() {
  const runtime = getRuntimeEnv<IpaymuEnv>();
  const va = runtime.IPAYMU_VA?.trim();
  const apiKey = runtime.IPAYMU_API_KEY?.trim();
  if (!va || !apiKey) throw new Error("Secret iPaymu VA dan API Key belum dikonfigurasi.");
  const defaultBase = runtime.IPAYMU_ENV === "production" ? "https://my.ipaymu.com" : "https://sandbox.ipaymu.com";
  const baseUrl = (runtime.IPAYMU_API_BASE_URL?.trim() || defaultBase).replace(/\/$/, "");
  const relaySecret = runtime.IPAYMU_RELAY_SECRET?.trim();
  let parsedBase: URL;
  try {
    parsedBase = new URL(baseUrl);
  } catch {
    throw new Error("IPAYMU_API_BASE_URL tidak valid.");
  }
  if (parsedBase.protocol !== "https:") throw new Error("Endpoint iPaymu wajib memakai HTTPS.");
  const isOfficialHost = parsedBase.hostname === "my.ipaymu.com" || parsedBase.hostname === "sandbox.ipaymu.com";
  if (!isOfficialHost && (!relaySecret || relaySecret.length < 32)) {
    throw new Error("IPAYMU_RELAY_SECRET minimal 32 karakter wajib diisi untuk relay IP statis.");
  }
  return { va, apiKey, baseUrl, relaySecret: isOfficialHost ? undefined : relaySecret };
}

function timestamp() {
  return new Date().toISOString().replace(/[-:T]/g, "").slice(0, 14);
}

export async function createIpaymuDirectPayment(input: {
  name: string;
  phone: string;
  email: string;
  amount: number;
  notifyUrl: string;
  referenceId: string;
  paymentMethod: string;
  paymentChannel: string;
  productName: string;
  productPrice: number;
}) {
  const { va, apiKey, baseUrl, relaySecret } = runtimeConfig();
  const body = {
    name: input.name,
    phone: input.phone,
    email: input.email,
    amount: input.amount,
    notifyUrl: input.notifyUrl,
    referenceId: input.referenceId,
    paymentMethod: input.paymentMethod,
    paymentChannel: input.paymentChannel,
    feeDirection: "BUYER",
    comments: `LFAMILIA STORE ${input.referenceId}`,
    product: [input.productName],
    qty: [1],
    price: [input.productPrice],
  };
  const rawBody = JSON.stringify(body);
  const bodyHash = hashHex("sha256", rawBody);
  const signature = hmacHex("sha256", apiKey, `POST:${va}:${bodyHash}:${apiKey}`);
  const requestPath = "/api/v2/payment/direct";
  const headers: Record<string, string> = {
    "content-type": "application/json",
    accept: "application/json",
    va,
    signature,
    timestamp: timestamp(),
  };
  if (relaySecret) {
    const relayTimestamp = Math.floor(Date.now() / 1000).toString();
    const relayNonce = randomUUID();
    const relayPayload = `${relayTimestamp}\n${relayNonce}\nPOST\n${requestPath}\n${rawBody}`;
    headers["x-lfamilia-relay-secret"] = relaySecret;
    headers["x-lfamilia-relay-timestamp"] = relayTimestamp;
    headers["x-lfamilia-relay-nonce"] = relayNonce;
    headers["x-lfamilia-relay-signature"] = hmacHex("sha256", relaySecret, relayPayload);
  }
  const response = await fetch(`${baseUrl}${requestPath}`, {
    method: "POST",
    headers,
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });
  const payload = await response.json() as DirectResponse;
  if (!response.ok || payload.Success === false || !payload.Data) {
    throw new Error(payload.Message || "iPaymu menolak pembuatan pembayaran.");
  }
  const data = payload.Data;
  const fee = Math.max(0, Number(data.Fee ?? 0));
  const total = Math.max(input.amount, Number(data.Total ?? input.amount + fee));
  return {
    transactionId: data.TransactionId == null ? null : String(data.TransactionId),
    referenceId: data.ReferenceId || input.referenceId,
    paymentNo: data.PaymentNo == null ? null : String(data.PaymentNo),
    paymentName: data.PaymentName || null,
    paymentUrl: data.Url || null,
    fee,
    total,
    expiredAt: data.Expired || null,
    raw: payload,
  } satisfies IpaymuDirectResult;
}

export function parseIpaymuCallback(rawBody: string, contentType: string | null) {
  if (contentType?.includes("application/json")) return JSON.parse(rawBody) as Record<string, unknown>;
  return Object.fromEntries(new URLSearchParams(rawBody).entries()) as Record<string, unknown>;
}

function normalizeCallback(raw: Record<string, unknown>) {
  const result: Record<string, unknown> = {};
  const integerFields = new Set(["trx_id", "status_code", "transaction_status_code", "paid_off"]);
  for (const [key, rawValue] of Object.entries(raw)) {
    if (key === "signature") continue;
    if (key === "is_escrow") {
      result[key] = rawValue === true || rawValue === 1 || rawValue === "1" || rawValue === "true";
    } else if (integerFields.has(key)) {
      result[key] = Number.parseInt(String(rawValue), 10);
    } else if (key === "additional_info") {
      if (Array.isArray(rawValue)) result[key] = rawValue;
      else if (rawValue === "[]" || rawValue == null || rawValue === "") result[key] = [];
      else {
        try { result[key] = JSON.parse(String(rawValue)); } catch { result[key] = String(rawValue); }
      }
    } else {
      result[key] = String(rawValue ?? "");
    }
  }
  if (!("additional_info" in result)) result.additional_info = [];
  return Object.keys(result).sort((a, b) => a.localeCompare(b)).reduce<Record<string, unknown>>((sorted, key) => {
    sorted[key] = result[key];
    return sorted;
  }, {});
}

export function validateIpaymuCallback(raw: Record<string, unknown>, receivedSignature: string | null) {
  const { va } = runtimeConfig();
  const normalized = normalizeCallback(raw);
  const signedBody = JSON.stringify(normalized).replace(/\//g, "\\/");
  const expected = hmacHex("sha256", va, signedBody);
  return { valid: safeEqual(receivedSignature, expected), normalized };
}

export function mapIpaymuStatus(payload: Record<string, unknown>) {
  const status = String(payload.status ?? "").toLowerCase();
  const statusCode = Number(payload.status_code);
  const transactionCode = Number(payload.transaction_status_code);
  if (statusCode === 1 || status === "berhasil" || transactionCode === 1 || transactionCode === 6) return "paid" as const;
  if (statusCode === -2 || status === "expired") return "expired" as const;
  if (status === "gagal" || status === "failed") return "failed" as const;
  return "pending" as const;
}
