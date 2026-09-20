// Shared SQL guard for asynchronous fulfillment error paths.
// A stale provider request must never downgrade a terminal callback result.
export const FULFILLMENT_ERROR_TRANSITION_GUARD_SQL =
  "payment_status = 'paid' AND fulfillment_type = 'automatic' " +
  "AND fulfillment_status NOT IN ('success', 'failed', 'cancelled') " +
  "AND COALESCE(provider_status, '') NOT IN ('success', 'failed')";
