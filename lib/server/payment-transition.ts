import { getD1 } from "@/db";
import type { OrderRecord } from "@/lib/server/orders";
import { consumeOrderPromotion, releaseExternalPromotion } from "@/lib/server/promotions";

export type ExternalPaymentStatus = "paid" | "pending" | "expired" | "failed";

async function expireIfDue(order: OrderRecord) {
  const result = await getD1().prepare(
    `UPDATE orders SET payment_status = 'expired', updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'pending'
       AND COALESCE(gateway_expired_at, doku_expired_at) IS NOT NULL
       AND datetime(COALESCE(gateway_expired_at, doku_expired_at)) <= datetime('now')`,
  ).bind(order.id).run();
  const changed = Number(result.meta.changes ?? 0) > 0;
  if (changed) await releaseExternalPromotion(order.id);
  return changed;
}

/**
 * Normal local transitions respect stored expiry. A cryptographically verified
 * or authenticated provider status may opt into authoritativePaid so a delayed
 * observation of a genuine payment is not discarded by local clock expiry.
 */
export async function applyPendingExternalPaymentStatus(
  order: OrderRecord,
  status: ExternalPaymentStatus,
  options: { authoritativePaid?: boolean } = {},
) {
  if (status === "pending") return false;
  if (status === "expired") {
    await expireIfDue(order);
    return false;
  }

  const db = getD1();
  if (status === "paid") {
    const nextFulfillment = order.fulfillment_type === "manual" ? "manual_pending" : "processing";
    const result = options.authoritativePaid
      ? await db.prepare(
          `UPDATE orders SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND payment_status IN ('pending', 'expired')`,
        ).bind(nextFulfillment, order.id).run()
      : await db.prepare(
          `UPDATE orders SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND payment_status = 'pending'
             AND (COALESCE(gateway_expired_at, doku_expired_at) IS NULL
               OR datetime(COALESCE(gateway_expired_at, doku_expired_at)) > datetime('now'))`,
        ).bind(nextFulfillment, order.id).run();
    const changed = Number(result.meta.changes ?? 0) > 0;
    if (changed) {
      await consumeOrderPromotion(order.voucher_code, order.flash_sale_id, order.id);
    } else if (!options.authoritativePaid) {
      await expireIfDue(order);
    }
    return changed;
  }

  const result = await db.prepare(
    `UPDATE orders SET payment_status = 'failed', updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'pending'`,
  ).bind(order.id).run();
  if (Number(result.meta.changes ?? 0) > 0) await releaseExternalPromotion(order.id);
  return false;
}
