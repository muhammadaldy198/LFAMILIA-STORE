import { getD1 } from "@/db";
import { queryDokuQrisStatus } from "@/lib/server/doku";
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
import { applyExternalWalletTopup } from "@/lib/server/wallet-external";

type ReconciliationOrder = OrderRecord & {
  payment_gateway: string | null;
  payment_gateway_mode: string | null;
  payment_gateway_environment: "sandbox" | "production" | null;
  gateway_request_id: string | null;
  gateway_reference_no: string | null;
  gateway_payment_no: string | null;
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
  payment_method: string;
  doku_request_id: string;
  doku_reference_no: string | null;
  doku_payment_no: string | null;
  doku_environment: "sandbox" | "production";
};

function orderEnvironment(order: ReconciliationOrder) {
  return order.payment_gateway_environment ?? order.doku_environment;
}

async function queryOrderStatus(order: ReconciliationOrder) {
  const environment = orderEnvironment(order);
  if (!environment || order.payment_gateway_mode !== "direct") return null;

  const requestId = order.gateway_request_id ?? order.doku_request_id;
  const referenceNo = order.gateway_reference_no ?? order.doku_reference_no;
  const paymentNo = order.gateway_payment_no ?? order.doku_payment_no;

  if (order.payment_method === "qris" && referenceNo) {
    return queryDokuQrisStatus({
      referenceId: order.reference_id,
      referenceNo,
      environment,
    });
  }
  if (order.payment_method === "va" && paymentNo) {
    return queryDokuVaStatus({
      environment,
      channel: order.payment_channel,
      paymentNo,
      referenceId: order.reference_id,
    });
  }
  if (order.payment_method === "ewallet" && requestId) {
    return queryDokuEwalletStatus({
      environment,
      referenceId: order.reference_id,
      requestId,
      referenceNo,
      amount: order.total,
    });
  }
  return null;
}

async function queryTopupStatus(topup: PendingTopup) {
  const [method = "", channel = ""] = topup.payment_method.split(":", 2);
  if (method === "qris" && topup.doku_reference_no) {
    return queryDokuQrisStatus({
      referenceId: topup.reference_id,
      referenceNo: topup.doku_reference_no,
      environment: topup.doku_environment,
    });
  }
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
      amount: topup.payment_total || topup.amount,
    });
  }
  return null;
}

async function markOrderStatusChecked(order: ReconciliationOrder) {
  await getD1().prepare(
    `UPDATE orders SET gateway_status_checked_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status IN ('pending', 'expired')`,
  ).bind(order.id).run();
  await markDokuStatusChecked(order.reference_id);
}

/**
 * Reconcile pending DOKU Direct API transactions through the documented SNAP
 * status endpoint for QRIS, VA, and e-wallet. Poll no sooner than 60 seconds and
 * no more often than once per minute per transaction. Locally expired records
 * remain eligible for authoritative late-paid recovery for at most 24 hours;
 * provider-final failures stop being polled immediately.
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
           AND NOT EXISTS (
             SELECT 1 FROM order_events oe
             WHERE oe.order_id = orders.id
               AND oe.source = 'doku'
               AND oe.status = 'failed'
           )
         )
       )
       AND payment_method IN ('va', 'ewallet', 'qris')
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'direct'
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
      await markOrderStatusChecked(order);
      const query = await queryOrderStatus(order);
      if (!query) continue;
      queriedOrders += 1;
      await recordOrderEvent({
        orderId: order.id,
        source: "doku",
        eventId: `status-query-${query.requestId}-${query.status}`,
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
      } else if (query.status === "failed" || query.status === "expired") {
        await applyPendingDokuPaymentStatus(order, query.status);
      }
    } catch (error) {
      console.error("Rekonsiliasi status order DOKU gagal:", error);
    }
  }

  const pendingTopups = await db.prepare(
    `SELECT id, reference_id, status, admin_notes, amount, payment_total, payment_method, doku_request_id,
      doku_reference_no, doku_payment_no, doku_environment
     FROM wallet_topups
     WHERE source = 'doku'
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'direct'
       AND (
         status = 'pending'
         OR (
           status = 'rejected'
           AND admin_notes IN ('Pembayaran kedaluwarsa.', 'Pembayaran DOKU kedaluwarsa.')
           AND COALESCE(gateway_expired_at, doku_expired_at) IS NOT NULL
           AND datetime(COALESCE(gateway_expired_at, doku_expired_at)) >= datetime('now', '-24 hours')
         )
       )
       AND (payment_method LIKE 'va:%' OR payment_method LIKE 'ewallet:%' OR payment_method LIKE 'qris:%')
       AND doku_request_id IS NOT NULL
       AND doku_environment IN ('sandbox', 'production')
       AND created_at <= datetime('now', '-60 seconds')
       AND (doku_status_checked_at IS NULL OR doku_status_checked_at <= datetime('now', '-60 seconds'))
     ORDER BY COALESCE(doku_status_checked_at, created_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<PendingTopup>();

  for (const topup of pendingTopups.results) {
    try {
      await db.prepare(
        `UPDATE wallet_topups SET doku_status_checked_at = CURRENT_TIMESTAMP,
          updated_at = updated_at
          WHERE id = ?
            AND (
              status = 'pending'
              OR (status = 'rejected' AND admin_notes IN ('Pembayaran kedaluwarsa.', 'Pembayaran DOKU kedaluwarsa.'))
            )`,
      ).bind(topup.id).run();
      const query = await queryTopupStatus(topup);
      if (!query) continue;
      queriedWalletTopups += 1;
      const result = await applyExternalWalletTopup({
        referenceId: topup.reference_id,
        gateway: "doku",
        status: query.status,
        originalRequestId: topup.doku_request_id,
        callbackAmount: query.amount,
        authoritativePaid: query.status === "paid",
      });
      if (result.credited) {
        await notifyWalletTopupSuccessById(topup.id, topup.reference_id).catch((error) =>
          console.error("Notifikasi top up hasil rekonsiliasi DOKU gagal:", error),
        );
      } else if (query.status === "failed" || query.status === "expired") {
        await db.prepare(
          `UPDATE wallet_topups
           SET admin_notes = ?, updated_at = CURRENT_TIMESTAMP
           WHERE id = ?
             AND status = 'rejected'
             AND admin_notes IN ('Pembayaran kedaluwarsa.', 'Pembayaran DOKU kedaluwarsa.')`,
        ).bind(
          query.status === "expired" ? "Pembayaran DOKU kedaluwarsa terkonfirmasi." : "Pembayaran DOKU gagal terkonfirmasi.",
          topup.id,
        ).run();
      }
    } catch (error) {
      console.error("Rekonsiliasi status top up DOKU gagal:", error);
    }
  }

  const expiredOrders = await db.prepare(
    `SELECT * FROM orders
     WHERE payment_status = 'pending'
       AND payment_method <> 'wallet'
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'direct'
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
          reason: "stored_doku_expiry",
        },
      });
    }
  }

  const expiredTopups = await db.prepare(
    `SELECT reference_id
     FROM wallet_topups
     WHERE source = 'doku'
       AND payment_gateway = 'doku'
       AND payment_gateway_mode = 'direct'
       AND status = 'pending'
       AND doku_request_id IS NOT NULL
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
