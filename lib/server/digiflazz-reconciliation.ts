import { getD1 } from "@/db";
import { digiflazzAdapter } from "@/lib/server/providers/digiflazz";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";

export async function reconcileStaleDigiflazzProcessing(
  publicBaseUrl: string,
  limit = 20,
) {
  const db = getD1();
  const rows = await db.prepare(
    `SELECT id, reference_id, provider_code, provider_sku, destination, server,
      customer_no, customer_notes, subtotal, package_sku, package_label,
      product_name, buyer_name, buyer_email, buyer_phone
     FROM orders
     WHERE payment_status = 'paid'
       AND fulfillment_type = 'automatic'
       AND provider_code = 'digiflazz'
       AND provider_status = 'processing'
       AND updated_at <= datetime('now', '-2 minutes')
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
    package_sku: string;
    package_label: string;
    product_name: string;
    buyer_name: string;
    buyer_email: string;
    buyer_phone: string;
  }>();

  for (const order of rows.results) {
    try {
      const result = await digiflazzAdapter.fulfill({
        id: order.id,
        referenceId: order.reference_id,
        providerCode: order.provider_code,
        providerSku: order.provider_sku,
        destination: order.destination,
        server: order.server,
        customerNo: order.customer_no,
        customerNotes: order.customer_notes,
        subtotal: order.subtotal,
        packageSku: order.package_sku,
        packageLabel: order.package_label,
        productName: order.product_name,
        buyerName: order.buyer_name,
        buyerEmail: order.buyer_email,
        buyerPhone: order.buyer_phone,
      }, publicBaseUrl);

      const eventId = `reconcile-${order.id}-${crypto.randomUUID()}`;
      await db.batch([
        db.prepare(
          `INSERT OR IGNORE INTO order_events
           (order_id, source, event_id, status, payload_json)
           VALUES (?, 'digiflazz', ?, ?, ?)`,
        ).bind(order.id, eventId, result.status, JSON.stringify(result.raw)),
        db.prepare(
          `UPDATE orders SET
             provider_ref_id = COALESCE(?, provider_ref_id),
             provider_status = ?,
             provider_message = ?,
             provider_serial_number = COALESCE(?, provider_serial_number),
             fulfillment_status = ?,
             updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND payment_status = 'paid'
             AND provider_code = 'digiflazz'
             AND provider_status = 'processing'`,
        ).bind(
          result.externalId,
          result.status,
          result.message,
          result.serialNumber,
          result.status,
          order.id,
        ),
      ]);

      if (result.status === "success") {
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi order hasil rekonsiliasi DigiFlazz gagal:", error),
        );
      }
    } catch (error) {
      console.error("Rekonsiliasi transaksi pending DigiFlazz gagal:", error);
    }
  }
}
