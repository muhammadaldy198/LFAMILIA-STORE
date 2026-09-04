import {
  hashHex,
  hmacBase64,
  signRsaSha256Base64,
  verifyRsaSha256Base64,
} from "@/lib/server/crypto";
import { withProviderRelayHeaders } from "@/lib/server/provider-relay";
import {
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

type MidtransBisnapEnvironment = "sandbox" | "production";

type Runtime = {
  MIDTRANS_ENV?: string;

  MIDTRANS_BISNAP_TIMEZONE_OFFSET?: string;
  MIDTRANS_BISNAP_CURRENCY?: string;
  MIDTRANS_BISNAP_COUNTRY_CODE?: string;
  MIDTRANS_BISNAP_LOCALE?: string;
  MIDTRANS_BISNAP_DEVICE_ID?: string;
  MIDTRANS_BISNAP_PAYMENT_EXPIRY_MINUTES?: string;
  MIDTRANS_BISNAP_TOKEN_EXPIRY_SAFETY_SECONDS?: string;

  MIDTRANS_BISNAP_SANDBOX_CLIENT_ID?: string;
  MIDTRANS_BISNAP_SANDBOX_PRIVATE_KEY?: string;
  MIDTRANS_BISNAP_SANDBOX_CLIENT_SECRET?: string;
  MIDTRANS_BISNAP_SANDBOX_PARTNER_ID?: string;
  MIDTRANS_BISNAP_SANDBOX_CHANNEL_ID?: string;
  MIDTRANS_BISNAP_SANDBOX_MERCHANT_ID?: string;
  MIDTRANS_BISNAP_SANDBOX_VA_PARTNER_SERVICE_ID?: string;
  MIDTRANS_BISNAP_SANDBOX_VA_RANDOMIZE?: string;
  MIDTRANS_BISNAP_SANDBOX_QRIS_ACQUIRER?: string;
  MIDTRANS_BISNAP_SANDBOX_ACCESS_TOKEN_URL?: string;
  MIDTRANS_BISNAP_SANDBOX_DIRECT_DEBIT_URL?: string;
  MIDTRANS_BISNAP_SANDBOX_QRIS_URL?: string;
  MIDTRANS_BISNAP_SANDBOX_VA_URL?: string;
  MIDTRANS_BISNAP_SANDBOX_PUBLIC_KEY?: string;

  MIDTRANS_BISNAP_PRODUCTION_CLIENT_ID?: string;
  MIDTRANS_BISNAP_PRODUCTION_PRIVATE_KEY?: string;
  MIDTRANS_BISNAP_PRODUCTION_CLIENT_SECRET?: string;
  MIDTRANS_BISNAP_PRODUCTION_PARTNER_ID?: string;
  MIDTRANS_BISNAP_PRODUCTION_CHANNEL_ID?: string;
  MIDTRANS_BISNAP_PRODUCTION_MERCHANT_ID?: string;
  MIDTRANS_BISNAP_PRODUCTION_VA_PARTNER_SERVICE_ID?: string;
  MIDTRANS_BISNAP_PRODUCTION_VA_RANDOMIZE?: string;
  MIDTRANS_BISNAP_PRODUCTION_QRIS_ACQUIRER?: string;
  MIDTRANS_BISNAP_PRODUCTION_ACCESS_TOKEN_URL?: string;
  MIDTRANS_BISNAP_PRODUCTION_DIRECT_DEBIT_URL?: string;
  MIDTRANS_BISNAP_PRODUCTION_QRIS_URL?: string;
  MIDTRANS_BISNAP_PRODUCTION_VA_URL?: string;
  MIDTRANS_BISNAP_PRODUCTION_PUBLIC_KEY?: string;
};

type EnvironmentConfig = {
  environment: MidtransBisnapEnvironment;
  clientId: string;
  privateKey: string;
  clientSecret: string;
  partnerId: string;
  channelId: string;
  merchantId: string;
  vaPartnerServiceId: string;
  vaRandomize: boolean;
  qrisAcquirer: string;
  accessTokenUrl: string;
  directDebitUrl: string;
  qrisUrl: string;
  vaUrl: string;
  publicKey?: string;
  timezoneOffset: string;
  currency: string;
  countryCode: string;
  locale: string;
  deviceId: string;
  paymentExpiryMinutes: number;
  tokenExpirySafetySeconds: number;
};

export type MidtransBisnapPaymentResult = {
  transactionId: string | null;
  paymentNo: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  raw: unknown;
};

type AccessTokenResponse = {
  responseCode?: string;
  responseMessage?: string;
  accessToken?: string;
  tokenType?: string;
  expiresIn?: string;
};

type DirectDebitResponse = {
  responseCode?: string;
  responseMessage?: string;
  referenceNo?: string;
  partnerReferenceNo?: string;
  webRedirectUrl?: string;
  appRedirectUrl?: string;
  additionalInfo?: {
    validUpTo?: string;
    paymentType?: string;
  };
};

type QrisResponse = {
  responseCode?: string;
  responseMessage?: string;
  referenceNo?: string;
  partnerReferenceNo?: string;
  qrContent?: string;
  qrUrl?: string;
  qrImage?: string;
};

type VaResponse = {
  responseCode?: string;
  responseMessage?: string;
  virtualAccountData?: {
    partnerServiceId?: string;
    customerNo?: string;
    virtualAccountNo?: string;
    trxId?: string;
    expiredDate?: string;
    additionalInfo?: Record<string, unknown>;
  };
};

type TokenCacheEntry = {
  token: string;
  expiresAt: number;
};

const tokenCache = new Map<MidtransBisnapEnvironment, TokenCacheEntry>();

function runtime() {
  return getRuntimeEnv<Runtime>();
}

function normalizePem(value: string) {
  return value.replace(/\\n/g, "\n").trim();
}

function parsePositiveInt(value: string | undefined, name: string) {
  const raw = requireRuntimeValue(value, name);
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0)
    throw new Error(`${name} harus berupa integer positif.`);
  return parsed;
}

function parseBoolean(value: string | undefined, name: string) {
  const raw = requireRuntimeChoice(value, name, ["true", "false"] as const);
  return raw === "true";
}

function commonConfig(config: Runtime) {
  return {
    timezoneOffset: requireRuntimeValue(
      config.MIDTRANS_BISNAP_TIMEZONE_OFFSET,
      "MIDTRANS_BISNAP_TIMEZONE_OFFSET",
    ),
    currency: requireRuntimeValue(
      config.MIDTRANS_BISNAP_CURRENCY,
      "MIDTRANS_BISNAP_CURRENCY",
    ),
    countryCode: requireRuntimeValue(
      config.MIDTRANS_BISNAP_COUNTRY_CODE,
      "MIDTRANS_BISNAP_COUNTRY_CODE",
    ),
    locale: requireRuntimeValue(
      config.MIDTRANS_BISNAP_LOCALE,
      "MIDTRANS_BISNAP_LOCALE",
    ),
    deviceId: requireRuntimeValue(
      config.MIDTRANS_BISNAP_DEVICE_ID,
      "MIDTRANS_BISNAP_DEVICE_ID",
    ),
    paymentExpiryMinutes: parsePositiveInt(
      config.MIDTRANS_BISNAP_PAYMENT_EXPIRY_MINUTES,
      "MIDTRANS_BISNAP_PAYMENT_EXPIRY_MINUTES",
    ),
    tokenExpirySafetySeconds: parsePositiveInt(
      config.MIDTRANS_BISNAP_TOKEN_EXPIRY_SAFETY_SECONDS,
      "MIDTRANS_BISNAP_TOKEN_EXPIRY_SAFETY_SECONDS",
    ),
  };
}

function environmentConfig(
  environment?: MidtransBisnapEnvironment,
): EnvironmentConfig {
  const config = runtime();
  const selected =
    environment ??
    requireRuntimeChoice(config.MIDTRANS_ENV, "MIDTRANS_ENV", [
      "sandbox",
      "production",
    ] as const);
  const common = commonConfig(config);

  if (selected === "sandbox") {
    return {
      environment: selected,
      clientId: requireRuntimeValue(
        config.MIDTRANS_BISNAP_SANDBOX_CLIENT_ID,
        "MIDTRANS_BISNAP_SANDBOX_CLIENT_ID",
      ),
      privateKey: normalizePem(
        requireRuntimeValue(
          config.MIDTRANS_BISNAP_SANDBOX_PRIVATE_KEY,
          "MIDTRANS_BISNAP_SANDBOX_PRIVATE_KEY",
        ),
      ),
      clientSecret: requireRuntimeValue(
        config.MIDTRANS_BISNAP_SANDBOX_CLIENT_SECRET,
        "MIDTRANS_BISNAP_SANDBOX_CLIENT_SECRET",
      ),
      partnerId: requireRuntimeValue(
        config.MIDTRANS_BISNAP_SANDBOX_PARTNER_ID,
        "MIDTRANS_BISNAP_SANDBOX_PARTNER_ID",
      ),
      channelId: requireRuntimeValue(
        config.MIDTRANS_BISNAP_SANDBOX_CHANNEL_ID,
        "MIDTRANS_BISNAP_SANDBOX_CHANNEL_ID",
      ),
      merchantId: requireRuntimeValue(
        config.MIDTRANS_BISNAP_SANDBOX_MERCHANT_ID,
        "MIDTRANS_BISNAP_SANDBOX_MERCHANT_ID",
      ),
      vaPartnerServiceId: requireRuntimeValue(
        config.MIDTRANS_BISNAP_SANDBOX_VA_PARTNER_SERVICE_ID,
        "MIDTRANS_BISNAP_SANDBOX_VA_PARTNER_SERVICE_ID",
      ).padStart(8, " "),
      vaRandomize: parseBoolean(
        config.MIDTRANS_BISNAP_SANDBOX_VA_RANDOMIZE,
        "MIDTRANS_BISNAP_SANDBOX_VA_RANDOMIZE",
      ),
      qrisAcquirer: requireRuntimeValue(
        config.MIDTRANS_BISNAP_SANDBOX_QRIS_ACQUIRER,
        "MIDTRANS_BISNAP_SANDBOX_QRIS_ACQUIRER",
      ),
      accessTokenUrl: requireHttpsUrl(
        config.MIDTRANS_BISNAP_SANDBOX_ACCESS_TOKEN_URL,
        "MIDTRANS_BISNAP_SANDBOX_ACCESS_TOKEN_URL",
      ),
      directDebitUrl: requireHttpsUrl(
        config.MIDTRANS_BISNAP_SANDBOX_DIRECT_DEBIT_URL,
        "MIDTRANS_BISNAP_SANDBOX_DIRECT_DEBIT_URL",
      ),
      qrisUrl: requireHttpsUrl(
        config.MIDTRANS_BISNAP_SANDBOX_QRIS_URL,
        "MIDTRANS_BISNAP_SANDBOX_QRIS_URL",
      ),
      vaUrl: requireHttpsUrl(
        config.MIDTRANS_BISNAP_SANDBOX_VA_URL,
        "MIDTRANS_BISNAP_SANDBOX_VA_URL",
      ),
      publicKey: config.MIDTRANS_BISNAP_SANDBOX_PUBLIC_KEY?.trim()
        ? normalizePem(config.MIDTRANS_BISNAP_SANDBOX_PUBLIC_KEY)
        : undefined,
      ...common,
    };
  }

  return {
    environment: selected,
    clientId: requireRuntimeValue(
      config.MIDTRANS_BISNAP_PRODUCTION_CLIENT_ID,
      "MIDTRANS_BISNAP_PRODUCTION_CLIENT_ID",
    ),
    privateKey: normalizePem(
      requireRuntimeValue(
        config.MIDTRANS_BISNAP_PRODUCTION_PRIVATE_KEY,
        "MIDTRANS_BISNAP_PRODUCTION_PRIVATE_KEY",
      ),
    ),
    clientSecret: requireRuntimeValue(
      config.MIDTRANS_BISNAP_PRODUCTION_CLIENT_SECRET,
      "MIDTRANS_BISNAP_PRODUCTION_CLIENT_SECRET",
    ),
    partnerId: requireRuntimeValue(
      config.MIDTRANS_BISNAP_PRODUCTION_PARTNER_ID,
      "MIDTRANS_BISNAP_PRODUCTION_PARTNER_ID",
    ),
    channelId: requireRuntimeValue(
      config.MIDTRANS_BISNAP_PRODUCTION_CHANNEL_ID,
      "MIDTRANS_BISNAP_PRODUCTION_CHANNEL_ID",
    ),
    merchantId: requireRuntimeValue(
      config.MIDTRANS_BISNAP_PRODUCTION_MERCHANT_ID,
      "MIDTRANS_BISNAP_PRODUCTION_MERCHANT_ID",
    ),
    vaPartnerServiceId: requireRuntimeValue(
      config.MIDTRANS_BISNAP_PRODUCTION_VA_PARTNER_SERVICE_ID,
      "MIDTRANS_BISNAP_PRODUCTION_VA_PARTNER_SERVICE_ID",
    ).padStart(8, " "),
    vaRandomize: parseBoolean(
      config.MIDTRANS_BISNAP_PRODUCTION_VA_RANDOMIZE,
      "MIDTRANS_BISNAP_PRODUCTION_VA_RANDOMIZE",
    ),
    qrisAcquirer: requireRuntimeValue(
      config.MIDTRANS_BISNAP_PRODUCTION_QRIS_ACQUIRER,
      "MIDTRANS_BISNAP_PRODUCTION_QRIS_ACQUIRER",
    ),
    accessTokenUrl: requireHttpsUrl(
      config.MIDTRANS_BISNAP_PRODUCTION_ACCESS_TOKEN_URL,
      "MIDTRANS_BISNAP_PRODUCTION_ACCESS_TOKEN_URL",
    ),
    directDebitUrl: requireHttpsUrl(
      config.MIDTRANS_BISNAP_PRODUCTION_DIRECT_DEBIT_URL,
      "MIDTRANS_BISNAP_PRODUCTION_DIRECT_DEBIT_URL",
    ),
    qrisUrl: requireHttpsUrl(
      config.MIDTRANS_BISNAP_PRODUCTION_QRIS_URL,
      "MIDTRANS_BISNAP_PRODUCTION_QRIS_URL",
    ),
    vaUrl: requireHttpsUrl(
      config.MIDTRANS_BISNAP_PRODUCTION_VA_URL,
      "MIDTRANS_BISNAP_PRODUCTION_VA_URL",
    ),
    publicKey: config.MIDTRANS_BISNAP_PRODUCTION_PUBLIC_KEY?.trim()
      ? normalizePem(config.MIDTRANS_BISNAP_PRODUCTION_PUBLIC_KEY)
      : undefined,
    ...common,
  };
}

function requireHttpsUrl(value: string | undefined, name: string) {
  const raw = requireRuntimeValue(value, name);
  let parsed: URL;
  try {
    parsed = new URL(raw);
  } catch {
    throw new Error(`${name} bukan URL yang valid.`);
  }
  if (parsed.protocol !== "https:")
    throw new Error(`${name} wajib menggunakan HTTPS.`);
  return parsed.toString();
}

function offsetMinutes(offset: string) {
  const match = /^([+-])(\d{2}):(\d{2})$/.exec(offset);
  if (!match)
    throw new Error(
      "MIDTRANS_BISNAP_TIMEZONE_OFFSET harus berformat +HH:MM atau -HH:MM.",
    );
  const sign = match[1] === "-" ? -1 : 1;
  return sign * (Number(match[2]) * 60 + Number(match[3]));
}

function timestamp(config: EnvironmentConfig, date = new Date()) {
  const minutes = offsetMinutes(config.timezoneOffset);
  const shifted = new Date(date.getTime() + minutes * 60_000);
  return `${shifted.toISOString().slice(0, 19)}${config.timezoneOffset}`;
}

function expiryTimestamp(config: EnvironmentConfig) {
  return timestamp(
    config,
    new Date(Date.now() + config.paymentExpiryMinutes * 60_000),
  );
}

function endpointPath(url: string) {
  const parsed = new URL(url);
  return `${parsed.pathname}${parsed.search}`;
}

function amount(value: number, currency: string) {
  if (!Number.isInteger(value) || value <= 0)
    throw new Error("Nominal BI-SNAP harus berupa integer positif.");
  return { value: `${value}.00`, currency };
}

function safeExternalId(referenceId: string) {
  const cleaned = referenceId.replace(/[^A-Za-z0-9._~-]/g, "");
  if (!cleaned) throw new Error("Reference ID BI-SNAP tidak valid.");
  return cleaned.slice(0, 36);
}

function customerNo(referenceId: string) {
  const hex = hashHex("sha256", referenceId).slice(0, 20);
  return Array.from(hex, (char) =>
    String(Number.parseInt(char, 16) % 10),
  ).join("");
}

function paymentMethod(channel: string) {
  const map: Record<string, string> = {
    gopay: "GOPAY",
    shopeepay: "SHOPEEPAY",
    dana: "DANA",
  };
  const value = map[channel];
  if (!value)
    throw new Error(
      `E-wallet ${channel} belum didukung pada Midtrans BI-SNAP.`,
    );
  return value;
}

function isSuccessfulCode(code?: string) {
  return Boolean(code && (code.startsWith("200") || code.startsWith("202")));
}

async function accessToken(config: EnvironmentConfig) {
  const cached = tokenCache.get(config.environment);
  if (cached && cached.expiresAt > Date.now()) return cached.token;

  const requestTimestamp = timestamp(config);
  const signature = signRsaSha256Base64(
    config.privateKey,
    `${config.clientId}|${requestTimestamp}`,
  );

  const rawBody = JSON.stringify({ grantType: "client_credentials" });
  const response = await fetch(config.accessTokenUrl, {
    method: "POST",
    headers: withProviderRelayHeaders(
      config.accessTokenUrl,
      {
        "content-type": "application/json",
        accept: "application/json",
        "x-timestamp": requestTimestamp,
        "x-signature": signature,
        "x-client-key": config.clientId,
      },
      { provider: "midtrans-bisnap", environment: config.environment },
    ),
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });

  const payload = (await response.json().catch(() => ({}))) as AccessTokenResponse;
  if (
    !response.ok ||
    !isSuccessfulCode(payload.responseCode) ||
    !payload.accessToken
  ) {
    throw new Error(
      payload.responseMessage ||
        "Midtrans BI-SNAP gagal memberikan B2B access token.",
    );
  }

  const expiresIn = Number(payload.expiresIn ?? 0);
  const safety = config.tokenExpirySafetySeconds;
  tokenCache.set(config.environment, {
    token: payload.accessToken,
    expiresAt:
      Date.now() + Math.max(1, expiresIn - safety) * 1000,
  });

  return payload.accessToken;
}

async function transactionalPost<T>(
  config: EnvironmentConfig,
  url: string,
  body: unknown,
  externalId: string,
  deviceId?: string,
) {
  const token = await accessToken(config);
  const rawBody = JSON.stringify(body);
  const requestTimestamp = timestamp(config);
  const bodyHash = hashHex("sha256", rawBody).toLowerCase();
  const signature = hmacBase64(
    "sha512",
    config.clientSecret,
    `POST:${endpointPath(url)}:${token}:${bodyHash}:${requestTimestamp}`,
  );

  const response = await fetch(url, {
    method: "POST",
    headers: withProviderRelayHeaders(
      url,
      {
        "content-type": "application/json",
        accept: "application/json",
        authorization: `Bearer ${token}`,
        "x-timestamp": requestTimestamp,
        "x-signature": signature,
        "x-partner-id": config.partnerId,
        "x-external-id": externalId,
        "channel-id": config.channelId,
        "x-device-id": deviceId?.trim() || config.deviceId,
      },
      { provider: "midtrans-bisnap", environment: config.environment },
    ),
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });

  const payload = (await response.json().catch(() => ({}))) as T & {
    responseCode?: string;
    responseMessage?: string;
  };

  if (!response.ok || !isSuccessfulCode(payload.responseCode)) {
    throw new Error(
      payload.responseMessage || "Midtrans BI-SNAP menolak transaksi.",
    );
  }

  return payload;
}

export async function createMidtransBisnapPayment(input: {
  referenceId: string;
  amount: number;
  productName: string;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  paymentMethod: string;
  paymentChannel: string;
  finishUrl: string;
  deviceId?: string;
}) {
  const config = environmentConfig();
  const externalId = safeExternalId(input.referenceId);
  const totalAmount = amount(input.amount, config.currency);
  const customer = {
    firstName: input.buyerName.slice(0, 50),
    email: input.buyerEmail,
    phone: input.buyerPhone,
  };
  const item = {
    id: externalId,
    price: totalAmount,
    quantity: 1,
    name: input.productName.slice(0, 50),
  };

  if (input.paymentMethod === "ewallet") {
    const provider = paymentMethod(input.paymentChannel);
    const token = await accessToken(config);
    const payload = await transactionalPost<DirectDebitResponse>(
      config,
      config.directDebitUrl,
      {
        partnerReferenceNo: externalId,
        chargeToken: token,
        merchantId: config.merchantId,
        validUpTo: expiryTimestamp(config),
        urlParams: [
          {
            url: input.finishUrl,
            type: "PAY_RETURN",
            isDeeplink: "N",
          },
        ],
        payOptionDetails: [
          {
            payMethod: provider,
            payOption: provider,
            transAmount: totalAmount,
          },
        ],
        additionalInfo: {
          customerDetails: customer,
          items: [item],
        },
      },
      externalId,
      input.deviceId,
    );

    return {
      transactionId: payload.referenceNo ?? null,
      paymentNo: null,
      paymentName: provider,
      paymentUrl: payload.webRedirectUrl ?? payload.appRedirectUrl ?? null,
      expiredAt: payload.additionalInfo?.validUpTo ?? null,
      raw: payload,
    } satisfies MidtransBisnapPaymentResult;
  }

  if (input.paymentMethod === "qris") {
    const payload = await transactionalPost<QrisResponse>(
      config,
      config.qrisUrl,
      {
        partnerReferenceNo: externalId,
        merchantId: config.merchantId,
        amount: totalAmount,
        validityPeriod: expiryTimestamp(config),
        additionalInfo: {
          acquirer: config.qrisAcquirer,
          customerDetails: customer,
          items: [item],
          countryCode: config.countryCode,
          locale: config.locale,
        },
      },
      externalId,
      input.deviceId,
    );

    return {
      transactionId: payload.referenceNo ?? null,
      paymentNo: payload.qrContent ?? null,
      paymentName: "QRIS",
      paymentUrl:
        payload.qrUrl ??
        (payload.qrImage
          ? `data:image/png;base64,${payload.qrImage}`
          : null),
      expiredAt: expiryTimestamp(config),
      raw: payload,
    } satisfies MidtransBisnapPaymentResult;
  }

  if (input.paymentMethod === "va") {
    const no = customerNo(externalId);
    const payload = await transactionalPost<VaResponse>(
      config,
      config.vaUrl,
      {
        partnerServiceId: config.vaPartnerServiceId,
        customerNo: no,
        virtualAccountNo: `${config.vaPartnerServiceId}${no}`,
        virtualAccountName: input.buyerName.slice(0, 100),
        virtualAccountEmail: input.buyerEmail,
        virtualAccountPhone: input.buyerPhone,
        trxId: externalId,
        totalAmount,
        expiredDate: expiryTimestamp(config),
        additionalInfo: {
          merchantId: config.merchantId,
          bank: input.paymentChannel,
          flags: {
            shouldRandomizeVaNumber: config.vaRandomize,
          },
          customerDetails: customer,
          items: [item],
        },
      },
      externalId,
      input.deviceId,
    );

    return {
      transactionId:
        payload.virtualAccountData?.trxId ?? externalId,
      paymentNo:
        payload.virtualAccountData?.virtualAccountNo?.trim() ?? null,
      paymentName: input.paymentChannel.toUpperCase(),
      paymentUrl: null,
      expiredAt:
        payload.virtualAccountData?.expiredDate ?? expiryTimestamp(config),
      raw: payload,
    } satisfies MidtransBisnapPaymentResult;
  }

  throw new Error("Metode pembayaran Midtrans BI-SNAP belum didukung.");
}

function notificationStringToSign(
  path: string,
  rawBody: string,
  requestTimestamp: string,
) {
  const bodyHash = hashHex("sha256", rawBody).toLowerCase();
  return `POST:${path}:${bodyHash}:${requestTimestamp}`;
}

export function validateMidtransBisnapNotification(input: {
  path: string;
  rawBody: string;
  timestamp: string | null;
  signature: string | null;
}) {
  if (!input.timestamp || !input.signature)
    return { valid: false, environment: null } as const;

  const config = runtime();
  const candidates = [
    [
      "sandbox",
      config.MIDTRANS_BISNAP_SANDBOX_PUBLIC_KEY?.trim()
        ? normalizePem(config.MIDTRANS_BISNAP_SANDBOX_PUBLIC_KEY)
        : "",
    ],
    [
      "production",
      config.MIDTRANS_BISNAP_PRODUCTION_PUBLIC_KEY?.trim()
        ? normalizePem(config.MIDTRANS_BISNAP_PRODUCTION_PUBLIC_KEY)
        : "",
    ],
  ] as const;

  const stringToSign = notificationStringToSign(
    input.path,
    input.rawBody,
    input.timestamp,
  );

  for (const [environment, publicKey] of candidates) {
    if (!publicKey) continue;
    if (
      verifyRsaSha256Base64(
        publicKey,
        stringToSign,
        input.signature,
      )
    ) {
      return { valid: true, environment } as const;
    }
  }

  return { valid: false, environment: null } as const;
}

export function mapMidtransBisnapStatus(value: unknown) {
  const status = String(value ?? "");
  if (status === "00") return "paid" as const;
  if (status === "08") return "expired" as const;
  if (["04", "05", "06", "07", "09"].includes(status))
    return "failed" as const;
  return "pending" as const;
}
