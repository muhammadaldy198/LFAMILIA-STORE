import {
  createHash,
  createPrivateKey,
  sign as rsaSign,
  timingSafeEqual,
} from "node:crypto";
import { hmacBase64 } from "@/lib/server/crypto";
import { isAutomatedTestRuntime,
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

export type DokuEnvironment = "sandbox" | "production";

type DokuRuntime = {
  DOKU_ENV?: string;
  DOKU_SANDBOX_CLIENT_ID?: string;
  DOKU_SANDBOX_SECRET_KEY?: string;
  DOKU_SANDBOX_PRIVATE_KEY?: string;
  DOKU_SANDBOX_PRIVATE_KEY_PASSPHRASE?: string;
  DOKU_SANDBOX_API_URL?: string;
  DOKU_SANDBOX_QRIS_MERCHANT_ID?: string;
  DOKU_SANDBOX_QRIS_TERMINAL_ID?: string;
  DOKU_SANDBOX_QRIS_POSTAL_CODE?: string;
  DOKU_SANDBOX_VA_CONFIG_JSON?: string;
  DOKU_PRODUCTION_CLIENT_ID?: string;
  DOKU_PRODUCTION_SECRET_KEY?: string;
  DOKU_PRODUCTION_PRIVATE_KEY?: string;
  DOKU_PRODUCTION_PRIVATE_KEY_PASSPHRASE?: string;
  DOKU_PRODUCTION_API_URL?: string;
  DOKU_PRODUCTION_QRIS_MERCHANT_ID?: string;
  DOKU_PRODUCTION_QRIS_TERMINAL_ID?: string;
  DOKU_PRODUCTION_QRIS_POSTAL_CODE?: string;
  DOKU_PRODUCTION_VA_CONFIG_JSON?: string;
};

type DirectConfig = {
  environment: DokuEnvironment;
  clientId: string;
  secretKey: string;
  privateKey: string;
  privateKeyPassphrase: string;
  apiOrigin: string;
  qrisMerchantId: string;
  qrisTerminalId: string;
  qrisPostalCode: string;
  vaConfigJson: string;
};

type TokenResponse = {
  responseCode?: string;
  responseMessage?: string;
  accessToken?: string;
  tokenType?: string;
  expiresIn?: number;
};

type QrisResponse = {
  responseCode?: string;
  responseMessage?: string;
  referenceNo?: string;
  partnerReferenceNo?: string;
  qrContent?: string;
  terminalId?: string;
  additionalInfo?: {
    validityPeriod?: string;
  };
};

type EwalletResponse = {
  responseCode?: string;
  responseMessage?: string;
  webRedirectUrl?: string;
  partnerReferenceNo?: string;
  originalReferenceNo?: string;
};

type VaResponse = {
  responseCode?: string;
  responseMessage?: string;
  virtualAccountData?: {
    partnerServiceId?: string;
    customerNo?: string;
    virtualAccountNo?: string;
    virtualAccountName?: string;
    trxId?: string;
    expiredDate?: string;
    additionalInfo?: {
      channel?: string;
      howToPayPage?: string;
      howToPayApi?: string;
    };
  };
};

type VaChannelConfig = {
  partnerServiceId: string;
  customerNo: string;
  virtualAccountNo: string;
  channel: string;
};

export type DokuDirectPaymentResult = {
  requestId: string;
  referenceNo: string | null;
  paymentNo: string | null;
  qrContent: string | null;
  paymentUrl: string | null;
  paymentName: string;
  expiredAt: string | null;
  raw: unknown;
};

type CachedToken = {
  token: string;
  expiresAt: number;
};

const tokenCache = new Map<string, CachedToken>();

function runtime() {
  return getRuntimeEnv<DokuRuntime>();
}

export function getDokuEnvironment() {
  return requireRuntimeChoice(runtime().DOKU_ENV, "DOKU_ENV", [
    "sandbox",
    "production",
  ] as const);
}

function normalizeOrigin(value: string | undefined, fallback: string) {
  const url = new URL(value?.trim() || fallback);
  if (url.protocol !== "https:") throw new Error("DOKU Direct API wajib HTTPS.");
  url.pathname = "/";
  url.search = "";
  url.hash = "";
  return url.origin;
}

function environmentConfig(environment: DokuEnvironment): DirectConfig {
  const config = runtime();
  const prefix = environment === "sandbox" ? "DOKU_SANDBOX" : "DOKU_PRODUCTION";
  const sandbox = environment === "sandbox";
  return {
    environment,
    clientId: requireRuntimeValue(
      sandbox ? config.DOKU_SANDBOX_CLIENT_ID : config.DOKU_PRODUCTION_CLIENT_ID,
      `${prefix}_CLIENT_ID`,
    ),
    secretKey: requireRuntimeValue(
      sandbox ? config.DOKU_SANDBOX_SECRET_KEY : config.DOKU_PRODUCTION_SECRET_KEY,
      `${prefix}_SECRET_KEY`,
    ),
    privateKey: requireRuntimeValue(
      sandbox ? config.DOKU_SANDBOX_PRIVATE_KEY : config.DOKU_PRODUCTION_PRIVATE_KEY,
      `${prefix}_PRIVATE_KEY`,
    ),
    privateKeyPassphrase: (
      sandbox
        ? config.DOKU_SANDBOX_PRIVATE_KEY_PASSPHRASE
        : config.DOKU_PRODUCTION_PRIVATE_KEY_PASSPHRASE
    )?.trim() || "",
    apiOrigin: normalizeOrigin(
      sandbox ? config.DOKU_SANDBOX_API_URL : config.DOKU_PRODUCTION_API_URL,
      sandbox ? "https://api-sandbox.doku.com" : "https://api.doku.com",
    ),
    qrisMerchantId: (
      sandbox
        ? config.DOKU_SANDBOX_QRIS_MERCHANT_ID
        : config.DOKU_PRODUCTION_QRIS_MERCHANT_ID
    )?.trim() || "",
    qrisTerminalId: (
      sandbox
        ? config.DOKU_SANDBOX_QRIS_TERMINAL_ID
        : config.DOKU_PRODUCTION_QRIS_TERMINAL_ID
    )?.trim() || "",
    qrisPostalCode: (
      sandbox
        ? config.DOKU_SANDBOX_QRIS_POSTAL_CODE
        : config.DOKU_PRODUCTION_QRIS_POSTAL_CODE
    )?.trim() || "",
    vaConfigJson: (
      sandbox
        ? config.DOKU_SANDBOX_VA_CONFIG_JSON
        : config.DOKU_PRODUCTION_VA_CONFIG_JSON
    )?.trim() || "",
  };
}

function activeConfig() {
  return environmentConfig(getDokuEnvironment());
}

export function getDokuReadiness() {
  try {
    const config = activeConfig();
    createSigningKey(config);
    return {
      ready: true as const,
      environment: config.environment,
      reason: null,
    };
  } catch (error) {
    return {
      ready: false as const,
      environment: null,
      reason:
        error instanceof Error
          ? error.message
          : "Konfigurasi DOKU Direct API belum lengkap.",
    };
  }
}

const VA_CHANNELS = new Set([
  "bca",
  "mandiri",
  "bni",
  "bri",
  "bsi",
  "cimb",
  "permata",
  "danamon",
  "btn",
  "bmi",
  "bag",
  "bpd_bali",
]);

export function isDokuChannelSupported(method: string, channel: string) {
  if (method === "qris") return channel === "mpm" || channel === "qris";
  if (method === "ewallet") return channel === "dana" || channel === "shopeepay";
  if (method === "va") return VA_CHANNELS.has(channel);
  return false;
}

function amount(value: number) {
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error("Nominal pembayaran DOKU tidak valid.");
  }
  return { value: `${value}.00`, currency: "IDR" };
}

function timestamp() {
  return new Date().toISOString();
}

function futureTimestamp(minutes: number) {
  return new Date(Date.now() + minutes * 60_000).toISOString();
}

function numericExternalId() {
  const random = new Uint32Array(2);
  crypto.getRandomValues(random);
  return `${Date.now()}${String(random[0] % 1_000_000).padStart(6, "0")}${String(
    random[1] % 1_000_000,
  ).padStart(6, "0")}`;
}

function createSigningKey(config: DirectConfig) {
  try {
    return createPrivateKey({
      key: config.privateKey,
      format: "pem",
      passphrase: config.privateKeyPassphrase || undefined,
    });
  } catch {
    throw new Error(
      "RSA Private Key DOKU tidak valid atau passphrase private key tidak cocok.",
    );
  }
}

function asymmetricSignature(config: DirectConfig, value: string) {
  return rsaSign("RSA-SHA256", Buffer.from(value, "utf8"), createSigningKey(config))
    .toString("base64");
}

function bodyDigest(rawBody: string) {
  return createHash("sha256").update(rawBody, "utf8").digest("hex").toLowerCase();
}

function symmetricStringToSign(input: {
  method: string;
  endpointPath: string;
  accessToken: string;
  rawBody: string;
  requestTimestamp: string;
}) {
  return [
    input.method.toUpperCase(),
    input.endpointPath,
    input.accessToken,
    bodyDigest(input.rawBody),
    input.requestTimestamp,
  ].join(":");
}

function symmetricSignature(
  secretKey: string,
  input: Parameters<typeof symmetricStringToSign>[0],
) {
  return hmacBase64("sha512", secretKey, symmetricStringToSign(input));
}

function responseError(payload: Record<string, unknown>, fallback: string) {
  const message =
    typeof payload.responseMessage === "string"
      ? payload.responseMessage.trim()
      : "";
  return message || fallback;
}

async function getB2BToken(config: DirectConfig) {
  const cacheKey = `${config.environment}:${config.clientId}`;
  const cached = tokenCache.get(cacheKey);
  if (cached && cached.expiresAt > Date.now() + 30_000) return cached.token;

  const endpointPath = "/authorization/v1/access-token/b2b";
  const requestTimestamp = timestamp();
  const rawBody = JSON.stringify({ grantType: "client_credentials" });
  const signature = asymmetricSignature(
    config,
    `${config.clientId}|${requestTimestamp}`,
  );
  const response = await fetch(`${config.apiOrigin}${endpointPath}`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      "x-client-key": config.clientId,
      "x-timestamp": requestTimestamp,
      "x-signature": signature,
    },
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });
  const payload = (await response.json().catch(() => ({}))) as TokenResponse;
  const accessToken = payload.accessToken?.trim() || "";
  if (!response.ok || !accessToken) {
    throw new Error(
      payload.responseMessage || "DOKU menolak permintaan B2B access token.",
    );
  }
  const expiresIn = Math.max(Number(payload.expiresIn) || 900, 60);
  tokenCache.set(cacheKey, {
    token: accessToken,
    expiresAt: Date.now() + Math.max(expiresIn - 60, 30) * 1000,
  });
  return accessToken;
}

async function directRequest<T>(input: {
  config: DirectConfig;
  endpointPath: string;
  body: Record<string, unknown>;
  channelId?: string;
}) {
  const accessToken = await getB2BToken(input.config);
  const requestTimestamp = timestamp();
  const requestId = numericExternalId();
  const rawBody = JSON.stringify(input.body);
  const signature = symmetricSignature(input.config.secretKey, {
    method: "POST",
    endpointPath: input.endpointPath,
    accessToken,
    rawBody,
    requestTimestamp,
  });
  const headers: Record<string, string> = {
    "content-type": "application/json",
    accept: "application/json",
    authorization: `Bearer ${accessToken}`,
    "x-partner-id": input.config.clientId,
    "x-external-id": requestId,
    "x-timestamp": requestTimestamp,
    "x-signature": signature,
  };
  if (input.channelId) headers["channel-id"] = input.channelId;

  const response = await fetch(
    `${input.config.apiOrigin}${input.endpointPath}`,
    {
      method: "POST",
      headers,
      body: rawBody,
      signal: AbortSignal.timeout(15_000),
    },
  );
  const payload = (await response.json().catch(() => ({}))) as T;
  return { response, payload, requestId };
}

function parseVaConfig(config: DirectConfig, channel: string): VaChannelConfig {
  if (!config.vaConfigJson) {
    throw new Error(
      `Konfigurasi Virtual Account ${channel.toUpperCase()} belum diisi di DOKU Direct API.`,
    );
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(config.vaConfigJson);
  } catch {
    throw new Error("Konfigurasi Virtual Account DOKU harus berupa JSON valid.");
  }
  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
    throw new Error("Konfigurasi Virtual Account DOKU tidak valid.");
  }
  const item = (parsed as Record<string, unknown>)[channel];
  if (!item || typeof item !== "object" || Array.isArray(item)) {
    throw new Error(
      `Konfigurasi Virtual Account ${channel.toUpperCase()} belum tersedia.`,
    );
  }
  const value = item as Record<string, unknown>;
  const required = (
    key: "partnerServiceId" | "customerNo" | "virtualAccountNo" | "channel",
  ) => {
    const field = typeof value[key] === "string" ? value[key].trim() : "";
    if (!field) {
      throw new Error(
        `Field ${key} untuk VA ${channel.toUpperCase()} belum diisi.`,
      );
    }
    return field;
  };
  return {
    partnerServiceId: required("partnerServiceId"),
    customerNo: required("customerNo"),
    virtualAccountNo: required("virtualAccountNo"),
    channel: required("channel"),
  };
}

function normalizePhone(value: string) {
  const digits = value.replace(/\D/g, "");
  if (digits.startsWith("0")) return `62${digits.slice(1)}`;
  if (digits.startsWith("62")) return digits;
  return digits;
}

export async function createDokuDirectPayment(input: {
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
  if (isAutomatedTestRuntime() && config.environment === "production") {
    throw new Error("DOKU production dinonaktifkan saat automated test.");
  }
  const expiresAt = futureTimestamp(60);

  if (
    input.paymentMethod === "qris" &&
    (input.paymentChannel === "mpm" || input.paymentChannel === "qris")
  ) {
    if (
      !config.qrisMerchantId ||
      !config.qrisTerminalId ||
      !/^\d{1,5}$/.test(config.qrisPostalCode)
    ) {
      throw new Error(
        "QRIS DOKU belum lengkap. Isi Merchant ID, Terminal ID, dan Postal Code di Admin Panel.",
      );
    }
    const endpointPath = "/snap-adapter/b2b/v1.0/qr/qr-mpm-generate";
    const { response, payload, requestId } = await directRequest<QrisResponse>({
      config,
      endpointPath,
      channelId: "H2H",
      body: {
        partnerReferenceNo: input.referenceId,
        amount: amount(input.amount),
        merchantId: config.qrisMerchantId,
        terminalId: config.qrisTerminalId,
        validityPeriod: expiresAt,
        additionalInfo: {
          postalCode: config.qrisPostalCode,
          feeType: "1",
        },
      },
    });
    if (!response.ok || !payload.qrContent?.trim()) {
      throw new Error(
        responseError(
          payload as Record<string, unknown>,
          "DOKU gagal membuat QRIS.",
        ),
      );
    }
    return {
      requestId,
      referenceNo: payload.referenceNo?.trim() || null,
      paymentNo: null,
      qrContent: payload.qrContent.trim(),
      paymentUrl: null,
      paymentName: "QRIS",
      expiredAt: payload.additionalInfo?.validityPeriod || expiresAt,
      raw: payload,
    } satisfies DokuDirectPaymentResult;
  }

  if (
    input.paymentMethod === "ewallet" &&
    (input.paymentChannel === "dana" || input.paymentChannel === "shopeepay")
  ) {
    const endpointPath = "/direct-debit/core/v1/debit/payment-host-to-host";
    const dokuChannel =
      input.paymentChannel === "dana"
        ? "EMONEY_DANA_SNAP"
        : "EMONEY_SHOPEE_PAY_SNAP";
    const { response, payload, requestId } = await directRequest<EwalletResponse>({
      config,
      endpointPath,
      body: {
        partnerReferenceNo: input.referenceId,
        validUpTo: expiresAt,
        pointOfInitiation: "mweb",
        urlParam: {
          url: input.finishUrl,
          type: "PAY_RETURN",
          isDeepLink: "N",
        },
        amount: amount(input.amount),
        additionalInfo: {
          channel: dokuChannel,
          orderTitle: input.productName.slice(0, 100),
          supportDeepLinkCheckoutUrl: "false",
        },
      },
    });
    const paymentUrl = payload.webRedirectUrl?.trim() || "";
    if (!response.ok || !paymentUrl) {
      throw new Error(
        responseError(
          payload as Record<string, unknown>,
          `DOKU gagal membuat pembayaran ${input.paymentChannel.toUpperCase()}.`,
        ),
      );
    }
    return {
      requestId,
      referenceNo: payload.originalReferenceNo?.trim() || null,
      paymentNo: null,
      qrContent: null,
      paymentUrl,
      paymentName:
        input.paymentChannel === "dana" ? "DANA" : "ShopeePay",
      expiredAt: expiresAt,
      raw: payload,
    } satisfies DokuDirectPaymentResult;
  }

  if (input.paymentMethod === "va" && VA_CHANNELS.has(input.paymentChannel)) {
    const va = parseVaConfig(config, input.paymentChannel);
    const endpointPath =
      "/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va";
    const { response, payload, requestId } = await directRequest<VaResponse>({
      config,
      endpointPath,
      channelId: "H2H",
      body: {
        partnerServiceId: va.partnerServiceId,
        customerNo: va.customerNo,
        virtualAccountNo: va.virtualAccountNo,
        virtualAccountName: input.buyerName.slice(0, 255),
        virtualAccountEmail: input.buyerEmail,
        virtualAccountPhone: normalizePhone(input.buyerPhone),
        trxId: input.referenceId,
        totalAmount: amount(input.amount),
        additionalInfo: {
          channel: va.channel,
          virtualAccountConfig: {
            reusableStatus: false,
            minAmount: `${input.amount}.00`,
            maxAmount: `${input.amount}.00`,
          },
        },
        virtualAccountTrxType: "C",
        expiredDate: expiresAt,
      },
    });
    const data = payload.virtualAccountData;
    const paymentNo = data?.virtualAccountNo?.trim() || "";
    if (!response.ok || !paymentNo) {
      throw new Error(
        responseError(
          payload as Record<string, unknown>,
          `DOKU gagal membuat Virtual Account ${input.paymentChannel.toUpperCase()}.`,
        ),
      );
    }
    return {
      requestId,
      referenceNo: data?.trxId?.trim() || null,
      paymentNo,
      qrContent: null,
      paymentUrl: null,
      paymentName: `Virtual Account ${input.paymentChannel.toUpperCase()}`,
      expiredAt: data?.expiredDate || expiresAt,
      raw: payload,
    } satisfies DokuDirectPaymentResult;
  }

  if (input.paymentMethod === "ewallet" && input.paymentChannel === "ovo") {
    throw new Error(
      "OVO Direct API membutuhkan account binding/tokenisasi dan belum diaktifkan untuk checkout satu kali.",
    );
  }

  throw new Error("Channel pembayaran belum didukung DOKU Direct API.");
}

function equalSignature(left: string | null, right: string) {
  if (!left) return false;
  const a = Buffer.from(left.trim(), "utf8");
  const b = Buffer.from(right, "utf8");
  return a.length === b.length && timingSafeEqual(a, b);
}

function nonSnapNotificationSignature(input: {
  secretKey: string;
  clientId: string;
  requestId: string;
  requestTimestamp: string;
  requestTarget: string;
  rawBody: string;
}) {
  const digest = createHash("sha256")
    .update(input.rawBody, "utf8")
    .digest("base64");
  const components = [
    `Client-Id:${input.clientId}`,
    `Request-Id:${input.requestId}`,
    `Request-Timestamp:${input.requestTimestamp}`,
    `Request-Target:${input.requestTarget}`,
    `Digest:${digest}`,
  ].join("\n");
  return `HMACSHA256=${hmacBase64("sha256", input.secretKey, components)}`;
}

export function validateDokuNotification(input: {
  rawBody: string;
  requestTarget: string;
  partnerId: string | null;
  requestTimestamp: string | null;
  receivedSignature: string | null;
  authorization: string | null;
  clientId: string | null;
  requestId: string | null;
  legacyTimestamp: string | null;
  legacySignature: string | null;
}) {
  const accessToken = (input.authorization || "")
    .replace(/^Bearer\s+/i, "")
    .trim();

  for (const environment of ["sandbox", "production"] as const) {
    let config: DirectConfig;
    try {
      config = environmentConfig(environment);
    } catch {
      continue;
    }

    if (
      input.partnerId &&
      input.requestTimestamp &&
      input.receivedSignature &&
      config.clientId === input.partnerId
    ) {
      const expected = symmetricSignature(config.secretKey, {
        method: "POST",
        endpointPath: input.requestTarget,
        accessToken,
        rawBody: input.rawBody,
        requestTimestamp: input.requestTimestamp,
      });
      if (equalSignature(input.receivedSignature, expected)) {
        return {
          valid: true as const,
          environment,
          scheme: "snap" as const,
        };
      }
    }

    if (
      input.clientId &&
      input.requestId &&
      input.legacyTimestamp &&
      input.legacySignature &&
      config.clientId === input.clientId
    ) {
      const expected = nonSnapNotificationSignature({
        secretKey: config.secretKey,
        clientId: input.clientId,
        requestId: input.requestId,
        requestTimestamp: input.legacyTimestamp,
        requestTarget: input.requestTarget,
        rawBody: input.rawBody,
      });
      if (equalSignature(input.legacySignature, expected)) {
        return {
          valid: true as const,
          environment,
          scheme: "non-snap" as const,
        };
      }
    }
  }

  return {
    valid: false as const,
    environment: null,
    scheme: null,
  };
}

function object(value: unknown) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
}

function numericAmount(value: unknown) {
  if (typeof value === "number") return value;
  if (typeof value === "string") return Number(value);
  const record = object(value);
  return Number(record.value ?? 0);
}

export function mapDokuStatus(payload: Record<string, unknown>) {
  const value = String(payload.latestTransactionStatus ?? "").trim();
  if (value === "00") return "paid" as const;
  if (value === "05" || value === "06") return "failed" as const;
  return "pending" as const;
}

export function parseDokuNotification(payload: Record<string, unknown>) {
  const orderData = object(payload.order);
  const transactionData = object(payload.transaction);
  const legacyReference = String(orderData.invoice_number ?? "").trim();
  if (legacyReference) {
    const legacyStatus = String(transactionData.status ?? "")
      .trim()
      .toUpperCase();
    const status =
      legacyStatus === "SUCCESS"
        ? ("paid" as const)
        : legacyStatus === "FAILED"
          ? ("failed" as const)
          : ("pending" as const);
    const vaInfo = object(payload.virtual_account_info);
    return {
      referenceId: legacyReference,
      originalRequestId:
        String(transactionData.original_request_id ?? "").trim() || null,
      status,
      amount: numericAmount(orderData.amount),
      referenceNo:
        String(vaInfo.virtual_account_number ?? "").trim() || null,
    };
  }

  const vaData = object(payload.virtualAccountData);
  if (Object.keys(vaData).length) {
    return {
      referenceId: String(vaData.trxId ?? "").trim(),
      originalRequestId: null,
      status: "paid" as const,
      amount: numericAmount(vaData.paidAmount),
      referenceNo: String(vaData.virtualAccountNo ?? "").trim() || null,
    };
  }

  return {
    referenceId: String(payload.originalPartnerReferenceNo ?? "").trim(),
    originalRequestId:
      String(payload.originalExternalId ?? "").trim() || null,
    status: mapDokuStatus(payload),
    amount: numericAmount(payload.amount ?? payload.transAmount),
    referenceNo: String(payload.originalReferenceNo ?? "").trim() || null,
  };
}

export async function queryDokuQrisStatus(input: {
  referenceId: string;
  referenceNo: string;
}) {
  const config = activeConfig();
  if (!config.qrisMerchantId) {
    throw new Error("QRIS Merchant ID DOKU belum dikonfigurasi.");
  }
  const endpointPath = "/snap-adapter/b2b/v1.0/qr/qr-mpm-query";
  const { response, payload, requestId } = await directRequest<
    Record<string, unknown>
  >({
    config,
    endpointPath,
    channelId: "H2H",
    body: {
      originalReferenceNo: input.referenceNo,
      originalPartnerReferenceNo: input.referenceId,
      serviceCode: "47",
      merchantId: config.qrisMerchantId,
    },
  });
  if (!response.ok) {
    throw new Error(responseError(payload, "Status QRIS DOKU gagal diperiksa."));
  }
  return {
    requestId,
    status: mapDokuStatus(payload),
    amount: numericAmount(payload.amount),
    raw: payload,
  };
}
