export type KokinpayFailureKind = "validation" | "authentication" | "service";

/**
 * Classify an upstream KokinPay HTTP failure without trusting its response body.
 * Only explicit client-input statuses are treated as invalid account data.
 * Authentication, rate-limit, and server failures remain service-side failures.
 */
export function classifyKokinpayFailure(status: number): KokinpayFailureKind {
  if (status === 400 || status === 404) return "validation";
  if (status === 401 || status === 403) return "authentication";
  return "service";
}
