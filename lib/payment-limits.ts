export const IPAYMU_MIN_CHECKOUT_AMOUNT = 10_000;

export function isIpaymuAmountSupported(amount: number) {
  return Number.isFinite(amount) && amount >= IPAYMU_MIN_CHECKOUT_AMOUNT;
}
