import { createDokuCheckoutPayment, getDokuCheckoutReadiness } from "@/lib/server/doku-checkout";
import { hostedPaymentType } from "@/lib/server/hosted-payment-methods";
import { createMidtransSnapPayment, getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";

export type RoutedPaymentMode = "checkout" | "snap";

export async function getConfiguredGatewayReadiness(input: {
  gateway: PaymentGatewayName;
  paymentMethod: string;
  paymentChannel: string;
  gatewayConfig?: Record<string, string>;
}) {
  if (input.gateway === "doku") {
    const readiness = await getDokuCheckoutReadiness();
    const paymentType = hostedPaymentType("doku", input.paymentMethod, input.paymentChannel, input.gatewayConfig);
    return {
      ready: readiness.ready && Boolean(paymentType),
      environment: readiness.environment,
      mode: "checkout" as const,
      reason: readiness.ready ? (paymentType ? null : "Channel belum memiliki kode DOKU Checkout.") : readiness.reason,
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

  if (input.gateway === "doku" && readiness.mode === "checkout") {
    const payment = await createDokuCheckoutPayment(input);
    return { ...payment, gateway: "doku" as const, mode: "checkout" as const, environment: readiness.environment };
  }
  const payment = await createMidtransSnapPayment(input);
  return { ...payment, gateway: "midtrans" as const, mode: "snap" as const, environment: readiness.environment };
}
