import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  getPricingSettings,
  listDigiflazzPriceList,
  savePricingSettings,
  syncDigiflazzPackage,
  syncDigiflazzProduct,
  syncDigiflazzPrices,
} from "@/lib/server/digiflazz-pricing";

export const dynamic = "force-dynamic";

const schema = z.object({
  isAutoSync: z.boolean().optional(),
  syncNow: z.boolean().optional(),
  productId: z.number().int().positive().optional(),
  packageSku: z.string().trim().min(2).max(100).optional(),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const url = new URL(request.url);
    if (url.searchParams.get("catalog") === "1") {
      return Response.json({
        settings: await getPricingSettings(),
        catalog: await listDigiflazzPriceList(),
      });
    }
    return Response.json({ settings: await getPricingSettings() });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Pengaturan harga belum siap." }, { status: 503 });
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    if (typeof input.isAutoSync === "boolean") await savePricingSettings({ isAutoSync: input.isAutoSync });
    const result = input.productId && input.packageSku
      ? await syncDigiflazzPackage(input.productId, input.packageSku)
      : input.productId
        ? await syncDigiflazzProduct(input.productId)
        : input.syncNow
          ? await syncDigiflazzPrices({ force: true })
          : null;
    return Response.json({ ok: true, result });
  } catch (error) {
    return Response.json({
      error: error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Sinkron harga gagal.",
    }, { status: 400 });
  }
}
