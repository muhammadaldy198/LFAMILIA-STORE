import { getD1 } from "@/db";
import { hashHex } from "@/lib/server/crypto";
import { digiflazzAdapter } from "@/lib/server/providers/digiflazz";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";

async function claimDigiflazzReconciliation(orderId: string) {
  const result = await getD1().prepare(
    `UPDATE orders
     SET provider_status = 'reconciling', updated_at = CURRENT_TIMESTAMP
     WHERE id = ?
       AND payment_status = 'paid'
       AND fulfillment_type = 'automatic'
       AND lower(trim(provider_code)) = 'digiflazz'
       AND fulfillment_status NOT IN ('success', 'failed', 'cancelled')
       AND (
         (provider_status = 'processing' AND updated_at <= datetime('now', '-2 minutes'))
         OR
         (provider_status = 'reconciling' AND updated_at <= datetime('now', '-5 minutes'))
       )`,
  ).bind(orderId).run();
  return Number(result.meta.changes ?? 0) > 0;
}

async function releaseDigiflazzReconciliation(
  orderId: string,
  message: string,
) {
  await getD1().prepare(
    `UPDATE orders
     SET provider_status = 'processing', fulfillment_status = 'processing',
         provider_message = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND provider_status = 'reconciling'
       AND fulfillment_status NOT IN ('success', 'failed', 'cancelled')`,
  ).bind(message.slice(0, 500), orderId).run();
}

function reconciliationTransitionGuard(status: "success" | "failed" | "processing") {
  if (status === "success") {
    return "fulfillment_status NOT IN ('success', 'cancelled')";
  }
  if (status === "failed") {
    return "fulfillment_status NOT IN ('success', 'failed', 'cancelled')";
  }
  return "fulfillment_status NOT IN ('success', 'failed', 'cancelled')";
}

export async function reconcileStaleDigiflazzProcessing(
  publicBaseUrl: string,
  limit = 20,
) {
  const db = getD1();
  const rows = await db.prepare(
    `SELECT id, reference_id, provider_code, provider_sku, destination, server,
      customer_no, customer_notes, subtotal, provider_max_price_snapshot, package_sku, package_label,
      product_name, buyer_name, buyer_email, buyer_phone
     FROM orders
     WHERE payment_status = 'paid'
       AND fulfillment_type = 'automatic'
       AND lower(trim(provider_code)) = 'digiflazz'
       AND (
         (provider_status = 'processing' AND updated_at <= datetime('now', '-2 minutes'))
         OR
         (provider_status = 'reconciling' AND updated_at <= datetime('now', '-5 minutes'))
       )
       AND created_at >= datetime('now', '-89 days')
     ORDER BY updated_at ASC
     LIMIT ?`,
  ).bind(Math.min(Math.max(limit, 1), 100)).all<{
    id: string;
    reference_id: string;
    provider_code: string;
    provider_sku: string;
    destination: string;
    server: string | null;
    customer_no: string;
    customer_notes: string | null;
    subtotal: number;
    provider_max_price_snapshot: number | null;
    package_sku: string;
    package_label: string;
    product_name: string;
    buyer_name: string;
    buyer_email: string;
    buyer_phone: string;
  }>();

  for (const order of rows.results) {
    try {
      const claimed = await claimDigiflazzReconciliation(order.id);
      if (!claimed) continue;

      const result = await digiflazzAdapter.fulfill({
        id: order.id,
        referenceId: order.reference_id,
        providerCode: order.provider_code.trim().toLowerCase(),
        providerSku: order.provider_sku.trim(),
        destination: order.destination,
        server: order.server,
        customerNo: order.customer_no,
        customerNotes: order.customer_notes,
        subtotal: order.subtotal,
        maxProviderPrice: order.provider_max_price_snapshot,
        packageSku: order.package_sku,
        packageLabel: order.package_label,
        productName: order.product_name,
        buyerName: order.buyer_name,
        buyerEmail: order.buyer_email,
        buyerPhone: order.buyer_phone,
      }, publicBaseUrl);

      const eventId = `reconcile-${hashHex(
        "sha256",
        JSON.stringify([
          order.reference_id,
          result.externalId ?? "",
          result.status,
          result.serialNumber ?? "",
          result.message ?? "",
        ]),
      )}`;
      const batch = await db.batch([
        db.prepare(
          `UPDATE orders SET
             provider_ref_id = COALESCE(?, provider_ref_id),
             provider_status = ?,
             provider_message = ?,
             provider_serial_number = COALESCE(?, provider_serial_number),
             fulfillment_status = ?,
             updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND payment_status = 'paid'
             AND lower(trim(provider_code)) = 'digiflazz'
             AND provider_status = 'reconciling'
             AND ${reconciliationTransitionGuard(result.status)}`,
        ).bind(
          result.externalId,
          result.status,
          result.message,
          result.serialNumber,
          result.status,
          order.id,
        ),
        db.prepare(
          `INSERT OR IGNORE INTO order_events
           (order_id, source, event_id, status, payload_json)
           VALUES (?, 'digiflazz', ?, ?, ?)`,
        ).bind(order.id, eventId, result.status, JSON.stringify(result.raw)),
      ]);

      const persisted = Number(batch[0]?.meta.changes ?? 0) > 0;
      if (persisted && result.status === "success") {
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi order hasil rekonsiliasi DigiFlazz gagal:", error),
        );
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : "Rekonsiliasi DigiFlazz gagal.";
      await releaseDigiflazzReconciliation(order.id, message).catch(() => undefined);
      console.error(JSON.stringify({
        event: "digiflazz_reconciliation_failure",
        orderId: order.id,
        referenceId: order.reference_id,
        message,
      }));
    }
  }
}
