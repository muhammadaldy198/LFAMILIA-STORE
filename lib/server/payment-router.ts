import { createDokuDirectPayment, getDokuReadiness, isDokuChannelSupported } from "@/lib/server/doku";
import { hostedPaymentType } from "@/lib/server/hosted-payment-methods";
import { createMidtransSnapPayment, getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { hydrateDokuDirectRuntimeEnv } from "@/lib/server/payment-mode-config";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";
import { getRuntimeEnv, setRuntimeEnv } from "@/lib/server/runtime-env";

export type RoutedPaymentMode = "direct" | "snap";

async function prepareDokuRuntime() {
  const current = getRuntimeEnv<Record<string, unknown>>();
  setRuntimeEnv(await hydrateDokuDirectRuntimeEnv(current));
}

function runtimeText(runtime: Record<string, unknown>, key: string) {
  const value = runtime[key];
  return typeof value === "string" ? value.trim() : "";
}

function dokuChannelConfigReason(
  environment: "sandbox" | "production" | null,
  method: string,
  channel: string,
) {
  if (!environment) return "Environment DOKU Direct API belum siap.";
  const runtime = getRuntimeEnv<Record<string, unknown>>();
  const prefix = `DOKU_${environment.toUpperCase()}_`;

  if (method === "qris") {
    const merchantId = runtimeText(runtime, `${prefix}QRIS_MERCHANT_ID`);
    const terminalId = runtimeText(runtime, `${prefix}QRIS_TERMINAL_ID`);
    const postalCode = runtimeText(runtime, `${prefix}QRIS_POSTAL_CODE`);
    if (!merchantId || !terminalId || !/^\d{1,5}$/.test(postalCode)) {
      return "QRIS DOKU belum lengkap. Isi Merchant ID, Terminal ID, dan Postal Code.";
    }
  }

  if (method === "va") {
    const raw = runtimeText(runtime, `${prefix}VA_CONFIG_JSON`);
    if (!raw) return `Konfigurasi Virtual Account ${channel.toUpperCase()} belum diisi.`;
    try {
      const parsed = JSON.parse(raw) as Record<string, unknown>;
      const item = parsed?.[channel];
      if (!item || typeof item !== "object" || Array.isArray(item)) {
        return `Konfigurasi Virtual Account ${channel.toUpperCase()} belum tersedia.`;
      }
      const values = item as Record<string, unknown>;
      for (const key of ["partnerServiceId", "customerNo", "virtualAccountNo", "channel"] as const) {
        if (typeof values[key] !== "string" || !values[key].trim()) {
          return `Konfigurasi Virtual Account ${channel.toUpperCase()} belum lengkap.`;
        }
      }
    } catch {
      return "Konfigurasi Virtual Account DOKU harus berupa JSON valid.";
    }
  }

  return null;
}

export async function getConfiguredGatewayReadiness(input: {
  gateway: PaymentGatewayName;
  paymentMethod: string;
  paymentChannel: string;
  gatewayConfig?: Record<string, string>;
}) {
  if (input.gateway === "doku") {
    await prepareDokuRuntime();
    const readiness = getDokuReadiness();
    const supported = isDokuChannelSupported(input.paymentMethod, input.paymentChannel);
    const channelReason = readiness.ready && supported
      ? dokuChannelConfigReason(readiness.environment, input.paymentMethod, input.paymentChannel)
      : null;
    return {
      ready: readiness.ready && supported && !channelReason,
      environment: readiness.environment,
      mode: "direct" as const,
      reason: readiness.ready
        ? supported
          ? channelReason
          : "Channel belum didukung DOKU Direct API."
        : readiness.reason,
    };
  }

  const readiness = await getMidtransSnapReadiness();
  const paymentType = hostedPaymentType("midtrans", input.paymentMethod, input.paymentChannel, input.gatewayConfig);
  return {
    ready: readiness.ready && Boolean(paymentType),
    environment: readiness.environment,
    mode: "snap" as const,
    reason: readiness.ready ? (paymentType ? null : "Channel belum memiliki kode Midtrans Snap.") : readiness.reason,
  };
}

export async function createConfiguredPayment(input: {
  gateway: PaymentGatewayName;
  referenceId: string;
  amount: number;
  paymentMethod: string;
  paymentChannel: string;
  gatewayConfig?: Record<string, string>;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  productName: string;
  packageLabel?: string;
  finishUrl: string;
  deviceId: string;
}) {
  const readiness = await getConfiguredGatewayReadiness({
    gateway: input.gateway,
    paymentMethod: input.paymentMethod,
    paymentChannel: input.paymentChannel,
    gatewayConfig: input.gatewayConfig,
  });
  if (!readiness.ready) throw new Error(readiness.reason || "Gateway belum siap.");

  if (input.gateway === "doku") {
    const payment = await createDokuDirectPayment(input);
    return { ...payment, gateway: "doku" as const, mode: "direct" as const, environment: readiness.environment };
  }
  const payment = await createMidtransSnapPayment(input);
  return { ...payment, gateway: "midtrans" as const, mode: "snap" as const, environment: readiness.environment };
}
