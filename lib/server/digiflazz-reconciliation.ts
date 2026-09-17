import { getD1 } from "@/db";
import { reconcileProcessingDigiflazzOrder } from "@/lib/server/orders";

export async function reconcileStaleDigiflazzProcessing(
  publicBaseUrl: string,
  limit = 20,
) {
  const db = getD1();
  const rows = await db.prepare(
    `SELECT id
     FROM orders
     WHERE payment_status = 'paid'
       AND fulfillment_type = 'automatic'
       AND provider_code = 'digiflazz'
       AND provider_status = 'processing'
       AND updated_at <= datetime('now', '-2 minutes')
       AND created_at >= datetime('now', '-89 days')
     ORDER BY updated_at ASC
     LIMIT ?`,
  ).bind(Math.min(Math.max(limit, 1), 100)).all<{ id: string }>();

  for (const order of rows.results) {
    try {
      await reconcileProcessingDigiflazzOrder(order.id, publicBaseUrl);
    } catch (error) {
      console.error("Rekonsiliasi transaksi pending DigiFlazz gagal:", error);
    }
  }
}
