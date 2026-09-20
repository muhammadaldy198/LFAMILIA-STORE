import { getD1 } from "@/db";
import { queryDokuCheckoutStatus } from "@/lib/server/doku-checkout";
import { applyPendingExternalPaymentStatus } from "@/lib/server/payment-transition";
import {
  fulfillAutomaticOrder,
  recordOrderEvent,
  type OrderRecord,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import {
  notifyOrderFulfillmentSuccessById,
  notifyWalletTopupSuccessById,
} from "@/lib/server/transaction-notifications";
import { applyExternalWalletTopup } from "@/lib/server/wallet-external";

type ReconciliationOrder = OrderRecord & {
  payment_gateway: string | null;
  payment_gateway_mode: string | null;
  payment_gateway_environment: "sandbox" | "production" | null;
  gateway_expired_at: string | null;
  gateway_status_checked_at: string | null;
};

type PendingTopup = {
  id: string;
  reference_id: string;
  status: string;
  admin_notes: string | null;
  amount: number;
  payment_total: number;
  payment_gateway_mode: string | null;
  gateway_environment: "sandbox" | "production" | null;
  gateway_expired_at: string | null;
};

async function markOrderStatusChecked(orderId: string) {
  await getD1().prepare(
    `UPDATE orders SET gateway_status_checked_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status IN ('pending', 'expired')`,
  ).bind(orderId).run();
}

async function markTopupStatusChecked(topupId: string) {
  await getD1().prepare(
    `UPDATE wallet_topups SET updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND status IN ('pending', 'rejected')`,
  ).bind(topupId).run();
}

/**
 * Reconcile hosted DOKU Checkout by merchant reference. A persisted provider
 * request ID is intentionally not required because DOKU can accept a Checkout
 * session even if our response persistence times out.
 */
export async function finalizeExpiredDokuPayments(limit = 100) {
  const db = getD1();
  const safeLimit = Math.min(Math.max(limit, 1), 500);
  const publicBaseUrl = getPublicBaseUrl();
  let queriedOrders = 0;
  let queriedWalletTopups = 0;

  const pendingOrders = await db.prepare(
    `SELECT * FROM orders
     WHERE (
         payment_status = 'pending'
         OR (
           payment_status = 'expired'
           AND gateway_expired_at IS NOT NULL
           AND datetime(gateway_expired_at) >= datetime('now', '-24 hours')
         )
       )
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'checkout'
       AND payment_gateway_environment IN ('sandbox', 'production')
       AND created_at <= datetime('now', '-60 seconds')
       AND (
         gateway_status_checked_at IS NULL
         OR gateway_status_checked_at <= datetime('now', '-60 seconds')
       )
     ORDER BY COALESCE(gateway_status_checked_at, created_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<ReconciliationOrder>();

  for (const order of pendingOrders.results) {
    try {
      if (!order.payment_gateway_environment) continue;
      await markOrderStatusChecked(order.id);
      const query = await queryDokuCheckoutStatus({
        referenceId: order.reference_id,
        environment: order.payment_gateway_environment,
      });
      queriedOrders += 1;
      await recordOrderEvent({
        orderId: order.id,
        source: "doku",
        eventId: `checkout-status-${query.requestId}-${query.status}`,
        status: query.status,
        payload: query.raw,
      });

      if (
        query.status === "paid" &&
        Number.isFinite(query.amount) &&
        query.amount === order.total
      ) {
        const firstPaid = await applyPendingExternalPaymentStatus(order, "paid", {
          authoritativePaid: true,
        });
        if (firstPaid && order.fulfillment_type === "automatic") {
          await fulfillAutomaticOrder(order.id, publicBaseUrl);
          await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
            console.error("Notifikasi order hasil rekonsiliasi DOKU gagal:", error),
          );
        }
      } else if (query.status === "expired") {
        await applyPendingExternalPaymentStatus(order, "expired");
      }
    } catch (error) {
      console.error("Rekonsiliasi status order DOKU Checkout gagal:", error);
    }
  }

  const pendingTopups = await db.prepare(
    `SELECT id, reference_id, status, admin_notes, amount, payment_total,
      payment_gateway_mode, gateway_environment, gateway_expired_at
     FROM wallet_topups
     WHERE source = 'doku'
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'checkout'
       AND (
         status = 'pending'
         OR (
           status = 'rejected'
           AND admin_notes = 'Pembayaran kedaluwarsa.'
           AND gateway_expired_at IS NOT NULL
           AND datetime(gateway_expired_at) >= datetime('now', '-24 hours')
         )
       )
       AND gateway_environment IN ('sandbox', 'production')
       AND created_at <= datetime('now', '-60 seconds')
       AND updated_at <= datetime('now', '-60 seconds')
     ORDER BY updated_at ASC, created_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<PendingTopup>();

  for (const topup of pendingTopups.results) {
    try {
      if (!topup.gateway_environment) continue;
      await markTopupStatusChecked(topup.id);
      const query = await queryDokuCheckoutStatus({
        referenceId: topup.reference_id,
        environment: topup.gateway_environment,
      });
      queriedWalletTopups += 1;

      const result = await applyExternalWalletTopup({
        referenceId: topup.reference_id,
        gateway: "doku",
        status: query.status,
        originalRequestId: query.originalRequestId,
        callbackAmount: query.amount,
        authoritativePaid: query.status === "paid",
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(topup.id, topup.reference_id).catch((error) =>
          console.error("Notifikasi top up hasil rekonsiliasi DOKU gagal:", error),
        );
      }
    } catch (error) {
      console.error("Rekonsiliasi status top up DOKU Checkout gagal:", error);
    }
  }

  const expiredOrders = await db.prepare(
    `SELECT * FROM orders
     WHERE payment_status = 'pending'
       AND payment_method <> 'wallet'
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'checkout'
       AND gateway_expired_at IS NOT NULL
       AND datetime(gateway_expired_at) <= datetime('now')
     ORDER BY gateway_expired_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<ReconciliationOrder>();

  for (const order of expiredOrders.results) {
    await applyPendingExternalPaymentStatus(order, "expired");
    const current = await db.prepare(
      "SELECT payment_status FROM orders WHERE id = ? LIMIT 1",
    ).bind(order.id).first<{ payment_status: string }>();
    if (current?.payment_status === "expired") {
      await recordOrderEvent({
        orderId: order.id,
        source: "doku",
        eventId: `local-expiry-${order.id}`,
        status: "expired",
        payload: {
          expiredAt: order.gateway_expired_at,
          reason: "stored_doku_checkout_expiry",
        },
      });
    }
  }

  const expiredTopups = await db.prepare(
    `SELECT reference_id
     FROM wallet_topups
     WHERE source = 'doku'
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'checkout'
       AND status = 'pending'
       AND gateway_expired_at IS NOT NULL
       AND datetime(gateway_expired_at) <= datetime('now')
     ORDER BY gateway_expired_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<{ reference_id: string }>();

  for (const topup of expiredTopups.results) {
    await applyExternalWalletTopup({
      referenceId: topup.reference_id,
      gateway: "doku",
      status: "expired",
      callbackAmount: 0,
    });
  }

  return {
    queriedOrders,
    queriedWalletTopups,
    expiredOrders: expiredOrders.results.length,
    expiredWalletTopups: expiredTopups.results.length,
  };
}
