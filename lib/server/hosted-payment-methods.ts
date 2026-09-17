export function dokuCheckoutPaymentType(method: string, channel: string) {
  const key = `${method}:${channel}`;
  const map: Record<string, string> = {
    "va:bca": "VIRTUAL_ACCOUNT_BCA",
    "va:mandiri": "VIRTUAL_ACCOUNT_BANK_MANDIRI",
    "va:bni": "VIRTUAL_ACCOUNT_BNI",
    "va:bri": "VIRTUAL_ACCOUNT_BRI",
    "va:cimb": "VIRTUAL_ACCOUNT_BANK_CIMB",
    "va:permata": "VIRTUAL_ACCOUNT_BANK_PERMATA",
    "va:danamon": "VIRTUAL_ACCOUNT_BANK_DANAMON",
    "ewallet:ovo": "EMONEY_OVO",
    "ewallet:shopeepay": "EMONEY_SHOPEE_PAY",
    "ewallet:dana": "EMONEY_DANA",
    "qris:mpm": "QRIS",
    "qris:qris": "QRIS",
  };
  return map[key] || null;
}

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
  gateway: "doku" | "midtrans",
  method: string,
  channel: string,
  gatewayConfig?: Record<string, string>,
) {
  const custom = gatewayConfig?.paymentType?.trim();
  if (custom) return custom;
  return gateway === "doku"
    ? dokuCheckoutPaymentType(method, channel)
    : midtransSnapPaymentType(method, channel);
}

export function isHostedGatewayChannelSupported(
  gateway: "doku" | "midtrans",
  method: string,
  channel: string,
  gatewayConfig?: Record<string, string>,
) {
  if (gateway === "doku") {
    if (method === "qris") return channel === "mpm" || channel === "qris";
    if (method === "ewallet") return channel === "dana" || channel === "shopeepay";
    if (method === "va") return new Set(["bca", "mandiri", "bni", "bri", "bsi", "cimb", "permata", "danamon", "btn", "bmi", "bag", "bpd_bali"]).has(channel);
    return false;
  }
  return Boolean(hostedPaymentType(gateway, method, channel, gatewayConfig));
}
