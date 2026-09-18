import { getD1 } from "@/db";
import type { OrderRecord } from "@/lib/server/orders";
import {
  consumeOrderPromotion,
  releaseExternalPromotion,
} from "@/lib/server/promotions";

export type DokuTerminalStatus = "paid" | "pending" | "expired" | "failed";

async function expirePendingOrderIfDue(order: OrderRecord) {
  const result = await getD1().prepare(
    `UPDATE orders
     SET payment_status = 'expired', updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'pending'
       AND COALESCE(gateway_expired_at, doku_expired_at) IS NOT NULL
       AND datetime(COALESCE(gateway_expired_at, doku_expired_at)) <= datetime('now')`,
  ).bind(order.id).run();
  const changed = Number(result.meta.changes ?? 0) > 0;
  if (changed) await releaseExternalPromotion(order.id);
  return changed;
}

/**
 * Local transitions respect stored expiry. A verified DOKU callback/status may
 * opt into authoritativePaid so a genuine payment observed after local clock
 * expiry is not discarded.
 */
export async function applyPendingDokuPaymentStatus(
  order: OrderRecord,
  status: DokuTerminalStatus,
  options: { authoritativePaid?: boolean } = {},
) {
  const db = getD1();
  if (status === "pending") return false;

  if (status === "paid") {
    const nextFulfillment =
      order.fulfillment_type === "manual" ? "manual_pending" : "processing";
    const result = options.authoritativePaid
      ? await db.prepare(
          `UPDATE orders
           SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND payment_status IN ('pending', 'expired')`,
        ).bind(nextFulfillment, order.id).run()
      : await db.prepare(
          `UPDATE orders
           SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND payment_status = 'pending'
             AND (doku_expired_at IS NULL OR datetime(doku_expired_at) > datetime('now'))`,
        ).bind(nextFulfillment, order.id).run();
    const changed = Number(result.meta.changes ?? 0) > 0;
    if (changed) {
      await consumeOrderPromotion(order.voucher_code, order.flash_sale_id, order.id);
      return true;
    }

    if (!options.authoritativePaid) await expirePendingOrderIfDue(order);
    return false;
  }

  if (status === "expired") {
    await expirePendingOrderIfDue(order);
    return false;
  }

  const result = await db.prepare(
    `UPDATE orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'pending'`,
  ).bind(status, order.id).run();
  const changed = Number(result.meta.changes ?? 0) > 0;
  if (changed && status === "failed") {
    await releaseExternalPromotion(order.id);
  }
  return false;
}
