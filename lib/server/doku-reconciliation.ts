import { getD1 } from "@/db";
import {
  applyPaymentStatus,
  recordOrderEvent,
  type OrderRecord,
} from "@/lib/server/orders";
import { applyDokuWalletTopup } from "@/lib/server/wallet";

/**
 * Finalize locally expired DOKU transactions without inventing unsupported
 * provider query endpoints. Once the stored DOKU expiry is reached, pending
 * records become terminal and their reserved resources are released once.
 */
export async function finalizeExpiredDokuPayments(limit = 100) {
  const db = getD1();
  const safeLimit = Math.min(Math.max(limit, 1), 500);

  const orders = await db.prepare(
    `SELECT * FROM orders
     WHERE payment_status = 'pending'
       AND payment_method <> 'wallet'
       AND doku_request_id IS NOT NULL
       AND doku_expired_at IS NOT NULL
       AND datetime(doku_expired_at) <= datetime('now')
     ORDER BY doku_expired_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<OrderRecord>();

  for (const order of orders.results) {
    await applyPaymentStatus(order, "expired");
    await recordOrderEvent({
      orderId: order.id,
      source: "doku",
      eventId: `local-expiry-${order.id}`,
      status: "expired",
      payload: { expiredAt: order.doku_expired_at, reason: "stored_doku_expiry" },
    });
  }

  const topups = await db.prepare(
    `SELECT reference_id
     FROM wallet_topups
     WHERE source = 'doku'
       AND status = 'pending'
       AND doku_request_id IS NOT NULL
       AND doku_expired_at IS NOT NULL
       AND datetime(doku_expired_at) <= datetime('now')
     ORDER BY doku_expired_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<{ reference_id: string }>();

  for (const topup of topups.results) {
    await applyDokuWalletTopup({
      referenceId: topup.reference_id,
      status: "expired",
      originalRequestId: null,
      callbackAmount: 0,
    });
  }

  return {
    expiredOrders: orders.results.length,
    expiredWalletTopups: topups.results.length,
  };
}
