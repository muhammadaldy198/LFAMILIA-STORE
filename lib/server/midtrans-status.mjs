/**
 * Map Midtrans transaction + fraud state into LFAMILIA payment state.
 * Midtrans requires fraud_status=accept when fraud_status is present before
 * capture/settlement can be treated as successful. A challenge remains pending.
 */
export function mapMidtransSnapStatus(transactionStatus, fraudStatus) {
  const status = transactionStatus.trim().toLowerCase();
  const fraud = (fraudStatus || "").trim().toLowerCase();

  if (status === "settlement" || status === "capture") {
    if (fraud === "deny") return "failed";
    if (fraud && fraud !== "accept") return "pending";
    return "paid";
  }
  if (status === "pending" || status === "authorize") return "pending";
  if (status === "expire") return "expired";
  if (status === "cancel" || status === "deny" || status === "failure") return "failed";
  return "ignore";
}
