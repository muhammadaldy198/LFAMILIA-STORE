import { createHash, createHmac, createPrivateKey, createPublicKey, sign as rsaSign, verify as rsaVerify } from "node:crypto";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import { providerRelayRequest } from "@/lib/server/provider-relay";

export type MidtransEnvironment = "sandbox" | "production";

type MidtransEnv = Record<string, unknown> & {
  MIDTRANS_ENV?: string;
  MIDTRANS_SANDBOX_MERCHANT_ID?: string;
  MIDTRANS_SANDBOX_CLIENT_ID?: string;
  MIDTRANS_SANDBOX_CLIENT_SECRET?: string;
  MIDTRANS_SANDBOX_PARTNER_ID?: string;
  MIDTRANS_SANDBOX_PRIVATE_KEY?: string;
  MIDTRANS_SANDBOX_PRIVATE_KEY_PASSPHRASE?: string;
  MIDTRANS_SANDBOX_PUBLIC_KEY?: string;
  MIDTRANS_SANDBOX_CHANNEL_ID?: string;
  MIDTRANS_SANDBOX_API_URL?: string;
  MIDTRANS_PRODUCTION_MERCHANT_ID?: string;
  MIDTRANS_PRODUCTION_CLIENT_ID?: string;
  MIDTRANS_PRODUCTION_CLIENT_SECRET?: string;
  MIDTRANS_PRODUCTION_PARTNER_ID?: string;
  MIDTRANS_PRODUCTION_PRIVATE_KEY?: string;
  MIDTRANS_PRODUCTION_PRIVATE_KEY_PASSPHRASE?: string;
  MIDTRANS_PRODUCTION_PUBLIC_KEY?: string;
  MIDTRANS_PRODUCTION_CHANNEL_ID?: string;
  MIDTRANS_PRODUCTION_API_URL?: string;
};

type MidtransConfig = {
  environment: MidtransEnvironment;
  apiOrigin: string;
  merchantId: string;
  clientId: string;
  clientSecret: string;
  partnerId: string;
  privateKey: string;
  privateKeyPassphrase: string;
  midtransPublicKey: string;
  channelId: string;
};

type TokenResponse = {
  responseCode?: string;
  responseMessage?: string;
  accessToken?: string;
  tokenType?: string;
  expiresIn?: string;
  referenceNo?: string;
};

type VirtualAccountResponse = {
  responseCode?: string;
  responseMessage?: string;
  referenceNo?: string;
  virtualAccountData?: {
    partnerServiceId?: string;
    customerNo?: string;
    virtualAccountNo?: string;
    virtualAccountName?: string;
    trxId?: string;
    totalAmount?: { value?: string; currency?: string };
    expiredDate?: string;
    additionalInfo?: Record<string, unknown>;
  };
};

const tokenCache = new Map<string, { token: string; expiresAt: number }>();
const VA_BANKS = new Set(["bca", "mandiri", "bni", "bri", "permata", "cimb", "danamon"]);

function runtime() {
  return getRuntimeEnv<MidtransEnv>();
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim() : "";
}

export function getMidtransEnvironment(): MidtransEnvironment {
  return text(runtime().MIDTRANS_ENV).toLowerCase() === "production" ? "production" : "sandbox";
}

function defaultOrigin(environment: MidtransEnvironment) {
  return environment === "production"
    ? "https://merchants.midtrans.com"
    : "https://merchants.sbx.midtrans.com";
}

function officialHost(environment: MidtransEnvironment) {
  return environment === "production" ? "merchants.midtrans.com" : "merchants.sbx.midtrans.com";
}

function normalizeApiOrigin(value: string, environment: MidtransEnvironment) {
  const parsed = new URL(value || defaultOrigin(environment));
  if (parsed.protocol !== "https:" || parsed.hostname !== officialHost(environment)) {
    throw new Error("API URL Midtrans harus menggunakan domain BI-SNAP resmi sesuai environment.");
  }
  return parsed.origin;
}

function getConfig(environment: MidtransEnvironment = getMidtransEnvironment()): MidtransConfig {
  const env = runtime();
  const prefix = `MIDTRANS_${environment.toUpperCase()}_`;
  const read = (key: string) => text(env[`${prefix}${key}`]);
  return {
    environment,
    apiOrigin: normalizeApiOrigin(read("API_URL"), environment),
    merchantId: read("MERCHANT_ID"),
    clientId: read("CLIENT_ID"),
    clientSecret: read("CLIENT_SECRET"),
    partnerId: read("PARTNER_ID"),
    privateKey: read("PRIVATE_KEY"),
    privateKeyPassphrase: read("PRIVATE_KEY_PASSPHRASE"),
    midtransPublicKey: read("PUBLIC_KEY"),
    channelId: read("CHANNEL_ID"),
  };
}

function createSigningKey(config: MidtransConfig) {
  try {
    return createPrivateKey({
      key: config.privateKey,
      format: "pem",
      passphrase: config.privateKeyPassphrase || undefined,
    });
  } catch {
    throw new Error("Private Key Midtrans tidak valid atau passphrase tidak cocok.");
  }
}

function createVerificationKey(config: MidtransConfig) {
  try {
    return createPublicKey({ key: config.midtransPublicKey, format: "pem" });
  } catch {
    throw new Error("Public Key notifikasi Midtrans tidak valid.");
  }
}

export function getMidtransReadiness(environment: MidtransEnvironment = getMidtransEnvironment()) {
  let config: MidtransConfig;
  try {
    config = getConfig(environment);
  } catch (error) {
    return {
      environment,
      ready: false,
      missing: [error instanceof Error ? error.message : "Konfigurasi Midtrans tidak valid."],
    };
  }
  const missing = [
    ["Merchant ID", config.merchantId],
    ["Client ID", config.clientId],
    ["Client Secret", config.clientSecret],
    ["Partner ID", config.partnerId],
    ["Private Key", config.privateKey],
    ["Midtrans Public Key", config.midtransPublicKey],
    ["CHANNEL-ID", config.channelId],
  ].flatMap(([label, value]) => value ? [] : [label]);
  if (config.channelId && !/^\d{5}$/.test(config.channelId)) missing.push("CHANNEL-ID harus 5 digit angka");
  if (config.privateKey) {
    try { createSigningKey(config); } catch (error) { missing.push(error instanceof Error ? error.message : "Private Key tidak valid"); }
  }
  if (config.midtransPublicKey) {
    try { createVerificationKey(config); } catch (error) { missing.push(error instanceof Error ? error.message : "Public Key tidak valid"); }
  }
  return { environment: config.environment, ready: missing.length === 0, missing };
}

export function isMidtransChannelSupported(method: string, channel: string) {
  return method === "va" && VA_BANKS.has(channel.toLowerCase());
}

function jakartaTimestamp(date = new Date()) {
  const shifted = new Date(date.getTime() + 7 * 60 * 60 * 1000);
  return `${shifted.toISOString().slice(0, 19)}+07:00`;
}

function futureJakartaTimestamp(minutes: number) {
  return jakartaTimestamp(new Date(Date.now() + minutes * 60_000));
}

function numericExternalId() {
  const random = new Uint32Array(2);
  crypto.getRandomValues(random);
  return `${Date.now()}${String(random[0] % 10_000_000).padStart(7, "0")}`;
}

function numericCustomerNo() {
  const random = new Uint32Array(2);
  crypto.getRandomValues(random);
  return `${Date.now()}${String(random[0] % 10_000_000).padStart(7, "0")}`.slice(-20);
}

function amount(value: number) {
  if (!Number.isInteger(value) || value <= 0) throw new Error("Nominal pembayaran Midtrans tidak valid.");
  return { value: `${value}.00`, currency: "IDR" };
}

function digest(rawBody: string) {
  return createHash("sha256").update(rawBody, "utf8").digest("hex").toLowerCase();
}

function hmacBase64(secret: string, value: string) {
  return createHmac("sha512", secret).update(value, "utf8").digest("base64");
}

function responseError(payload: { responseMessage?: string }, fallback: string) {
  return text(payload.responseMessage) || fallback;
}

async function getB2BToken(config: MidtransConfig) {
  const cacheKey = `${config.environment}:${config.clientId}`;
  const cached = tokenCache.get(cacheKey);
  if (cached && cached.expiresAt > Date.now() + 30_000) return cached.token;

  const endpointPath = "/v1.0/access-token/b2b";
  const requestTimestamp = jakartaTimestamp();
  const rawBody = JSON.stringify({ grantType: "client_credentials" });
  const signature = rsaSign(
    "RSA-SHA256",
    Buffer.from(`${config.clientId}|${requestTimestamp}`, "utf8"),
    createSigningKey(config),
  ).toString("base64");
  const headers: Record<string, string> = {
    "content-type": "application/json",
    accept: "application/json",
    "x-timestamp": requestTimestamp,
    "x-signature": signature,
    "x-client-key": config.clientId,
  };
  const route = providerRelayRequest(`${config.apiOrigin}${endpointPath}`, headers, {
    provider: "midtrans",
    environment: config.environment,
  });
  if (!route.relayed) throw new Error("VPS Relay Midtrans belum dikonfigurasi. BI-SNAP membutuhkan outgoing IP statis yang terdaftar.");

  const response = await fetch(route.url, {
    method: "POST",
    headers: route.headers,
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });
  const payload = (await response.json().catch(() => ({}))) as TokenResponse;
  const accessToken = text(payload.accessToken);
  if (!response.ok || payload.responseCode !== "2007300" || !accessToken) {
    throw new Error(responseError(payload, "Midtrans menolak permintaan B2B access token."));
  }
  const expiresIn = Math.max(Number(payload.expiresIn) || 900, 60);
  tokenCache.set(cacheKey, {
    token: accessToken,
    expiresAt: Date.now() + Math.max(expiresIn - 60, 30) * 1000,
  });
  return accessToken;
}

async function transactionalRequest<T extends { responseCode?: string; responseMessage?: string }>(input: {
  config: MidtransConfig;
  endpointPath: string;
  body: Record<string, unknown>;
  deviceId: string;
}) {
  const accessToken = await getB2BToken(input.config);
  const requestTimestamp = jakartaTimestamp();
  const externalId = numericExternalId();
  const rawBody = JSON.stringify(input.body);
  const stringToSign = [
    "POST",
    input.endpointPath,
    accessToken,
    digest(rawBody),
    requestTimestamp,
  ].join(":");
  const signature = hmacBase64(input.config.clientSecret, stringToSign);
  const headers: Record<string, string> = {
    "content-type": "application/json",
    accept: "application/json",
    authorization: `Bearer ${accessToken}`,
    "x-timestamp": requestTimestamp,
    "x-signature": signature,
    "x-partner-id": input.config.partnerId,
    "x-external-id": externalId,
    "channel-id": input.config.channelId,
    "x-device-id": input.deviceId.slice(0, 512) || "lfamilia-web",
  };
  const route = providerRelayRequest(`${input.config.apiOrigin}${input.endpointPath}`, headers, {
    provider: "midtrans",
    environment: input.config.environment,
  });
  if (!route.relayed) throw new Error("VPS Relay Midtrans belum dikonfigurasi. BI-SNAP membutuhkan outgoing IP statis yang terdaftar.");

  const response = await fetch(route.url, {
    method: "POST",
    headers: route.headers,
    body: rawBody,
    signal: AbortSignal.timeout(20_000),
  });
  const payload = (await response.json().catch(() => ({}))) as T;
  if (!response.ok) throw new Error(responseError(payload, `Midtrans mengembalikan HTTP ${response.status}.`));
  return { payload, externalId };
}

export async function createMidtransVirtualAccount(input: {
  referenceId: string;
  amount: number;
  channel: string;
  partnerServiceId: string;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  productName: string;
  packageLabel: string;
  deviceId: string;
  expiryMinutes?: number;
}) {
  const config = getConfig();
  const readiness = getMidtransReadiness(config.environment);
  if (!readiness.ready) throw new Error(`Konfigurasi Midtrans belum lengkap: ${readiness.missing.join(", ")}`);
  const channel = input.channel.toLowerCase();
  if (!isMidtransChannelSupported("va", channel)) throw new Error("Channel VA Midtrans tidak didukung.");
  if (input.partnerServiceId.length !== 8) throw new Error("Partner Service ID VA Midtrans harus tepat 8 karakter termasuk left-padding spasi.");

  const customerNo = numericCustomerNo();
  const expiredDate = futureJakartaTimestamp(Math.max(5, Math.min(input.expiryMinutes || 60, 1440)));
  const body = {
    partnerServiceId: input.partnerServiceId,
    customerNo,
    virtualAccountNo: `${input.partnerServiceId}${customerNo}`,
    virtualAccountName: input.buyerName.slice(0, 255),
    virtualAccountEmail: input.buyerEmail.slice(0, 255),
    virtualAccountPhone: input.buyerPhone.replace(/[^+\d]/g, "").slice(0, 30),
    trxId: input.referenceId.slice(0, 64),
    totalAmount: amount(input.amount),
    expiredDate,
    additionalInfo: {
      merchantId: config.merchantId,
      bank: channel,
      flags: { shouldRandomizeVaNumber: true },
      customerDetails: {
        firstName: input.buyerName.slice(0, 64),
        email: input.buyerEmail.slice(0, 255),
        phone: input.buyerPhone.replace(/[^+\d]/g, "").slice(0, 30),
      },
      items: [{
        id: input.referenceId.slice(0, 64),
        price: amount(input.amount),
        quantity: 1,
        name: `${input.productName} ${input.packageLabel}`.trim().slice(0, 255),
        merchantName: "LFAMILIA STORE",
      }],
    },
  };
  const result = await transactionalRequest<VirtualAccountResponse>({
    config,
    endpointPath: "/v1.0/transfer-va/create-va",
    body,
    deviceId: input.deviceId,
  });
  if (result.payload.responseCode !== "2002700") {
    throw new Error(responseError(result.payload, "Midtrans gagal membuat Virtual Account."));
  }
  const va = result.payload.virtualAccountData;
  const paymentNo = text(va?.virtualAccountNo);
  if (!paymentNo) throw new Error("Midtrans tidak mengembalikan nomor Virtual Account.");
  return {
    environment: config.environment,
    requestId: result.externalId,
    referenceNo: text(result.payload.referenceNo),
    paymentNo,
    paymentName: input.channel.toUpperCase(),
    paymentUrl: "",
    qrContent: "",
    expiredAt: text(va?.expiredDate) || expiredDate,
    raw: result.payload,
  };
}

export function verifyMidtransNotification(input: {
  rawBody: string;
  timestamp: string;
  signature: string;
  endpointPath: string;
  expectedEnvironment?: MidtransEnvironment | null;
}) {
  const config = getConfig(input.expectedEnvironment ?? getMidtransEnvironment());
  if (!input.timestamp || !input.signature) return false;
  const stringToVerify = [
    "POST",
    input.endpointPath,
    digest(input.rawBody),
    input.timestamp,
  ].join(":");
  try {
    return rsaVerify(
      "RSA-SHA256",
      Buffer.from(stringToVerify, "utf8"),
      createVerificationKey(config),
      Buffer.from(input.signature, "base64"),
    );
  } catch {
    return false;
  }
}

export function getMidtransPartnerId(environment: MidtransEnvironment = getMidtransEnvironment()) {
  return getConfig(environment).partnerId;
}
