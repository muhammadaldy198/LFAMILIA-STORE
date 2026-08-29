import { hashHex } from "@/lib/server/crypto";
import type { ProviderAdapter, ProviderResult } from "@/lib/server/providers/types";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

type VipPaymentEnv = {
  VIPPAYMENT_API_ID?: string;
  VIPPAYMENT_API_KEY?: string;
  VIPPAYMENT_API_URL?: string;
};

type VipPaymentResponse = {
  result?: boolean;
  message?: string;
  data?: {
    trxid?: string;
    status?: string;
    note?: string;
  };
};

function mapStatus(value?: string): ProviderResult["status"] {
  const status = value?.toLowerCase();
  if (status === "success") return "success";
  if (status === "error") return "failed";
  return "processing";
}

export const vipPaymentAdapter: ProviderAdapter = {
  code: "vippayment",
  name: "VIPayment",
  async fulfill(order) {
    const runtime = getRuntimeEnv<VipPaymentEnv>();
    const apiId = runtime.VIPPAYMENT_API_ID?.trim();
    const apiKey = runtime.VIPPAYMENT_API_KEY?.trim();
    if (!apiId || !apiKey) throw new Error("Secret VIPayment belum dikonfigurasi.");

    const form = new URLSearchParams({
      key: apiKey,
      sign: hashHex("md5", `${apiId}${apiKey}`),
      type: "order",
      service: order.providerSku,
      data_no: order.destination,
    });
    if (order.server) form.set("data_zone", order.server);
    if (order.customerNotes) form.set("post_additional_data", order.customerNotes);

    const response = await fetch(runtime.VIPPAYMENT_API_URL?.trim() || "https://vip-reseller.co.id/api/game-feature", {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded", accept: "application/json" },
      body: form,
      signal: AbortSignal.timeout(15_000),
    });
    const payload = await response.json() as VipPaymentResponse;
    if (!response.ok || payload.result === false || !payload.data) {
      return {
        externalId: null,
        status: "failed",
        message: payload.message ?? "VIPayment menolak transaksi.",
        serialNumber: null,
        raw: payload,
      };
    }
    return {
      externalId: payload.data.trxid ?? null,
      status: mapStatus(payload.data.status),
      message: payload.data.note || payload.message || `Status VIPayment: ${payload.data.status ?? "waiting"}`,
      serialNumber: null,
      raw: payload,
    };
  },
};
