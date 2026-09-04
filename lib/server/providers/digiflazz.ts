import { hashHex } from "@/lib/server/crypto";
import type { ProviderAdapter, ProviderResult } from "@/lib/server/providers/types";
import { withProviderRelayHeaders } from "@/lib/server/provider-relay";
import { getRuntimeEnv, requireRuntimeValue } from "@/lib/server/runtime-env";

type DigiFlazzEnv = {
  DIGIFLAZZ_USERNAME?: string;
  DIGIFLAZZ_API_KEY?: string;
  DIGIFLAZZ_PRODUCTION_API_KEY?: string;
  DIGIFLAZZ_API_URL?: string;
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
    const runtime = getRuntimeEnv<DigiFlazzEnv>();
    const username = requireRuntimeValue(runtime.DIGIFLAZZ_USERNAME, "DIGIFLAZZ_USERNAME");
    const developmentApiKey = requireRuntimeValue(
      runtime.DIGIFLAZZ_API_KEY,
      "DIGIFLAZZ_API_KEY",
    );
    const productionApiKey = runtime.DIGIFLAZZ_PRODUCTION_API_KEY?.trim();
    const apiKey = productionApiKey || developmentApiKey;
    const environment = productionApiKey ? "production" : "development";
    const apiUrl = requireRuntimeValue(runtime.DIGIFLAZZ_API_URL, "DIGIFLAZZ_API_URL");

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
    const response = await fetch(apiUrl, {
      method: "POST",
      headers: withProviderRelayHeaders(apiUrl, { "content-type": "application/json", accept: "application/json" }),
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(15_000),
    });
    const payload = await response.json() as DigiFlazzResponse;
    const data = payload.data;
    if (!response.ok || !data) throw new Error("DigiFlazz tidak memberikan jawaban transaksi yang valid.");
    return {
      externalId: data.ref_id ?? order.referenceId,
      status: mapStatus(data.status),
      message: data.message ?? `Status DigiFlazz: ${data.status ?? "tidak diketahui"}`,
      serialNumber: data.sn || null,
      raw: payload,
    };
  },
};
