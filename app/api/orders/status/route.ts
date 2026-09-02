import { z } from "zod";
import { getD1 } from "@/db";
import { getWebsiteVoucherCodeByReference } from "@/lib/server/customer-voucher-codes";
import type { OrderRecord } from "@/lib/server/orders";

export const dynamic = "force-dynamic";

const legacyReference = /^LF-\d{8}-[A-F0-9]{8,12}$/;
const publicReference = /^LF[A-F0-9]{8,12}$/;

const schema = z.object({
  referenceId: z.string().trim().toUpperCase().refine(
    (value) => legacyReference.test(value) || publicReference.test(value),
    "Format invoice tidak valid.",
  ),
});

function publicReferenceId(value: string) {
  if (publicReference.test(value)) return value;
  const token = value.split("-").at(-1) ?? value;
  return `LF${token}`;
}

async function resolveOrder(referenceId: string) {
  const db = getD1();
  if (legacyReference.test(referenceId)) {
    return db.prepare("SELECT * FROM orders WHERE reference_id = ? LIMIT 1")
      .bind(referenceId)
      .first<OrderRecord>();
  }
  const token = referenceId.slice(2);
  return db.prepare("SELECT * FROM orders WHERE reference_id LIKE ? LIMIT 1")
    .bind(`%-${token}`)
    .first<OrderRecord>();
}

function maskDestination(value: string, server: string | null) {
  const trimmed = value.trim();
  const visible = trimmed.length <= 6
    ? `${trimmed.slice(0, 2)}${"•".repeat(Math.max(trimmed.length - 3, 1))}${trimmed.slice(-1)}`
    : `${trimmed.slice(0, 3)}${"•".repeat(Math.min(trimmed.length - 5, 8))}${trimmed.slice(-2)}`;
  return server ? `${visible} (${server})` : visible;
}

export async function POST(request: Request) {
  try {
    const { referenceId } = schema.parse(await request.json());
    const order = await resolveOrder(referenceId);
    if (!order) {
      return Response.json({ error: "Invoice tidak ditemukan." }, { status: 404 });
    }

    const voucherCode = order.payment_status === "paid"
      ? await getWebsiteVoucherCodeByReference(order.reference_id).catch(() => null)
      : null;
    const isInternalVoucherDestination = order.destination.trim() === "00000000";
    const eventsResult = await getD1().prepare(
      `SELECT source, status, created_at FROM order_events
       WHERE order_id = ? ORDER BY created_at ASC, id ASC LIMIT 50`,
    ).bind(order.id).all<{ source: string; status: string; created_at: string }>();

    const events = [
      { source: "system", status: "created", createdAt: order.created_at },
      ...eventsResult.results.map((event) => ({
        source: event.source,
        status: event.status,
        createdAt: event.created_at,
      })),
    ];

    return Response.json({
      order: {
        referenceId: publicReferenceId(order.reference_id),
        productName: order.product_name,
        packageLabel: order.package_label,
        destination: isInternalVoucherDestination ? null : maskDestination(order.destination, order.server),
        total: order.total,
        paymentMethod: order.payment_method,
        paymentChannel: order.payment_channel,
        paymentStatus: order.payment_status,
        fulfillmentStatus: order.fulfillment_status,
        fulfillmentType: order.fulfillment_type,
        paymentUrl: order.payment_status === "pending" ? order.midtrans_payment_url : null,
        voucherCode,
        createdAt: order.created_at,
        updatedAt: order.updated_at,
        events,
      },
    }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json({ error: "Format invoice tidak valid." }, { status: 400 });
    }
    return Response.json({ error: "Status pesanan belum dapat dimuat." }, { status: 503 });
  }
}
