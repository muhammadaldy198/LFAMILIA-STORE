import { getD1 } from "@/db";
import { queryMidtransSnapStatus, type PaymentEnvironment } from "@/lib/server/midtrans-snap";
import { notifyWalletTopupSuccessById } from "@/lib/server/transaction-notifications";
import { applyExternalWalletTopup } from "@/lib/server/wallet-external";

type PendingMidtransTopup = {
  id: string;
  reference_id: string;
  payment_total: number;
  gateway_environment: PaymentEnvironment;
  gateway_expired_at: string | null;
};

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
       AND (gateway_expired_at IS NULL OR datetime(gateway_expired_at) > datetime('now'))
     ORDER BY created_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<PendingMidtransTopup>();

  for (const topup of pending.results) {
    try {
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
        });
        if (applied.credited) {
          await notifyWalletTopupSuccessById(topup.id, topup.reference_id).catch((error) =>
            console.error("Notifikasi top up hasil rekonsiliasi Midtrans gagal:", error),
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
      console.error("Rekonsiliasi status top up Midtrans gagal:", error);
    }
  }

  const expired = await db.prepare(
    `SELECT reference_id
     FROM wallet_topups
     WHERE source = 'midtrans'
       AND payment_gateway = 'midtrans'
       AND payment_gateway_mode = 'snap'
       AND status = 'pending'
       AND gateway_expired_at IS NOT NULL
       AND datetime(gateway_expired_at) <= datetime('now')
     ORDER BY gateway_expired_at ASC
     LIMIT ?`,
  ).bind(safeLimit).all<{ reference_id: string }>();

  for (const topup of expired.results) {
    await applyExternalWalletTopup({
      referenceId: topup.reference_id,
      gateway: "midtrans",
      status: "expired",
      originalRequestId: null,
      callbackAmount: 0,
    });
  }

  return { queried, expired: expired.results.length };
}
