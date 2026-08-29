import { z } from "zod";
import { getAdminEmail, unauthorizedResponse } from "@/lib/server/admin";
import { completeManualOrder, listOrders } from "@/lib/server/orders";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const adminEmail = getAdminEmail(request);
  if (!adminEmail) return unauthorizedResponse();
  try {
    return Response.json({ orders: await listOrders() });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Pesanan gagal dimuat." }, { status: 503 });
  }
}

const actionSchema = z.object({
  id: z.string().uuid(),
  action: z.literal("complete_manual"),
});

export async function PATCH(request: Request) {
  const adminEmail = getAdminEmail(request);
  if (!adminEmail) return unauthorizedResponse();
  try {
    const input = actionSchema.parse(await request.json());
    await completeManualOrder(input.id, adminEmail);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Pesanan gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}
