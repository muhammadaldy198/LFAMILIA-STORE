import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { completeManualOrder, listOrders } from "@/lib/server/orders";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const orders = await listOrders();
    return Response.json({
      orders: access.role === "owner" ? orders : orders.map((order) => ({
        id: order.id,
        reference_id: order.reference_id,
        product_name: order.product_name,
        package_label: order.package_label,
        destination: order.destination,
        server: order.server,
        buyer_name: order.buyer_name,
        buyer_phone: order.buyer_phone,
        total: null,
        payment_status: order.payment_status,
        fulfillment_type: order.fulfillment_type,
        fulfillment_status: order.fulfillment_status,
        provider_code: order.provider_code,
        provider_message: order.provider_message,
        created_at: order.created_at,
      })),
      role: access.role,
    });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Pesanan gagal dimuat." }, { status: 503 });
  }
}

const actionSchema = z.object({
  id: z.string().uuid(),
  action: z.literal("complete_manual"),
});

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = actionSchema.parse(await request.json());
    await completeManualOrder(input.id, access.email);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Pesanan gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}
