import {
  createHash,
  createPrivateKey,
  sign as rsaSign,
} from "node:crypto";
import { hmacBase64 } from "@/lib/server/crypto";
import type { DokuEnvironment } from "@/lib/server/doku";
import {
  getRuntimeEnv,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

type Runtime = {
  DOKU_SANDBOX_CLIENT_ID?: string;
  DOKU_SANDBOX_SECRET_KEY?: string;
  DOKU_SANDBOX_PRIVATE_KEY?: string;
  DOKU_SANDBOX_PRIVATE_KEY_PASSPHRASE?: string;
  DOKU_SANDBOX_API_URL?: string;
  DOKU_SANDBOX_VA_CONFIG_JSON?: string;
  DOKU_PRODUCTION_CLIENT_ID?: string;
  DOKU_PRODUCTION_SECRET_KEY?: string;
  DOKU_PRODUCTION_PRIVATE_KEY?: string;
  DOKU_PRODUCTION_PRIVATE_KEY_PASSPHRASE?: string;
  DOKU_PRODUCTION_API_URL?: string;
  DOKU_PRODUCTION_VA_CONFIG_JSON?: string;
};

type StatusConfig = {
  environment: DokuEnvironment;
  clientId: string;
  secretKey: string;
  privateKey: string;
  privateKeyPassphrase: string;
  apiOrigin: string;
  vaConfigJson: string;
};

type TokenResponse = {
  accessToken?: string;
  expiresIn?: number;
  responseMessage?: string;
};

type CachedToken = { token: string; expiresAt: number };
const tokenCache = new Map<string, CachedToken>();

export type DokuStatusResult = {
  requestId: string;
  status: "paid" | "pending" | "failed";
  amount: number;
  raw: Record<string, unknown>;
};

function configFor(environment: DokuEnvironment): StatusConfig {
  const runtime = getRuntimeEnv<Runtime>();
  const sandbox = environment === "sandbox";
  const prefix = sandbox ? "DOKU_SANDBOX" : "DOKU_PRODUCTION";
  const origin = new URL(
    (sandbox ? runtime.DOKU_SANDBOX_API_URL : runtime.DOKU_PRODUCTION_API_URL)?.trim() ||
      (sandbox ? "https://api-sandbox.doku.com" : "https://api.doku.com"),
  );
  if (origin.protocol !== "https:") throw new Error("DOKU Direct API wajib HTTPS.");
  return {
    environment,
    clientId: requireRuntimeValue(
      sandbox ? runtime.DOKU_SANDBOX_CLIENT_ID : runtime.DOKU_PRODUCTION_CLIENT_ID,
      `${prefix}_CLIENT_ID`,
    ),
    secretKey: requireRuntimeValue(
      sandbox ? runtime.DOKU_SANDBOX_SECRET_KEY : runtime.DOKU_PRODUCTION_SECRET_KEY,
      `${prefix}_SECRET_KEY`,
    ),
    privateKey: requireRuntimeValue(
      sandbox ? runtime.DOKU_SANDBOX_PRIVATE_KEY : runtime.DOKU_PRODUCTION_PRIVATE_KEY,
      `${prefix}_PRIVATE_KEY`,
    ),
    privateKeyPassphrase: (
      sandbox
        ? runtime.DOKU_SANDBOX_PRIVATE_KEY_PASSPHRASE
        : runtime.DOKU_PRODUCTION_PRIVATE_KEY_PASSPHRASE
    )?.trim() || "",
    apiOrigin: origin.origin,
    vaConfigJson: (
      sandbox ? runtime.DOKU_SANDBOX_VA_CONFIG_JSON : runtime.DOKU_PRODUCTION_VA_CONFIG_JSON
    )?.trim() || "",
  };
}

function signingKey(config: StatusConfig) {
  try {
    return createPrivateKey({
      key: config.privateKey,
      format: "pem",
      passphrase: config.privateKeyPassphrase || undefined,
    });
  } catch {
    throw new Error("RSA Private Key DOKU tidak valid atau passphrase private key tidak cocok.");
  }
}

function timestamp() {
  return new Date().toISOString();
}

function numericExternalId() {
  const random = new Uint32Array(2);
  crypto.getRandomValues(random);
  return `${Date.now()}${String(random[0] % 1_000_000).padStart(6, "0")}${String(random[1] % 1_000_000).padStart(6, "0")}`;
}

async function getB2BToken(config: StatusConfig) {
  const cacheKey = `${config.environment}:${config.clientId}`;
  const cached = tokenCache.get(cacheKey);
  if (cached && cached.expiresAt > Date.now() + 30_000) return cached.token;

  const endpointPath = "/authorization/v1/access-token/b2b";
  const requestTimestamp = timestamp();
  const signature = rsaSign(
    "RSA-SHA256",
    Buffer.from(`${config.clientId}|${requestTimestamp}`, "utf8"),
    signingKey(config),
  ).toString("base64");
  const response = await fetch(`${config.apiOrigin}${endpointPath}`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      "x-client-key": config.clientId,
      "x-timestamp": requestTimestamp,
      "x-signature": signature,
    },
    body: JSON.stringify({ grantType: "client_credentials" }),
    signal: AbortSignal.timeout(15_000),
  });
  const payload = (await response.json().catch(() => ({}))) as TokenResponse;
  const token = payload.accessToken?.trim() || "";
  if (!response.ok || !token) {
    throw new Error(payload.responseMessage || "DOKU menolak permintaan B2B access token.");
  }
  const expiresIn = Math.max(Number(payload.expiresIn) || 900, 60);
  tokenCache.set(cacheKey, {
    token,
    expiresAt: Date.now() + Math.max(expiresIn - 60, 30) * 1000,
  });
  return token;
}

function bodyDigest(rawBody: string) {
  return createHash("sha256").update(rawBody, "utf8").digest("hex").toLowerCase();
}

async function statusRequest(
  config: StatusConfig,
  endpointPath: string,
  body: Record<string, unknown>,
) {
  const accessToken = await getB2BToken(config);
  const requestTimestamp = timestamp();
  const requestId = numericExternalId();
  const rawBody = JSON.stringify(body);
  const stringToSign = [
    "POST",
    endpointPath,
    accessToken,
    bodyDigest(rawBody),
    requestTimestamp,
  ].join(":");
  const signature = hmacBase64("sha512", config.secretKey, stringToSign);
  const response = await fetch(`${config.apiOrigin}${endpointPath}`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      authorization: `Bearer ${accessToken}`,
      "x-partner-id": config.clientId,
      "x-external-id": requestId,
      "x-timestamp": requestTimestamp,
      "x-signature": signature,
    },
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });
  const payload = (await response.json().catch(() => ({}))) as Record<string, unknown>;
  if (!response.ok) {
    const message = typeof payload.responseMessage === "string" && payload.responseMessage.trim()
      ? payload.responseMessage.trim()
      : "DOKU gagal memeriksa status transaksi.";
    throw new Error(message);
  }
  return { payload, requestId };
}

function object(value: unknown) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};
}

function numericAmount(value: unknown) {
  if (typeof value === "number") return value;
  if (typeof value === "string") return Number(value);
  return Number(object(value).value ?? 0);
}

function statusFromCode(value: unknown) {
  const code = String(value ?? "").trim();
  if (code === "00") return "paid" as const;
  if (code === "05" || code === "06") return "failed" as const;
  return "pending" as const;
}

function parseVaChannel(config: StatusConfig, channel: string) {
  if (!config.vaConfigJson) throw new Error(`Konfigurasi Virtual Account ${channel.toUpperCase()} belum diisi.`);
  let parsed: unknown;
  try {
    parsed = JSON.parse(config.vaConfigJson);
  } catch {
    throw new Error("Konfigurasi Virtual Account DOKU harus berupa JSON valid.");
  }
  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("Konfigurasi Virtual Account DOKU tidak valid.");
  const value = object((parsed as Record<string, unknown>)[channel]);
  const partnerServiceId = String(value.partnerServiceId ?? "").trim();
  const customerNo = String(value.customerNo ?? "").trim();
  if (!partnerServiceId || !customerNo) throw new Error(`Konfigurasi status VA ${channel.toUpperCase()} belum lengkap.`);
  return { partnerServiceId, customerNo };
}

/** Official SNAP Check Status API: POST /orders/v1.0/transfer-va/status. */
export async function queryDokuVaStatus(input: {
  environment: DokuEnvironment;
  channel: string;
  paymentNo: string;
}) : Promise<DokuStatusResult> {
  const config = configFor(input.environment);
  const va = parseVaChannel(config, input.channel);
  const { payload, requestId } = await statusRequest(
    config,
    "/orders/v1.0/transfer-va/status",
    {
      partnerServiceId: va.partnerServiceId,
      customerNo: va.customerNo,
      virtualAccountNo: input.paymentNo,
    },
  );
  const data = object(payload.virtualAccountData);
  const reason = object(data.paymentFlagReason);
  const paidAmount = numericAmount(data.paidAmount);
  let status = statusFromCode(data.paymentFlagStatus ?? object(data.additionalInfo).latestTransactionStatus);
  if (
    status === "pending" &&
    String(reason.english ?? "").trim().toUpperCase() === "SUCCESS" &&
    Number.isFinite(paidAmount) && paidAmount > 0
  ) {
    status = "paid";
  }
  return { requestId, status, amount: paidAmount, raw: payload };
}

/** Official SNAP E-Wallet Check Status API: POST /orders/v1.0/debit/status, serviceCode 55. */
export async function queryDokuEwalletStatus(input: {
  environment: DokuEnvironment;
  referenceId: string;
  requestId: string;
  referenceNo: string | null;
  amount: number;
}) : Promise<DokuStatusResult> {
  const config = configFor(input.environment);
  const body: Record<string, unknown> = {
    originalPartnerReferenceNo: input.referenceId,
    originalExternalId: input.requestId,
    serviceCode: "55",
    amount: { value: `${input.amount}.00`, currency: "IDR" },
  };
  if (input.referenceNo) body.originalReferenceNo = input.referenceNo;
  const result = await statusRequest(config, "/orders/v1.0/debit/status", body);
  return {
    requestId: result.requestId,
    status: statusFromCode(result.payload.latestTransactionStatus),
    amount: numericAmount(result.payload.transAmount ?? result.payload.amount),
    raw: result.payload,
  };
}
