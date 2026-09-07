import {
  getIpaymuReadiness,
  isIpaymuChannelSupported,
} from "@/lib/server/ipaymu";
import {
  getMidtransMode,
  getMidtransReadiness,
  isMidtransChannelSupported,
} from "@/lib/server/midtrans";
import { isIpaymuAmountSupported } from "@/lib/payment-limits";

export type RoutedPaymentGateway = "ipaymu" | "midtrans";

export type PaymentGatewayRouting = {
  candidates: RoutedPaymentGateway[];
  ipaymuEligible: boolean;
  midtransEligible: boolean;
  ipaymuReady: boolean;
  midtransReady: boolean;
};

export function routePaymentGateway(input: {
  amount: number;
  paymentMethod: string;
  paymentChannel: string;
  ipaymuEnabled: boolean;
  midtransEnabled: boolean;
}): PaymentGatewayRouting {
  const ipaymu = getIpaymuReadiness();
  const midtrans = getMidtransReadiness();

  const ipaymuEligible =
    input.ipaymuEnabled &&
    ipaymu.ready &&
    isIpaymuAmountSupported(input.amount) &&
    isIpaymuChannelSupported(input.paymentMethod, input.paymentChannel);

  const midtransEligible =
    input.midtransEnabled &&
    midtrans.ready &&
    isMidtransChannelSupported(
      input.paymentMethod,
      input.paymentChannel,
      midtrans.ready ? getMidtransMode() : undefined,
    );

  const candidates: RoutedPaymentGateway[] = [];

  // iPaymu is the primary gateway when its documented minimum and channel
  // requirements are satisfied. Midtrans covers lower-value transactions and
  // channels that iPaymu does not support, and remains the fallback.
  if (ipaymuEligible) candidates.push("ipaymu");
  if (midtransEligible) candidates.push("midtrans");

  return {
    candidates,
    ipaymuEligible,
    midtransEligible,
    ipaymuReady: ipaymu.ready,
    midtransReady: midtrans.ready,
  };
}
