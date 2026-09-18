import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { fulfillAutomaticOrder, getOrderById } from "@/lib/server/orders";
import { importVoucherCodes, listVoucherDashboard, revealVoucherCode } from "@/lib/server/vouchers";

export const dynamic = "force-dynamic";

const importSchema = z.object({
  action: z.literal("import"),
  stockKey: z.string().trim().min(2).max(100),
  codes: z.array(z.string().max(500)).min(1).max(1_000),
});

const revealSchema = z.object({
  action: z.literal("reveal"),
  orderId: z.string().uuid(),
});

const retrySchema = z.object({
  action: z.literal("retry"),
  orderId: z.string().uuid(),
});

function message(error: unknown, fallback: string) {
  if (error instanceof z.ZodError) return error.issues[0]?.message || fallback;
  return error instanceof Error ? error.message : fallback;
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    return Response.json(await listVoucherDashboard(), { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return Response.json({ error: message(error, "Stok voucher gagal dimuat.") }, { status: 503 });
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const body = await request.json();
    if (body?.action === "reveal") {
      const input = revealSchema.parse(body);
      return Response.json(await revealVoucherCode(input.orderId), { headers: { "Cache-Control": "no-store" } });
    }
    const input = importSchema.parse(body);
    return Response.json(await importVoucherCodes(input.stockKey, input.codes), { status: 201 });
  } catch (error) {
    return Response.json({ error: message(error, "Kode voucher gagal diproses.") }, { status: 400 });
  }
}

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const input = retrySchema.parse(await request.json());
    const order = await getOrderById(input.orderId);
    if (!order || order.provider_code?.trim().toLowerCase() !== "voucher-stock" || order.payment_status !== "paid") {
      return Response.json({ error: "Pesanan stok kode yang lunas tidak ditemukan." }, { status: 404 });
    }
    const baseUrl = new URL(request.url).origin;
    await fulfillAutomaticOrder(order.id, baseUrl);
    const updated = await getOrderById(order.id);
    return Response.json({
      ok: updated?.fulfillment_status === "success",
      fulfillmentStatus: updated?.fulfillment_status,
      message: updated?.provider_message,
    });
  } catch (error) {
    return Response.json({ error: message(error, "Pengiriman ulang gagal.") }, { status: 400 });
  }
}
