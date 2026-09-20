import { getD1 } from "@/db";
import { queryDokuCheckoutStatus } from "@/lib/server/doku-checkout";
import { applyPendingDokuPaymentStatus } from "@/lib/server/doku-payment-transition";
import {
  fulfillAutomaticOrder,
  markDokuStatusChecked,
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
  doku_environment: "sandbox" | "production" | null;
  doku_request_id: string | null;
};

function orderEnvironment(order: ReconciliationOrder) {
  return order.payment_gateway_environment ?? order.doku_environment;
}

async function markOrderStatusChecked(order: ReconciliationOrder) {
  await getD1().prepare(
    `UPDATE orders SET gateway_status_checked_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status IN ('pending', 'expired')`,
  ).bind(order.id).run();
  await markDokuStatusChecked(order.reference_id);
}

/**
 * Reconcile DOKU Checkout using the documented non-SNAP order status endpoint.
 * FAILED is intentionally treated as pending by queryDokuCheckoutStatus because
 * Checkout lets customers retry with another payment method.
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
           AND COALESCE(gateway_expired_at, doku_expired_at) IS NOT NULL
           AND datetime(COALESCE(gateway_expired_at, doku_expired_at)) >= datetime('now', '-24 hours')
         )
       )
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'checkout'
       AND payment_gateway_environment IN ('sandbox', 'production')
       AND (gateway_request_id IS NOT NULL OR doku_request_id IS NOT NULL)
       AND created_at <= datetime('now', '-60 seconds')
       AND (COALESCE(gateway_status_checked_at, doku_status_checked_at) IS NULL
         OR COALESCE(gateway_status_checked_at, doku_status_checked_at) <= datetime('now', '-60 seconds'))
     ORDER BY COALESCE(gateway_status_checked_at, doku_status_checked_at, created_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<ReconciliationOrder>();

  for (const order of pendingOrders.results) {
    try {
      const environment = orderEnvironment(order);
      if (!environment) continue;
      await markOrderStatusChecked(order);
      const query = await queryDokuCheckoutStatus({
        referenceId: order.reference_id,
        environment,
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
        const firstPaid = await applyPendingDokuPaymentStatus(order, "paid", {
          authoritativePaid: true,
        });
        if (firstPaid && order.fulfillment_type === "automatic") {
          await fulfillAutomaticOrder(order.id, publicBaseUrl);
          await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
            console.error("Notifikasi order hasil rekonsiliasi DOKU gagal:", error),
          );
        }
      } else if (query.status === "expired") {
        await applyPendingDokuPaymentStatus(order, "expired");
      }
    } catch (error) {
      console.error("Rekonsiliasi status order DOKU Checkout gagal:", error);
    }
  }

  const pendingTopups = await db.prepare(
    `SELECT id, reference_id, status, admin_notes, amount, payment_total,
      payment_gateway_mode, gateway_environment, doku_environment, doku_request_id
     FROM wallet_topups
     WHERE source = 'doku'
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'checkout'
       AND (
         status = 'pending'
         OR (
           status = 'rejected'
           AND admin_notes IN ('Pembayaran kedaluwarsa.', 'Pembayaran DOKU kedaluwarsa.')
           AND COALESCE(gateway_expired_at, doku_expired_at) IS NOT NULL
           AND datetime(COALESCE(gateway_expired_at, doku_expired_at)) >= datetime('now', '-24 hours')
         )
       )
       AND doku_request_id IS NOT NULL
       AND created_at <= datetime('now', '-60 seconds')
       AND (doku_status_checked_at IS NULL OR doku_status_checked_at <= datetime('now', '-60 seconds'))
     ORDER BY COALESCE(doku_status_checked_at, created_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<PendingTopup>();

  for (const topup of pendingTopups.results) {
    try {
      const environment = topup.gateway_environment ?? topup.doku_environment;
      if (!environment) continue;
      await db.prepare(
        `UPDATE wallet_topups SET doku_status_checked_at = CURRENT_TIMESTAMP,
          updated_at = updated_at
          WHERE id = ?
            AND (
              status = 'pending'
              OR (status = 'rejected' AND admin_notes IN ('Pembayaran kedaluwarsa.', 'Pembayaran DOKU kedaluwarsa.'))
            )`,
      ).bind(topup.id).run();

      const query = await queryDokuCheckoutStatus({
        referenceId: topup.reference_id,
        environment,
      });
      queriedWalletTopups += 1;

      const result = await applyExternalWalletTopup({
        referenceId: topup.reference_id,
        gateway: "doku",
        status: query.status,
        originalRequestId: query.originalRequestId ?? topup.doku_request_id,
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
       AND COALESCE(gateway_expired_at, doku_expired_at) IS NOT NULL
       AND datetime(COALESCE(gateway_expired_at, doku_expired_at)) <= datetime('now')
     ORDER BY COALESCE(gateway_expired_at, doku_expired_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<ReconciliationOrder>();

  for (const order of expiredOrders.results) {
    await applyPendingDokuPaymentStatus(order, "expired");
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
          expiredAt: order.gateway_expired_at ?? order.doku_expired_at,
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
       AND COALESCE(gateway_expired_at, doku_expired_at) IS NOT NULL
       AND datetime(COALESCE(gateway_expired_at, doku_expired_at)) <= datetime('now')
     ORDER BY COALESCE(gateway_expired_at, doku_expired_at) ASC
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
