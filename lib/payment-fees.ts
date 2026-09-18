export type CustomerPaymentFeeInput = {
  customerFeeEnabled?: string | number | boolean | null;
  customerFeeBps?: string | number | null;
  customerFeeFixed?: string | number | null;
};

export type PublicCustomerPaymentFee = {
  customerFeeEnabled: boolean;
  customerFeeBps: number;
  customerFeeFixed: number;
};

function parseInteger(value: unknown, fallback = 0) {
  if (typeof value === "number" && Number.isInteger(value)) return value;
  if (typeof value === "string" && /^-?\d+$/.test(value.trim())) {
    const parsed = Number(value);
    if (Number.isSafeInteger(parsed)) return parsed;
  }
  return fallback;
}

export function publicCustomerPaymentFee(input?: CustomerPaymentFeeInput): PublicCustomerPaymentFee {
  const rawEnabled = input?.customerFeeEnabled;
  const customerFeeEnabled =
    rawEnabled === undefined || rawEnabled === null || rawEnabled === ""
      ? true
      : !(
          rawEnabled === false ||
          rawEnabled === 0 ||
          String(rawEnabled).trim().toLowerCase() === "false" ||
          String(rawEnabled).trim() === "0"
        );
  const customerFeeBps = parseInteger(input?.customerFeeBps, 0);
  const customerFeeFixed = parseInteger(input?.customerFeeFixed, 0);
  if (customerFeeBps < 0 || customerFeeBps >= 10_000) {
    throw new Error("Persentase biaya payment gateway tidak valid.");
  }
  if (customerFeeFixed < 0 || customerFeeFixed > 100_000_000) {
    throw new Error("Biaya tetap payment gateway tidak valid.");
  }
  return { customerFeeEnabled, customerFeeBps, customerFeeFixed };
}

/**
 * Returns the exact surcharge added to the customer total.
 *
 * Percentage fees are grossed up so a percentage charged against the final
 * transaction total is also covered by the customer:
 *   total = ceil((amount + fixed) / (1 - percentage))
 *   fee   = total - amount
 */
export function calculateCustomerPaymentFee(
  amount: number,
  input?: CustomerPaymentFeeInput,
) {
  if (!Number.isInteger(amount) || amount < 0) {
    throw new Error("Nominal pembayaran tidak valid.");
  }
  const config = publicCustomerPaymentFee(input);
  if (!config.customerFeeEnabled) return 0;

  const base = amount + config.customerFeeFixed;
  if (config.customerFeeBps === 0) return config.customerFeeFixed;

  const total = Math.ceil(
    (base * 10_000) / (10_000 - config.customerFeeBps),
  );
  if (!Number.isSafeInteger(total) || total < amount) {
    throw new Error("Total pembayaran setelah biaya tidak valid.");
  }
  return total - amount;
}
