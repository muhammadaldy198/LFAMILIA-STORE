import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";

export const dynamic = "force-dynamic";

const schema = z.object({ id: z.string().uuid(), status: z.enum(["open", "in_progress", "resolved", "rejected"]), reply: z.string().trim().max(2000).optional() });

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const rows = await getD1().prepare("SELECT r.id, r.kind, r.order_reference, r.subject, r.message, r.status, r.staff_reply, r.created_at, r.updated_at, c.name AS customer_name, c.email AS customer_email FROM customer_support_requests r JOIN customer_users c ON c.id = r.customer_id ORDER BY CASE r.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END, r.updated_at DESC LIMIT 100").all();
    return Response.json({ requests: rows.results, role: access.role });
  } catch (error) { return Response.json({ error: error instanceof Error ? error.message : "Bantuan gagal dimuat." }, { status: 503 }); }
}

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    await getD1().prepare("UPDATE customer_support_requests SET status = ?, staff_reply = ?, handled_by = ?, handled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?").bind(input.status, input.reply || null, access.email, input.id).run();
    return Response.json({ ok: true });
  } catch (error) { return Response.json({ error: error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Balasan gagal disimpan." }, { status: 400 }); }
}
