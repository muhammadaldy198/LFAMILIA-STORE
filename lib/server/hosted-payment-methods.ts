export function midtransSnapPaymentType(method: string, channel: string) {
  const key = `${method}:${channel}`;
  const map: Record<string, string> = {
    "va:bca": "bca_va",
    "va:mandiri": "echannel",
    "va:bni": "bni_va",
    "va:bri": "bri_va",
    "va:cimb": "cimb_va",
    "va:permata": "permata_va",
    "va:danamon": "danamon_va",
    "ewallet:gopay": "gopay",
    "ewallet:ovo": "ovo",
    "ewallet:dana": "dana",
    "ewallet:shopeepay": "shopeepay",
    "qris:mpm": "other_qris",
    "qris:qris": "other_qris",
  };
  return map[key] || null;
}

export function hostedPaymentType(
  gateway: "midtrans",
  method: string,
  channel: string,
  gatewayConfig?: Record<string, string>,
) {
  const custom = gatewayConfig?.paymentType?.trim();
  if (custom) return custom;
  return midtransSnapPaymentType(method, channel);
}

export function isHostedGatewayChannelSupported(
  gateway: "midtrans",
  method: string,
  channel: string,
  gatewayConfig?: Record<string, string>,
) {
  return Boolean(hostedPaymentType(gateway, method, channel, gatewayConfig));
}
