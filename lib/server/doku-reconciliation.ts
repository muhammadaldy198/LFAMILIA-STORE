import { getD1 } from "@/db";
import { applyPendingDokuPaymentStatus } from "@/lib/server/doku-payment-transition";
import {
  queryDokuEwalletStatus,
  queryDokuVaStatus,
} from "@/lib/server/doku-status";
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
import { applyDokuWalletTopup } from "@/lib/server/wallet";

type PendingTopup = {
  id: string;
  reference_id: string;
  amount: number;
  payment_method: string;
  doku_request_id: string;
  doku_reference_no: string | null;
  doku_payment_no: string | null;
  doku_environment: "sandbox" | "production";
};

async function queryOrderStatus(order: OrderRecord) {
  if (!order.doku_environment || !order.doku_request_id) return null;
  if (order.payment_method === "va" && order.doku_payment_no) {
    return queryDokuVaStatus({
      environment: order.doku_environment,
      channel: order.payment_channel,
      paymentNo: order.doku_payment_no,
      referenceId: order.reference_id,
    });
  }
  if (order.payment_method === "ewallet") {
    return queryDokuEwalletStatus({
      environment: order.doku_environment,
      referenceId: order.reference_id,
      requestId: order.doku_request_id,
      referenceNo: order.doku_reference_no,
      amount: order.total,
    });
  }
  return null;
}

async function queryTopupStatus(topup: PendingTopup) {
  const [method = "", channel = ""] = topup.payment_method.split(":", 2);
  if (method === "va" && channel && topup.doku_payment_no) {
    return queryDokuVaStatus({
      environment: topup.doku_environment,
      channel,
      paymentNo: topup.doku_payment_no,
      referenceId: topup.reference_id,
    });
  }
  if (method === "ewallet") {
    return queryDokuEwalletStatus({
      environment: topup.doku_environment,
      referenceId: topup.reference_id,
      requestId: topup.doku_request_id,
      referenceNo: topup.doku_reference_no,
      amount: topup.amount,
    });
  }
  return null;
}

/**
 * Reconcile pending DOKU VA/e-wallet transactions only through DOKU's documented
 * SNAP Check Status endpoints, then finalize records whose stored DOKU expiry has
 * elapsed. The status API is polled no sooner than 60 seconds after creation and
 * no more often than once per minute per transaction.
 */
export async function finalizeExpiredDokuPayments(limit = 100) {
  const db = getD1();
  const safeLimit = Math.min(Math.max(limit, 1), 500);
  const publicBaseUrl = getPublicBaseUrl();
  let queriedOrders = 0;
  let queriedWalletTopups = 0;

  const pendingOrders = await db.prepare(
    `SELECT * FROM orders
     WHERE payment_status = 'pending'
       AND payment_method IN ('va', 'ewallet')
       AND doku_request_id IS NOT NULL
       AND doku_environment IN ('sandbox', 'production')
       AND created_at <= datetime('now', '-60 seconds')
       AND (doku_status_checked_at IS NULL OR doku_status_checked_at <= datetime('now', '-60 seconds'))
       AND (doku_expired_at IS NULL OR datetime(doku_expired_at) > datetime('now'))
     ORDER BY COALESCE(doku_status_checked_at, created_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<OrderRecord>();

  for (const order of pendingOrders.results) {
    try {
      await markDokuStatusChecked(order.reference_id);
      const query = await queryOrderStatus(order);
      if (!query) continue;
      queriedOrders += 1;
      await recordOrderEvent({
        orderId: order.id,
        source: "doku",
        eventId: `status-query-${query.requestId}`,
        status: query.status,
        payload: query.raw,
      });
      if (
        query.status === "paid" &&
        Number.isFinite(query.amount) &&
        query.amount === order.total
      ) {
        const firstPaid = await applyPendingDokuPaymentStatus(order, "paid");
        if (firstPaid && order.fulfillment_type === "automatic") {
          await fulfillAutomaticOrder(order.id, publicBaseUrl);
          await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
            console.error("Notifikasi order hasil rekonsiliasi DOKU gagal:", error),
          );
        }
      } else if (query.status === "failed") {
        await applyPendingDokuPaymentStatus(order, "failed");
      }
    } catch (error) {
      console.error("Rekonsiliasi status order DOKU gagal:", error);
    }
  }

  const pendingTopups = await db.prepare(
    `SELECT id, reference_id, amount, payment_method, doku_request_id,
      doku_reference_no, doku_payment_no, doku_environment
     FROM wallet_topups
     WHERE source = 'doku'
       AND status = 'pending'
       AND (payment_method LIKE 'va:%' OR payment_method LIKE 'ewallet:%')
       AND doku_request_id IS NOT NULL
       AND doku_environment IN ('sandbox', 'production')
       AND created_at <= datetime('now', '-60 seconds')
       AND (doku_status_checked_at IS NULL OR doku_status_checked_at <= datetime('now', '-60 seconds'))
       AND (doku_expired_at IS NULL OR datetime(doku_expired_at) > datetime('now'))
     ORDER BY COALESCE(doku_status_checked_at, created_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<PendingTopup>();

  for (const topup of pendingTopups.results) {
    try {
      await db.prepare(
        `UPDATE wallet_topups SET doku_status_checked_at = CURRENT_TIMESTAMP,
          updated_at = updated_at WHERE id = ? AND status = 'pending'`,
      ).bind(topup.id).run();
      const query = await queryTopupStatus(topup);
      if (!query) continue;
      queriedWalletTopups += 1;
      const result = await applyDokuWalletTopup({
        referenceId: topup.reference_id,
        status: query.status,
        originalRequestId: topup.doku_request_id,
        callbackAmount: query.amount,
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(topup.id, topup.reference_id).catch((error) =>
          console.error("Notifikasi top up hasil rekonsiliasi DOKU gagal:", error),
        );
      }
    } catch (error) {
      console.error("Rekonsiliasi status top up DOKU gagal:", error);
    }
  }

  const expiredOrders = await db.prepare(
    `SELECT * FROM orders
     WHERE payment_status = 'pending'
       AND payment_method <> 'wallet'
       AND doku_request_id IS NOT NULL
       AND doku_expired_at IS NOT NULL
       AND datetime(doku_expired_at) <= datetime('now')
     ORDER BY doku_expired_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<OrderRecord>();

  for (const order of expiredOrders.results) {
    await applyPendingDokuPaymentStatus(order, "expired");
    await recordOrderEvent({
      orderId: order.id,
      source: "doku",
      eventId: `local-expiry-${order.id}`,
      status: "expired",
      payload: { expiredAt: order.doku_expired_at, reason: "stored_doku_expiry" },
    });
  }

  const expiredTopups = await db.prepare(
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

  for (const topup of expiredTopups.results) {
    await applyDokuWalletTopup({
      referenceId: topup.reference_id,
      status: "expired",
      originalRequestId: null,
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
