import { hashHex } from "@/lib/server/crypto";
import type { ProviderAdapter, ProviderResult } from "@/lib/server/providers/types";
import { providerRelayRequest } from "@/lib/server/provider-relay";
import {
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

type DigiFlazzResponse = {
  data?: {
    ref_id?: string;
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

    const body = {
      username,
      buyer_sku_code: order.providerSku,
      customer_no: order.customerNo,
      ref_id: order.referenceId,
      sign: hashHex("md5", `${username}${apiKey}${order.referenceId}`),
      testing: environment === "development",
      max_price: order.subtotal,
      cb_url: `${publicBaseUrl}/api/fulfillment/digiflazz/callback`,
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

    const payload = (await response.json()) as DigiFlazzResponse;
    const data = payload.data;
    if (!response.ok || !data)
      throw new Error(
        "DigiFlazz tidak memberikan jawaban transaksi yang valid.",
      );

    return {
      externalId: data.ref_id ?? order.referenceId,
      status: mapStatus(data.status),
      message:
        data.message ?? `Status DigiFlazz: ${data.status ?? "tidak diketahui"}`,
      serialNumber: data.sn || null,
      raw: payload,
    };
  },
};
