import { createDokuDirectPayment, getDokuReadiness, isDokuChannelSupported } from "@/lib/server/doku";
import { hostedPaymentType } from "@/lib/server/hosted-payment-methods";
import { createMidtransSnapPayment, getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { hydrateDokuDirectRuntimeEnv } from "@/lib/server/payment-mode-config";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";
import { getRuntimeEnv, setRuntimeEnv } from "@/lib/server/runtime-env";

export type RoutedPaymentMode = "direct" | "checkout" | "snap";

async function prepareDokuRuntime() {
  const current = getRuntimeEnv<Record<string, unknown>>();
  setRuntimeEnv(await hydrateDokuDirectRuntimeEnv(current));
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
    return {
      ready: readiness.ready && supported,
      environment: readiness.environment,
      mode: "direct" as const,
      reason: readiness.ready
        ? supported ? null : "Channel belum didukung DOKU Direct API."
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
