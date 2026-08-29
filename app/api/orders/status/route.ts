import { z } from "zod";
import { getOrderByReference } from "@/lib/server/orders";

export const dynamic = "force-dynamic";

const schema = z.object({
  referenceId: z.string().trim().toUpperCase().regex(/^LF-\d{8}-[A-F0-9]{8,12}$/),
});

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
    const order = await getOrderByReference(referenceId);
    if (!order) {
      return Response.json({ error: "Invoice tidak ditemukan." }, { status: 404 });
    }

    return Response.json({
      order: {
        referenceId: order.reference_id,
        productName: order.product_name,
        packageLabel: order.package_label,
        destination: maskDestination(order.destination, order.server),
        total: order.total,
        paymentStatus: order.payment_status,
        fulfillmentStatus: order.fulfillment_status,
        fulfillmentType: order.fulfillment_type,
        createdAt: order.created_at,
        updatedAt: order.updated_at,
      },
    }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json({ error: "Format invoice tidak valid." }, { status: 400 });
    }
    return Response.json({ error: "Status pesanan belum dapat dimuat." }, { status: 503 });
  }
}
