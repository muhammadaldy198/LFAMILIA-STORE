import { hashHex, hmacHex, safeEqual } from "@/lib/server/crypto";
import { withProviderRelayHeaders } from "@/lib/server/provider-relay";
import {
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

type IpaymuEnvironment = "sandbox" | "production";

type IpaymuRuntime = {
  IPAYMU_ENV?: string;
  IPAYMU_SANDBOX_VA?: string;
  IPAYMU_SANDBOX_API_KEY?: string;
  IPAYMU_SANDBOX_API_URL?: string;
  IPAYMU_PRODUCTION_VA?: string;
  IPAYMU_PRODUCTION_API_KEY?: string;
  IPAYMU_PRODUCTION_API_URL?: string;
};

export function isIpaymuChannelSupported(method: string, channel: string) {
  if (method === "qris") return channel === "mpm";
  if (method === "ewallet")
    return channel === "dana" || channel === "shopeepay";
  if (method === "va") {
    return new Set([
      "bag",
      "bca",
      "bpd_bali",
      "bni",
      "cimb",
      "mandiri",
      "bmi",
      "bri",
      "bsi",
      "permata",
      "danamon",
      "btn",
    ]).has(channel);
  }
  return false;
}

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

function runtime() {
  return getRuntimeEnv<IpaymuRuntime>();
}

function environmentConfig(environment: IpaymuEnvironment) {
  const config = runtime();

  if (environment === "sandbox") {
    return {
      environment,
      va: requireRuntimeValue(config.IPAYMU_SANDBOX_VA, "IPAYMU_SANDBOX_VA"),
      apiKey: requireRuntimeValue(
        config.IPAYMU_SANDBOX_API_KEY,
        "IPAYMU_SANDBOX_API_KEY",
      ),
      apiUrl: requireRuntimeValue(
        config.IPAYMU_SANDBOX_API_URL,
        "IPAYMU_SANDBOX_API_URL",
      ),
    };
  }

  return {
    environment,
    va: requireRuntimeValue(
      config.IPAYMU_PRODUCTION_VA,
      "IPAYMU_PRODUCTION_VA",
    ),
    apiKey: requireRuntimeValue(
      config.IPAYMU_PRODUCTION_API_KEY,
      "IPAYMU_PRODUCTION_API_KEY",
    ),
    apiUrl: requireRuntimeValue(
      config.IPAYMU_PRODUCTION_API_URL,
      "IPAYMU_PRODUCTION_API_URL",
    ),
  };
}

function activeConfig() {
  const config = runtime();
  const environment = requireRuntimeChoice(
    config.IPAYMU_ENV,
    "IPAYMU_ENV",
    ["sandbox", "production"] as const,
  );
  const selected = environmentConfig(environment);
  let parsed: URL;
  try {
    parsed = new URL(selected.apiUrl);
  } catch {
    throw new Error(
      `${environment === "sandbox" ? "IPAYMU_SANDBOX_API_URL" : "IPAYMU_PRODUCTION_API_URL"} di Cloudflare bukan URL yang valid.`,
    );
  }
  if (parsed.protocol !== "https:")
    throw new Error("URL API iPaymu wajib HTTPS.");
  return { ...selected, apiUrl: parsed.toString() };
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
  const { environment, va, apiKey, apiUrl } = activeConfig();
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
  const signature = hmacHex(
    "sha256",
    apiKey,
    `POST:${va}:${bodyHash}:${apiKey}`,
  );

  const response = await fetch(apiUrl, {
    method: "POST",
    headers: withProviderRelayHeaders(
      apiUrl,
      {
        "content-type": "application/json",
        accept: "application/json",
        va,
        signature,
        timestamp: timestamp(),
      },
      { provider: "ipaymu", environment },
    ),
    body: rawBody,
    signal: AbortSignal.timeout(15_000),
  });

  const payload = (await response.json().catch(() => ({}))) as DirectResponse;
  if (!response.ok || payload.Success === false || !payload.Data) {
    throw new Error(
      payload.Message || "iPaymu menolak pembuatan pembayaran.",
    );
  }

  const data = payload.Data;
  const fee = Math.max(0, Number(data.Fee ?? 0));
  const total = Math.max(
    input.amount,
    Number(data.Total ?? input.amount + fee),
  );

  return {
    transactionId:
      data.TransactionId == null ? null : String(data.TransactionId),
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

export function parseIpaymuCallback(
  rawBody: string,
  contentType: string | null,
) {
  if (contentType?.includes("application/json")) {
    return JSON.parse(rawBody) as Record<string, unknown>;
  }
  return Object.fromEntries(
    new URLSearchParams(rawBody).entries(),
  ) as Record<string, unknown>;
}

function normalizeCallback(raw: Record<string, unknown>) {
  const result: Record<string, unknown> = {};
  const integerFields = new Set([
    "trx_id",
    "status_code",
    "transaction_status_code",
    "paid_off",
  ]);

  for (const [key, rawValue] of Object.entries(raw)) {
    if (key === "signature") continue;
    if (key === "is_escrow") {
      result[key] =
        rawValue === true ||
        rawValue === 1 ||
        rawValue === "1" ||
        rawValue === "true";
    } else if (integerFields.has(key)) {
      result[key] = Number.parseInt(String(rawValue), 10);
    } else if (key === "additional_info") {
      if (Array.isArray(rawValue)) result[key] = rawValue;
      else if (
        rawValue === "[]" ||
        rawValue == null ||
        rawValue === ""
      )
        result[key] = [];
      else {
        try {
          result[key] = JSON.parse(String(rawValue));
        } catch {
          result[key] = String(rawValue);
        }
      }
    } else {
      result[key] = String(rawValue ?? "");
    }
  }

  if (!("additional_info" in result)) result.additional_info = [];

  return Object.keys(result)
    .sort((a, b) => a.localeCompare(b))
    .reduce<Record<string, unknown>>((sorted, key) => {
      sorted[key] = result[key];
      return sorted;
    }, {});
}

export function validateIpaymuCallback(
  raw: Record<string, unknown>,
  receivedSignature: string | null,
) {
  const config = runtime();
  const normalized = normalizeCallback(raw);
  const signedBody = JSON.stringify(normalized).replace(/\//g, "\\/");

  const candidates = [
    ["sandbox", config.IPAYMU_SANDBOX_VA?.trim()],
    ["production", config.IPAYMU_PRODUCTION_VA?.trim()],
  ] as const;

  for (const [environment, va] of candidates) {
    if (!va) continue;
    const expected = hmacHex("sha256", va, signedBody);
    if (safeEqual(receivedSignature, expected)) {
      return { valid: true, environment, normalized };
    }
  }

  return { valid: false, environment: null, normalized };
}

export function mapIpaymuStatus(payload: Record<string, unknown>) {
  const status = String(payload.status ?? "").toLowerCase();
  const statusCode = Number(payload.status_code);
  const transactionCode = Number(payload.transaction_status_code);

  if (
    statusCode === 1 ||
    status === "berhasil" ||
    transactionCode === 1 ||
    transactionCode === 6
  )
    return "paid" as const;
  if (statusCode === -2 || status === "expired")
    return "expired" as const;
  if (status === "gagal" || status === "failed")
    return "failed" as const;
  return "pending" as const;
}
