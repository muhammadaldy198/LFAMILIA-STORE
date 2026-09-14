import { createDokuCheckoutPayment, getDokuCheckoutReadiness } from "@/lib/server/doku-checkout";
import { createDokuDirectPayment, getDokuReadiness, isDokuChannelSupported } from "@/lib/server/doku";
import { dokuCheckoutPaymentType, midtransSnapPaymentType } from "@/lib/server/hosted-payment-methods";
import { createMidtransSnapPayment, getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { createMidtransVirtualAccount, getMidtransReadiness, isMidtransChannelSupported } from "@/lib/server/midtrans";
import { getActivePaymentModes } from "@/lib/server/payment-mode-config";
import { isProviderRelayConfigured } from "@/lib/server/provider-relay";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";

export type RoutedPaymentMode = "checkout" | "direct" | "snap" | "bisnap";

export async function getConfiguredGatewayReadiness(input: {
  gateway: PaymentGatewayName;
  paymentMethod: string;
  paymentChannel: string;
  gatewayConfig?: Record<string, string>;
}) {
  const modes = await getActivePaymentModes();
  if (input.gateway === "doku") {
    if (modes.dokuMode === "checkout") {
      const readiness = await getDokuCheckoutReadiness();
      return {
        ready: readiness.ready && Boolean(dokuCheckoutPaymentType(input.paymentMethod, input.paymentChannel)),
        environment: readiness.environment,
        mode: "checkout" as const,
        reason: readiness.ready ? (dokuCheckoutPaymentType(input.paymentMethod, input.paymentChannel) ? null : "Channel tidak didukung DOKU Checkout.") : readiness.reason,
      };
    }
    const readiness = getDokuReadiness();
    return {
      ready: readiness.ready && isDokuChannelSupported(input.paymentMethod, input.paymentChannel),
      environment: readiness.environment,
      mode: "direct" as const,
      reason: readiness.ready ? (isDokuChannelSupported(input.paymentMethod, input.paymentChannel) ? null : "Channel tidak didukung DOKU Direct API.") : readiness.reason,
    };
  }

  if (modes.midtransMode === "snap") {
    const readiness = await getMidtransSnapReadiness();
    return {
      ready: readiness.ready && Boolean(midtransSnapPaymentType(input.paymentMethod, input.paymentChannel)),
      environment: readiness.environment,
      mode: "snap" as const,
      reason: readiness.ready ? (midtransSnapPaymentType(input.paymentMethod, input.paymentChannel) ? null : "Channel tidak didukung Midtrans Snap.") : readiness.reason,
    };
  }
  const readiness = getMidtransReadiness();
  const partnerServiceId = input.gatewayConfig?.partnerServiceId || "";
  const supported = isMidtransChannelSupported(input.paymentMethod, input.paymentChannel);
  const relayReady = isProviderRelayConfigured("midtrans");
  return {
    ready: readiness.ready && supported && relayReady && partnerServiceId.length === 8,
    environment: readiness.environment,
    mode: "bisnap" as const,
    reason: !readiness.ready
      ? readiness.missing.join(", ")
      : !supported
        ? "Mode BI-SNAP saat ini hanya mendukung Virtual Account yang telah dikonfigurasi."
        : !relayReady
          ? "Relay Midtrans belum siap."
          : partnerServiceId.length !== 8
            ? "Partner Service ID VA belum lengkap."
            : null,
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
  if (input.gateway === "doku") {
    const payment = await createDokuDirectPayment(input);
    return { ...payment, gateway: "doku" as const, mode: "direct" as const, environment: readiness.environment };
  }
  if (readiness.mode === "snap") {
    const payment = await createMidtransSnapPayment(input);
    return { ...payment, gateway: "midtrans" as const, mode: "snap" as const, environment: readiness.environment };
  }
  const payment = await createMidtransVirtualAccount({
    referenceId: input.referenceId,
    amount: input.amount,
    channel: input.paymentChannel,
    partnerServiceId: input.gatewayConfig?.partnerServiceId || "",
    buyerName: input.buyerName,
    buyerEmail: input.buyerEmail,
    buyerPhone: input.buyerPhone,
    productName: input.productName,
    packageLabel: input.packageLabel || "",
    deviceId: input.deviceId,
  });
  return { ...payment, gateway: "midtrans" as const, mode: "bisnap" as const, environment: payment.environment };
}
