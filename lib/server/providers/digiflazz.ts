import { hashHex } from "@/lib/server/crypto";
import type { ProviderAdapter, ProviderResult } from "@/lib/server/providers/types";
import { requireDigiflazzEndpoint } from "@/lib/server/digiflazz-endpoint";
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
    message?: string;
    rc?: string;
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
  const rawApiUrl = requireRuntimeValue(
    environment === "development"
      ? runtime.DIGIFLAZZ_DEVELOPMENT_API_URL
      : runtime.DIGIFLAZZ_PRODUCTION_API_URL,
    environment === "development"
      ? "DIGIFLAZZ_DEVELOPMENT_API_URL"
      : "DIGIFLAZZ_PRODUCTION_API_URL",
  );
  const apiUrl = requireDigiflazzEndpoint(rawApiUrl, "/v1/transaction");
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

function providerErrorMessage(
  data: { rc?: string; message?: string } | undefined,
  fallback: string,
) {
  const message = data?.message?.trim() || fallback;
  const rc = data?.rc?.trim();
  return rc ? `[RC ${rc}] ${message}` : message;
}

export async function getDigiflazzBalance(options: { timeoutMs?: number } = {}) {
  if (isAutomatedTestRuntime() && runtimeConfig().environment === "production") throw new Error("DigiFlazz production dinonaktifkan saat automated test.");
  const { username, apiKey, apiUrl } = runtimeConfig();
  if (balanceCache && Date.now() - balanceCache.checkedAt < 60_000) {
    return { balance: balanceCache.value, cached: true as const };
  }
  const origin = new URL(apiUrl).origin;
  const balanceUrl = new URL("/v1/cek-saldo", origin).toString();
  const response = await fetch(balanceUrl, {
    method: "POST",
    headers: { "content-type": "application/json", accept: "application/json" },
    body: JSON.stringify({
      cmd: "deposit",
      username,
      sign: hashHex("md5", `${username}${apiKey}depo`),
    }),
    signal: AbortSignal.timeout(Math.max(250, Math.min(12_000, options.timeoutMs ?? 12_000))),
  });
  const payload = (await response.json().catch(() => null)) as DigiFlazzBalanceResponse | null;
  const deposit = Number(payload?.data?.deposit);
  if (!response.ok || !Number.isFinite(deposit)) {
    throw new Error(providerErrorMessage(payload?.data, `Saldo DigiFlazz tidak dapat dibaca (HTTP ${response.status}).`));
  }
  balanceCache = { value: deposit, checkedAt: Date.now() };
  return { balance: deposit, cached: false as const };
}

function mapStatus(value?: string, rc?: string): ProviderResult["status"] {
  const status = value?.trim().toLowerCase();
  if (status === "sukses") return "success";
  if (status === "gagal") return "failed";
  if (status === "pending") return "processing";

  const code = rc?.trim();
  if (code === "00") return "success";
  if (code === "03" || code === "99") return "processing";
  if (code) return "failed";
  return "processing";
}

export const digiflazzAdapter: ProviderAdapter = {
  code: "digiflazz",
  name: "DigiFlazz",
  async fulfill(order, publicBaseUrl) {
    const { environment, username, apiKey, apiUrl } = runtimeConfig();
    if (isAutomatedTestRuntime() && environment === "production") throw new Error("DigiFlazz production dinonaktifkan saat automated test.");
    if (!order.providerSku?.trim()) throw new Error("SKU DigiFlazz order kosong.");
    if (!order.customerNo?.trim()) throw new Error("Customer No DigiFlazz order kosong.");

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

    const response = await fetch(apiUrl, {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json" },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(15_000),
    });

    const payload = (await response.json().catch(() => null)) as DigiFlazzResponse | null;
    const data = payload?.data;
    if (!response.ok || !data) {
      throw new Error(
        providerErrorMessage(
          data,
          `DigiFlazz tidak memberikan jawaban transaksi yang valid (HTTP ${response.status}).`,
        ),
      );
    }
    if (data.ref_id !== order.referenceId) {
      throw new Error("Ref ID jawaban DigiFlazz tidak cocok dengan order LFAMILIA.");
    }
    if (data.buyer_sku_code !== order.providerSku) {
      throw new Error("SKU jawaban DigiFlazz tidak cocok dengan order LFAMILIA.");
    }

    const status = mapStatus(data.status, data.rc);
    return {
      externalId: data.ref_id,
      status,
      message: providerErrorMessage(
        data,
        `Status DigiFlazz: ${data.status ?? data.rc ?? "tidak diketahui"}`,
      ),
      serialNumber: data.sn || null,
      raw: payload,
    };
  },
};
