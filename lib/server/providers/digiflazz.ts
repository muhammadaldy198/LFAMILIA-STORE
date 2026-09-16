import { hashHex } from "@/lib/server/crypto";
import type { ProviderAdapter, ProviderResult } from "@/lib/server/providers/types";
import { providerRelayRequest } from "@/lib/server/provider-relay";
import { isAutomatedTestRuntime,
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

type DigiFlazzEnv = {
  DIGIFLAZZ_ENV?: string;
  DIGIFLAZZ_USERNAME?: string;
  DIGIFLAZZ_DEVELOPMENT_API_KEY?: string;
  DIGIFLAZZ_PRODUCTION_API_KEY?: string;
  DIGIFLAZZ_DEVELOPMENT_API_URL?: string;
  DIGIFLAZZ_PRODUCTION_API_URL?: string;
};

type DigiFlazzBalanceResponse = {
  data?: {
    deposit?: number;
  };
};

type DigiFlazzResponse = {
  data?: {
    ref_id?: string;
    buyer_sku_code?: string;
    customer_no?: string;
    price?: number;
    message?: string;
    status?: string;
    rc?: string;
    sn?: string;
  };
};

function runtimeConfig() {
  const runtime = getRuntimeEnv<DigiFlazzEnv>();
  const environment = requireRuntimeChoice(
    runtime.DIGIFLAZZ_ENV,
    "DIGIFLAZZ_ENV",
    ["development", "production"] as const,
  );
  const username = requireRuntimeValue(
    runtime.DIGIFLAZZ_USERNAME,
    "DIGIFLAZZ_USERNAME",
  );
  const apiKey = requireRuntimeValue(
    environment === "development"
      ? runtime.DIGIFLAZZ_DEVELOPMENT_API_KEY
      : runtime.DIGIFLAZZ_PRODUCTION_API_KEY,
    environment === "development"
      ? "DIGIFLAZZ_DEVELOPMENT_API_KEY"
      : "DIGIFLAZZ_PRODUCTION_API_KEY",
  );
  const apiUrl = requireRuntimeValue(
    environment === "development"
      ? runtime.DIGIFLAZZ_DEVELOPMENT_API_URL
      : runtime.DIGIFLAZZ_PRODUCTION_API_URL,
    environment === "development"
      ? "DIGIFLAZZ_DEVELOPMENT_API_URL"
      : "DIGIFLAZZ_PRODUCTION_API_URL",
  );
  return { environment, username, apiKey, apiUrl };
}

export function getDigiflazzReadiness() {
  try {
    const config = runtimeConfig();
    return {
      ready: true as const,
      environment: config.environment,
      reason: null,
    };
  } catch (error) {
    return {
      ready: false as const,
      environment: null,
      reason: error instanceof Error ? error.message : "Konfigurasi DigiFlazz belum lengkap.",
    };
  }
}

let balanceCache: { value: number; checkedAt: number } | null = null;

export function clearDigiflazzBalanceCache() {
  balanceCache = null;
}

export async function getDigiflazzBalance() {
  if (isAutomatedTestRuntime() && runtimeConfig().environment === "production") throw new Error("DigiFlazz production dinonaktifkan saat automated test.");
  const { environment, username, apiKey, apiUrl } = runtimeConfig();
  if (balanceCache && Date.now() - balanceCache.checkedAt < 60_000) {
    return { balance: balanceCache.value, cached: true as const };
  }
  const origin = new URL(apiUrl).origin;
  const balanceUrl = new URL("/v1/cek-saldo", origin).toString();
  const relay = providerRelayRequest(
    balanceUrl,
    { "content-type": "application/json", accept: "application/json" },
    { provider: "digiflazz", environment },
  );
  const response = await fetch(relay.url, {
    method: "POST",
    headers: relay.headers,
    body: JSON.stringify({
      cmd: "deposit",
      username,
      sign: hashHex("md5", `${username}${apiKey}depo`),
    }),
    signal: AbortSignal.timeout(12_000),
  });
  const payload = (await response.json().catch(() => null)) as DigiFlazzBalanceResponse | null;
  const deposit = Number(payload?.data?.deposit);
  if (!response.ok || !Number.isFinite(deposit)) {
    throw new Error("Saldo DigiFlazz tidak dapat dibaca.");
  }
  balanceCache = { value: deposit, checkedAt: Date.now() };
  return { balance: deposit, cached: false as const };
}

function mapStatus(value?: string): ProviderResult["status"] {
  const status = value?.toLowerCase();
  if (status === "sukses") return "success";
  if (status === "gagal") return "failed";
  return "processing";
}

export const digiflazzAdapter: ProviderAdapter = {
  code: "digiflazz",
  name: "DigiFlazz",
  async fulfill(order, publicBaseUrl) {
    const { environment, username, apiKey, apiUrl } = runtimeConfig();
    if (isAutomatedTestRuntime() && environment === "production") throw new Error("DigiFlazz production dinonaktifkan saat automated test.");

    const body = {
      username,
      buyer_sku_code: order.providerSku,
      customer_no: order.customerNo,
      ref_id: order.referenceId,
      sign: hashHex("md5", `${username}${apiKey}${order.referenceId}`),
      testing: environment === "development",
      cb_url: `${publicBaseUrl}/api/fulfillment/digiflazz/callback`,
      ...(order.customerNo.includes(".") ? { allow_dot: true } : {}),
    };

    const relay = providerRelayRequest(
      apiUrl,
      { "content-type": "application/json", accept: "application/json" },
      { provider: "digiflazz", environment },
    );
    const response = await fetch(relay.url, {
      method: "POST",
      headers: relay.headers,
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(15_000),
    });

    const payload = (await response.json().catch(() => null)) as DigiFlazzResponse | null;
    const data = payload?.data;
    if (!response.ok || !data) {
      throw new Error("DigiFlazz tidak memberikan jawaban transaksi yang valid.");
    }
    if (data.ref_id !== order.referenceId) {
      throw new Error("Ref ID jawaban DigiFlazz tidak cocok dengan order LFAMILIA.");
    }
    if (data.buyer_sku_code !== order.providerSku) {
      throw new Error("SKU jawaban DigiFlazz tidak cocok dengan order LFAMILIA.");
    }

    return {
      externalId: data.ref_id,
      status: mapStatus(data.status),
      message:
        data.message ?? `Status DigiFlazz: ${data.status ?? "tidak diketahui"}`,
      serialNumber: data.sn || null,
      raw: payload,
    };
  },
};
