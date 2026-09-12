import { getD1 } from "@/db";
import type { OrderRecord } from "@/lib/server/orders";
import {
  consumeOrderPromotion,
  releaseExternalPromotion,
} from "@/lib/server/promotions";

export type DokuTerminalStatus = "paid" | "pending" | "expired" | "failed";

/**
 * DOKU may send or return delayed status updates. Only a locally pending order
 * may transition, so a late paid event cannot revive an expired/failed order
 * and a delayed failure cannot downgrade a paid order.
 */
export async function applyPendingDokuPaymentStatus(
  order: OrderRecord,
  status: DokuTerminalStatus,
) {
  const db = getD1();
  if (status === "pending") return false;

  if (status === "paid") {
    const nextFulfillment =
      order.fulfillment_type === "manual" ? "manual_pending" : "processing";
    const result = await db.prepare(
      `UPDATE orders
       SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND payment_status = 'pending'`,
    ).bind(nextFulfillment, order.id).run();
    const changed = Number(result.meta.changes ?? 0) > 0;
    if (changed) {
      await consumeOrderPromotion(order.voucher_code, order.flash_sale_id, order.id);
    }
    return changed;
  }

  const result = await db.prepare(
    `UPDATE orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'pending'`,
  ).bind(status, order.id).run();
  const changed = Number(result.meta.changes ?? 0) > 0;
  if (changed && (status === "expired" || status === "failed")) {
    await releaseExternalPromotion(order.id);
  }
  return false;
}
