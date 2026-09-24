import { logServerError } from "@/lib/server/safe-log";
import { getD1 } from "@/db";
import {
  expireConfirmedMissingMidtransOrder,
  recordExternalPaymentEvent,
} from "@/lib/server/external-payments";
import {
  MidtransTransactionNotFoundError,
  queryMidtransSnapStatus,
} from "@/lib/server/midtrans-snap";
import type { PaymentEnvironment } from "@/lib/server/payment-mode-config";
import { applyPendingExternalPaymentStatus } from "@/lib/server/payment-transition";
import { fulfillAutomaticOrder, type OrderRecord } from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import {
  notifyOrderFulfillmentSuccessById,
  notifyWalletTopupSuccessById,
} from "@/lib/server/transaction-notifications";
import {
  applyExternalWalletTopup,
  expireConfirmedMissingMidtransTopup,
} from "@/lib/server/wallet-external";

type PendingMidtransTopup = {
  id: string;
  reference_id: string;
  payment_total: number;
  gateway_environment: PaymentEnvironment;
  gateway_expired_at: string | null;
};

export async function reconcilePendingMidtransOrders(limit = 100) {
  const db = getD1();
  const safeLimit = Math.min(Math.max(limit, 1), 500);
  const publicBaseUrl = getPublicBaseUrl();
  let queried = 0;
  let settled = 0;

  const pending = await db.prepare(
    `SELECT * FROM orders
     WHERE payment_status = 'pending'
       AND payment_gateway = 'midtrans'
       AND payment_gateway_mode = 'snap'
       AND payment_gateway_environment IN ('sandbox', 'production')
       AND created_at <= datetime('now', '-60 seconds')
       AND (
         gateway_status_checked_at IS NULL
         OR gateway_status_checked_at <= datetime('now', '-60 seconds')
       )
     ORDER BY COALESCE(gateway_status_checked_at, created_at) ASC
     LIMIT ?`,
  ).bind(safeLimit).all<OrderRecord>();

  for (const order of pending.results) {
    try {
      await db.prepare(
        `UPDATE orders
         SET gateway_status_checked_at = CURRENT_TIMESTAMP
         WHERE id = ? AND payment_status = 'pending'`,
      ).bind(order.id).run();

      const environment = order.payment_gateway_environment;
      if (environment !== "sandbox" && environment !== "production") continue;

      const result = await queryMidtransSnapStatus({
        orderId: order.reference_id,
        environment,
      });
      queried += 1;

      await recordExternalPaymentEvent({
        orderId: order.id,
        gateway: "midtrans",
        eventId: `scheduler-snap-${result.transactionId || order.reference_id}-${result.status}`,
        status: result.status,
        payload: result.raw,
      });

      if (result.status === "paid") {
        if (!Number.isFinite(result.amount) || result.amount !== order.total) continue;
        const firstPaid = await applyPendingExternalPaymentStatus(order, "paid", {
          authoritativePaid: true,
        });
        if (firstPaid) {
          settled += 1;
          if (order.fulfillment_type === "automatic") {
            await fulfillAutomaticOrder(order.id, publicBaseUrl);
            await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
              logServerError("Notifikasi order hasil rekonsiliasi Midtrans gagal:", error),
            );
          }
        }
      } else if (result.status === "expired" || result.status === "failed") {
        await applyPendingExternalPaymentStatus(order, result.status, {
          authoritativeExpired: result.status === "expired",
        });
      }
    } catch (error) {
      if (error instanceof MidtransTransactionNotFoundError) {
        const expired = await expireConfirmedMissingMidtransOrder(order.id);
        if (expired) {
          await recordExternalPaymentEvent({
            orderId: order.id,
            gateway: "midtrans",
            eventId: `confirmed-missing-${order.id}`,
            status: "expired",
            payload: { reason: "midtrans_transaction_not_found_after_safe_window" },
          });
        }
        continue;
      }
      logServerError("Rekonsiliasi status order Midtrans gagal:", error);
    }
  }

  return { queried, settled };
}

export async function reconcilePendingMidtransTopups(limit = 100) {
  const db = getD1();
  const safeLimit = Math.min(Math.max(limit, 1), 500);
  let queried = 0;

  const pending = await db.prepare(
    `SELECT id, reference_id, payment_total, gateway_environment, gateway_expired_at
     FROM wallet_topups
     WHERE source = 'midtrans'
       AND payment_gateway = 'midtrans'
       AND payment_gateway_mode = 'snap'
       AND status = 'pending'
       AND gateway_environment IN ('sandbox', 'production')
       AND created_at <= datetime('now', '-60 seconds')
       AND updated_at <= datetime('now', '-60 seconds')
     ORDER BY updated_at ASC, created_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<PendingMidtransTopup>();

  for (const topup of pending.results) {
    try {
      await db.prepare(
        `UPDATE wallet_topups
         SET updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = 'pending'`,
      ).bind(topup.id).run();

      const result = await queryMidtransSnapStatus({
        orderId: topup.reference_id,
        environment: topup.gateway_environment,
      });
      queried += 1;
      if (result.status === "paid") {
        if (!Number.isFinite(result.amount) || result.amount !== topup.payment_total) continue;
        const applied = await applyExternalWalletTopup({
          referenceId: topup.reference_id,
          gateway: "midtrans",
          status: "paid",
          originalRequestId: null,
          callbackAmount: result.amount,
          authoritativePaid: true,
        });
        if (applied.credited) {
          await notifyWalletTopupSuccessById(topup.id, topup.reference_id).catch((error) =>
            logServerError("Notifikasi top up hasil rekonsiliasi Midtrans gagal:", error),
          );
        }
      } else if (result.status === "expired" || result.status === "failed") {
        await applyExternalWalletTopup({
          referenceId: topup.reference_id,
          gateway: "midtrans",
          status: result.status,
          originalRequestId: null,
          callbackAmount: 0,
        });
      }
    } catch (error) {
      if (error instanceof MidtransTransactionNotFoundError) {
        await expireConfirmedMissingMidtransTopup(topup.reference_id);
        continue;
      }
      logServerError("Rekonsiliasi status top up Midtrans gagal:", error);
    }
  }

  return { queried };
}
