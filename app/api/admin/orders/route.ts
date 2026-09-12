import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession, type AdminRole } from "@/lib/server/admin";
import {
  completeManualOrder,
  createOrderIdentity,
  getOrderById,
  listOrders,
  recordOrderEvent,
  type OrderRecord,
} from "@/lib/server/orders";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";

export const dynamic = "force-dynamic";

type DeliveryMode = "direct" | "voucher" | "manual";
type OrderWithMode = OrderRecord & { delivery_mode: DeliveryMode };

async function readDeliveryModes() {
  const result = await getD1()
    .prepare(
      `SELECT slug,
       CASE
         WHEN LOWER(TRIM(category)) = 'voucher' THEN 'voucher'
         WHEN fulfillment_type = 'manual' THEN 'manual'
         ELSE 'direct'
       END AS mode
       FROM products`,
    )
    .all<{ slug: string; mode: DeliveryMode }>();
  return new Map(result.results.map((row) => [row.slug, row.mode]));
}

function withDeliveryMode(order: OrderRecord, deliveryModes: Map<string, DeliveryMode>): OrderWithMode {
  return {
    ...order,
    delivery_mode:
      deliveryModes.get(order.product_slug) ??
      (order.fulfillment_type === "manual" ? "manual" : "direct"),
  };
}

function visibleOrder(order: OrderWithMode, role: AdminRole) {
  if (role === "super_admin") return order;
  return {
    id: order.id,
    reference_id: order.reference_id,
    product_slug: order.product_slug,
    product_name: order.product_name,
    package_sku: order.package_sku,
    package_label: order.package_label,
    destination: order.destination,
    server: order.server,
    nickname: order.nickname,
    buyer_name: order.buyer_name,
    buyer_phone: order.buyer_phone,
    customer_inputs_json: order.customer_inputs_json,
    total: null,
    payment_method: order.payment_method,
    payment_channel: order.payment_channel,
    payment_status: order.payment_status,
    fulfillment_type: order.fulfillment_type,
    fulfillment_status: order.fulfillment_status,
    provider_code: order.provider_code,
    provider_status: order.provider_status,
    provider_message: order.provider_message,
    provider_serial_number: order.provider_serial_number,
    delivery_mode: order.delivery_mode,
    created_at: order.created_at,
    updated_at: order.updated_at,
  };
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const requestedId = new URL(request.url).searchParams.get("id");
    const deliveryModes = await readDeliveryModes();

    if (requestedId) {
      const parsedId = z.string().uuid().safeParse(requestedId);
      if (!parsedId.success) return Response.json({ error: "ID pesanan tidak valid." }, { status: 400 });
      const order = await getOrderById(parsedId.data);
      if (!order) return Response.json({ error: "Pesanan tidak ditemukan." }, { status: 404 });
      const eventRows = await getD1()
        .prepare(
          `SELECT id, source, event_id, status, payload_json, created_at
           FROM order_events WHERE order_id = ? ORDER BY created_at ASC LIMIT 100`,
        )
        .bind(order.id)
        .all<{
          id: number;
          source: string;
          event_id: string;
          status: string;
          payload_json: string;
          created_at: string;
        }>();
      return Response.json(
        {
          order: visibleOrder(withDeliveryMode(order, deliveryModes), access.role),
          events: eventRows.results,
          role: access.role,
        },
        { headers: { "Cache-Control": "no-store" } },
      );
    }

    const orders = await listOrders(500);
    return Response.json(
      {
        orders: orders.map((order) => visibleOrder(withDeliveryMode(order, deliveryModes), access.role)),
        role: access.role,
      },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Pesanan gagal dimuat." },
      { status: 503 },
    );
  }
}

const manualOrderSchema = z.object({
  customer: z.string().trim().min(2).max(120),
  phone: z.string().trim().min(5).max(30),
  product: z.string().trim().min(2).max(120),
  packageName: z.string().trim().min(2).max(120),
  destination: z.string().trim().min(1).max(300),
  total: z.coerce.number().int().min(1).max(100_000_000),
  payment: z.literal("admin_manual"),
});

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const input = manualOrderSchema.parse(await request.json());
    const identity = createOrderIdentity();
    const slug = `manual-${input.product.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "order"}`;
    await getD1().prepare(
      `INSERT INTO orders (
        id, reference_id, product_slug, product_name, package_sku, package_label,
        provider_code, provider_sku, fulfillment_type, target_template, destination,
        server, nickname, customer_no, buyer_name, buyer_email, buyer_phone,
        customer_notes, customer_inputs_json, base_subtotal, subtotal, discount_amount,
        admin_fee, total, payment_method, payment_channel, payment_status, fulfillment_status
      ) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 'manual', '{{destination}}', ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 'admin_manual', 'admin_manual', 'paid', 'manual_pending')`
    ).bind(
      identity.id,
      identity.referenceId,
      slug,
      input.product,
      `MANUAL-${identity.id.slice(0, 8).toUpperCase()}`,
      input.packageName,
      input.destination,
      input.destination,
      input.customer,
      access.email,
      input.phone,
      `Dibuat manual oleh ${access.email}`,
      JSON.stringify([{ id: "destination", label: "Tujuan", value: input.destination }]),
      input.total,
      input.total,
      input.total,
    ).run();
    await recordOrderEvent({
      orderId: identity.id,
      source: "admin",
      eventId: `manual-created-${identity.id}`,
      status: "manual_pending",
      payload: { adminEmail: access.email },
    });
    return Response.json({ ok: true, id: identity.id, referenceId: identity.referenceId }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Pesanan manual gagal dibuat.";
    return Response.json({ error: message }, { status: 400 });
  }
}

const actionSchema = z.object({
  id: z.string().uuid(),
  action: z.literal("complete_manual"),
  serialNumber: z.string().trim().min(1).max(500).optional(),
});

async function completeManualVoucher(id: string, serialNumber: string | undefined, adminEmail: string) {
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
