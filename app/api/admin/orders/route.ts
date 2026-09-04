import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import {
  completeManualOrder,
  getOrderById,
  listOrders,
  recordOrderEvent,
} from "@/lib/server/orders";
import { ensureProductDeliveryTable } from "@/lib/server/product-delivery";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";

export const dynamic = "force-dynamic";

type DeliveryMode = "direct" | "voucher" | "manual";

async function readDeliveryModes() {
  await ensureProductDeliveryTable();
  const result = await getD1()
    .prepare(
      `SELECT p.slug,
       COALESCE(
         dm.mode,
         CASE
           WHEN p.fulfillment_type = 'manual' THEN 'manual'
           WHEN LOWER(TRIM(p.category)) = 'voucher' THEN 'voucher'
           ELSE 'direct'
         END
       ) AS mode
       FROM products p
       LEFT JOIN product_delivery_modes dm ON dm.product_slug = p.slug`,
    )
    .all<{ slug: string; mode: DeliveryMode }>();
  return new Map(result.results.map((row) => [row.slug, row.mode]));
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const [orders, deliveryModes] = await Promise.all([
      listOrders(),
      readDeliveryModes(),
    ]);
    const rows = orders.map((order) => ({
      ...order,
      delivery_mode:
        deliveryModes.get(order.product_slug) ??
        (order.fulfillment_type === "manual" ? "manual" : "direct"),
    }));
    return Response.json({
      orders:
        access.role === "owner"
          ? rows
          : rows.map((order) => ({
              id: order.id,
              reference_id: order.reference_id,
              product_name: order.product_name,
              package_label: order.package_label,
              destination: order.destination,
              server: order.server,
              buyer_name: order.buyer_name,
              buyer_phone: order.buyer_phone,
              customer_inputs_json: order.customer_inputs_json,
              total: null,
              payment_method: order.payment_method,
              payment_status: order.payment_status,
              fulfillment_type: order.fulfillment_type,
              fulfillment_status: order.fulfillment_status,
              provider_code: order.provider_code,
              provider_message: order.provider_message,
              provider_serial_number: order.provider_serial_number,
              delivery_mode: order.delivery_mode,
              created_at: order.created_at,
            })),
      role: access.role,
    });
  } catch (error) {
    return Response.json(
      {
        error: error instanceof Error ? error.message : "Pesanan gagal dimuat.",
      },
      { status: 503 },
    );
  }
}

const actionSchema = z.object({
  id: z.string().uuid(),
  action: z.literal("complete_manual"),
  serialNumber: z.string().trim().min(1).max(500).optional(),
});

async function completeManualVoucher(
  id: string,
  serialNumber: string | undefined,
  adminEmail: string,
) {
  const order = await getOrderById(id);
  if (
    !order ||
    order.fulfillment_type !== "manual" ||
    order.payment_status !== "paid" ||
    order.fulfillment_status !== "manual_pending"
  ) {
    throw new Error("Voucher manual belum siap dikirim atau tidak ditemukan.");
  }

  const deliveryModes = await readDeliveryModes();
  if (deliveryModes.get(order.product_slug) !== "voucher") {
    await completeManualOrder(id, adminEmail);
    return;
  }

  const code = serialNumber?.trim();
  if (!code) throw new Error("Kode voucher / serial wajib diisi sebelum pesanan diselesaikan.");

  const result = await getD1()
    .prepare(
      `UPDATE orders
       SET fulfillment_status = 'success', provider_status = 'manual_done',
           provider_message = ?, provider_serial_number = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND payment_status = 'paid' AND fulfillment_status = 'manual_pending'`,
    )
    .bind(`Kode dikirim oleh ${adminEmail}`, code, id)
    .run();
  if (Number(result.meta.changes ?? 0) === 0) {
    throw new Error("Voucher sudah diproses atau status pesanan berubah.");
  }

  await recordOrderEvent({
    orderId: id,
    source: "admin",
    eventId: `manual-voucher-${id}`,
    status: "success",
    payload: { adminEmail, voucherCodeDelivered: true },
  });
}

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = actionSchema.parse(await request.json());
    await completeManualVoucher(input.id, input.serialNumber, access.email);
    await notifyOrderFulfillmentSuccessById(input.id).catch((error) =>
      console.error("Notifikasi pesanan selesai manual gagal:", error),
    );
    return Response.json({ ok: true });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Pesanan gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}
