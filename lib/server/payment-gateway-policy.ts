export type CheckoutGateway = "ipaymu" | "midtrans";

/**
 * LFAMILIA checkout routing policy.
 * iPaymu is always attempted first when enabled.
 * Midtrans is used as fallback when iPaymu is unavailable or fails.
 */
export function resolveCheckoutGatewayOrder(enabled: CheckoutGateway[]) {
  const unique = [...new Set(enabled)];

  return [
    "ipaymu",
    "midtrans",
  ].filter((gateway) => unique.includes(gateway as CheckoutGateway)) as CheckoutGateway[];
}

export function getFallbackGateway(current: CheckoutGateway, enabled: CheckoutGateway[]) {
  if (current !== "ipaymu") return null;
  return enabled.includes("midtrans") ? "midtrans" : null;
}
