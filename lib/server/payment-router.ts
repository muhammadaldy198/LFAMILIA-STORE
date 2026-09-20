import { getD1 } from "@/db";
import {
  createDokuCheckoutPayment,
  getDokuCheckoutReadiness,
  isDokuCheckoutChannelSupported,
} from "@/lib/server/doku-checkout";
import { hostedPaymentType } from "@/lib/server/hosted-payment-methods";
import { createMidtransSnapPayment, getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { hydrateDokuCheckoutRuntimeEnv } from "@/lib/server/payment-mode-config";
import type { PaymentGatewayName } from "@/lib/server/payment-channels";
import { getRuntimeEnv, setRuntimeEnv } from "@/lib/server/runtime-env";

export type RoutedPaymentMode = "checkout" | "snap";

async function prepareDokuRuntime() {
  const current = getRuntimeEnv<Record<string, unknown>>();
  setRuntimeEnv(await hydrateDokuCheckoutRuntimeEnv(current));
}

async function hasOutstandingDokuLegacyPayments() {
  const db = getD1();
  const [order, topup] = await Promise.all([
    db.prepare(`SELECT 1 AS found
      FROM orders
      WHERE payment_gateway = 'doku'
        AND payment_gateway_mode = 'direct'
        AND payment_status = 'pending'
      LIMIT 1`).first<{ found: number }>(),
    db.prepare(`SELECT 1 AS found
      FROM wallet_topups
      WHERE payment_gateway = 'doku'
        AND payment_gateway_mode = 'direct'
        AND status = 'pending'
      LIMIT 1`).first<{ found: number }>(),
  ]);
  return Boolean(order || topup);
}

export async function getConfiguredGatewayReadiness(input: {
  gateway: PaymentGatewayName;
  paymentMethod: string;
  paymentChannel: string;
  gatewayConfig?: Record<string, string>;
}) {
  if (input.gateway === "doku") {
    await prepareDokuRuntime();
    const readiness = getDokuCheckoutReadiness();
    const supported = isDokuCheckoutChannelSupported(
      input.paymentMethod,
      input.paymentChannel,
      input.gatewayConfig,
    );
    const hasLegacyPending = readiness.ready
      ? await hasOutstandingDokuLegacyPayments()
      : false;
    return {
      ready: readiness.ready && supported && !hasLegacyPending,
      environment: readiness.environment,
      mode: "checkout" as const,
      reason: readiness.ready
        ? hasLegacyPending
          ? "Ada transaksi pembayaran lama yang belum selesai. Gateway dinonaktifkan sementara."
          : supported
            ? null
            : "Channel belum didukung DOKU Checkout."
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
    const payment = await createDokuCheckoutPayment(input);
    return {
      ...payment,
      gateway: "doku" as const,
      mode: "checkout" as const,
      environment: readiness.environment,
    };
  }

  const payment = await createMidtransSnapPayment(input);
  return {
    ...payment,
    gateway: "midtrans" as const,
    mode: "snap" as const,
    environment: readiness.environment,
  };
}
