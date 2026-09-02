import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  listProductDeliveryConfigs,
  saveProductDeliveryConfig,
} from "@/lib/server/product-delivery";

export const dynamic = "force-dynamic";

const schema = z.object({
  slug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(80),
  mode: z.enum(["direct", "voucher", "manual"]),
  inputLabel: z.string().trim().min(2).max(80).optional(),
  inputPlaceholder: z.string().trim().min(2).max(120).optional(),
  needsServer: z.boolean().optional(),
  targetTemplate: z.string().trim().min(3).max(120).optional(),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const products = await listProductDeliveryConfigs(true);
    return Response.json({ products, role: access.role });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Tipe pengiriman gagal dimuat." },
      { status: 503 },
    );
  }
}

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    await saveProductDeliveryConfig(input);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message
      : error instanceof Error
        ? error.message
        : "Tipe pengiriman gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
