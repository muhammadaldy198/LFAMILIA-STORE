// Canonical request values from DOKU Checkout Supported Payment Methods:
// https://developers.doku.com/accept-payments/doku-checkout/configuration/supported-payment-methods
// Keep exact provider spellings for outbound requests. Documentation/sample
// aliases are accepted below but always normalized back to canonical values.

export const DOKU_CHECKOUT_TYPES = Object.freeze({
  "va:doku": "VIRTUAL_ACCOUNT_DOKU",
  "va:bca": "VIRTUAL_ACCOUNT_BCA",
  "va:mandiri": "VIRTUAL_ACCOUNT_BANK_MANDIRI",
  "va:bsi": "VIRTUAL_ACCOUNT_BANK_SYARIAH_MANDIRI",
  "va:bri": "VIRTUAL_ACCOUNT_BRI",
  "va:bni": "VIRTUAL_ACCOUNT_BNI",
  "va:permata": "VIRTUAL_ACCOUNT_BANK_PERMATA",
  "va:cimb": "VIRTUAL_ACCOUNT_BANK_CIMB",
  "va:danamon": "VIRTUAL_ACCOUNT_BANK_DANAMON",
  "va:btn": "VIRTUAL_ACCOUNT_BTN",
  "va:bnc": "VIRTUAL_ACCOUNT_BNC",
  "va:bss": "VIRTUAL_ACCOUNT_BSS",
  "va:bjb": "VIRTUAL_ACCOUNT_BJB",
  "va:sinarmas": "VIRTUAL_ACCOUNT_Sinarmas",
  "ewallet:ovo": "EMONEY_OVO",
  "ewallet:shopeepay": "EMONEY_SHOPEE_PAY",
  "ewallet:doku": "EMONEY_DOKU",
  "ewallet:linkaja": "EMONEY_LINKAJA",
  "ewallet:dana": "EMONEY_DANA",
  "qris:mpm": "QRIS",
  "qris:qris": "QRIS",
});

const DOKU_CHECKOUT_TYPES_BY_METHOD = Object.freeze({
  va: Object.freeze([
    "VIRTUAL_ACCOUNT_DOKU",
    "VIRTUAL_ACCOUNT_BCA",
    "VIRTUAL_ACCOUNT_BANK_MANDIRI",
    "VIRTUAL_ACCOUNT_BANK_SYARIAH_MANDIRI",
    "VIRTUAL_ACCOUNT_BRI",
    "VIRTUAL_ACCOUNT_BNI",
    "VIRTUAL_ACCOUNT_BANK_PERMATA",
    "VIRTUAL_ACCOUNT_BANK_CIMB",
    "VIRTUAL_ACCOUNT_BANK_DANAMON",
    "VIRTUAL_ACCOUNT_BTN",
    "VIRTUAL_ACCOUNT_BNC",
    "VIRTUAL_ACCOUNT_BSS",
    "VIRTUAL_ACCOUNT_BJB",
    "VIRTUAL_ACCOUNT_Sinarmas",
  ]),
  ewallet: Object.freeze([
    "EMONEY_OVO",
    "EMONEY_SHOPEE_PAY",
    "EMONEY_DOKU",
    "EMONEY_LINKAJA",
    "EMONEY_DANA",
  ]),
  qris: Object.freeze(["QRIS"]),
});

const DOKU_CHECKOUT_PAYMENT_TYPE_ALIASES = new Map([
  // DOKU's Backend Integration request example still shows EMONEY_SHOPEEPAY,
  // while Supported Payment Methods and Checkout responses use EMONEY_SHOPEE_PAY.
  ["EMONEY_SHOPEEPAY", "EMONEY_SHOPEE_PAY"],
  // Checkout responses may use all-caps SINARMAS while the supported-methods
  // request table currently publishes VIRTUAL_ACCOUNT_Sinarmas.
  ["VIRTUAL_ACCOUNT_SINARMAS", "VIRTUAL_ACCOUNT_Sinarmas"],
]);

/**
 * @param {string} method
 * @param {string} paymentType
 * @returns {string | null}
 */
export function canonicalDokuCheckoutPaymentType(method, paymentType) {
  const candidates =
    method === "va"
      ? DOKU_CHECKOUT_TYPES_BY_METHOD.va
      : method === "ewallet"
        ? DOKU_CHECKOUT_TYPES_BY_METHOD.ewallet
        : method === "qris"
          ? DOKU_CHECKOUT_TYPES_BY_METHOD.qris
          : [];
  const raw = paymentType.trim();
  const alias = DOKU_CHECKOUT_PAYMENT_TYPE_ALIASES.get(raw.toUpperCase()) ?? raw;
  return candidates.find((value) => value.toUpperCase() === alias.toUpperCase()) ?? null;
}

/**
 * @param {string} method
 * @param {string} paymentType
 * @returns {boolean}
 */
export function isDokuCheckoutPaymentTypeCompatible(method, paymentType) {
  return Boolean(canonicalDokuCheckoutPaymentType(method, paymentType));
}

/**
 * @param {string} method
 * @param {string} channel
 * @param {Record<string, string> | undefined} gatewayConfig
 * @returns {string | null}
 */
export function dokuCheckoutPaymentType(method, channel, gatewayConfig) {
  const custom = gatewayConfig?.paymentType?.trim();
  if (custom) return canonicalDokuCheckoutPaymentType(method, custom);
  return DOKU_CHECKOUT_TYPES[`${method}:${channel}`] || null;
}
