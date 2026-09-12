import { z } from "zod";
import { getD1 } from "@/db";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

export const dynamic = "force-dynamic";

const schema = z.object({
  kind: z.enum(["support", "refund"]),
  orderReference: z.string().trim().max(100).optional(),
  subject: z.string().trim().min(4).max(140),
  message: z.string().trim().min(10).max(2000),
});

export async function GET(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    const rows = await getD1().prepare("SELECT id, kind, order_reference, subject, message, status, staff_reply, created_at, updated_at FROM customer_support_requests WHERE customer_id = ? ORDER BY updated_at DESC LIMIT 50").bind(customer.id).all();
    return Response.json({ requests: rows.results }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) { return Response.json({ error: error instanceof Error ? error.message : "Bantuan gagal dimuat." }, { status: 503 }); }
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "customer-support", 10, 3600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak permintaan bantuan. Coba lagi nanti." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    const input = schema.parse(await request.json());
    if (input.kind === "refund" && input.orderReference) {
      const owned = await getD1().prepare("SELECT id FROM orders WHERE reference_id = ? AND customer_id = ? LIMIT 1").bind(input.orderReference, customer.id).first();
      if (!owned) return Response.json({ error: "Pesanan refund tidak ditemukan untuk akun ini." }, { status: 403 });
    }
    const id = crypto.randomUUID();
    await getD1().prepare("INSERT INTO customer_support_requests (id, customer_id, kind, order_reference, subject, message) VALUES (?, ?, ?, ?, ?, ?)").bind(id, customer.id, input.kind, input.orderReference || null, input.subject, input.message).run();
    return Response.json({ id }, { status: 201 });
  } catch (error) { return Response.json({ error: error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Permintaan gagal dikirim." }, { status: 400 }); }
}
