import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  getDigiflazzPriceListCacheMeta,
  getPricingSettings,
  listDigiflazzPriceList,
  savePricingSettings,
  syncDigiflazzPackage,
  syncDigiflazzProduct,
  syncDigiflazzPrices,
  updateDigiflazzPackagePricing,
} from "@/lib/server/digiflazz-pricing";

export const dynamic = "force-dynamic";

const actionSchema = z.object({
  isAutoSync: z.boolean().optional(),
  syncNow: z.boolean().optional(),
  productId: z.number().int().positive().optional(),
  packageSku: z.string().trim().min(2).max(100).optional(),
});

const pricingSchema = z.object({
  packageId: z.number().int().positive(),
  maxPrice: z.number().int().min(1).max(100_000_000),
  marginType: z.enum(["fixed", "percent"]),
  marginValue: z.number().int().min(0).max(1_000_000),
});

function errorResponse(error: unknown, fallback: string) {
  return Response.json({
    error: error instanceof z.ZodError
      ? error.issues[0]?.message
      : error instanceof Error
        ? error.message
        : fallback,
  }, { status: 400 });
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const url = new URL(request.url);
    if (url.searchParams.get("catalog") === "1") {
      const [settings, catalog, cache] = await Promise.all([
        getPricingSettings(),
        listDigiflazzPriceList(),
        getDigiflazzPriceListCacheMeta(),
      ]);
      return Response.json({ settings, catalog, cache });
    }
    return Response.json({ settings: await getPricingSettings(), cache: await getDigiflazzPriceListCacheMeta() });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Pengaturan harga belum siap." }, { status: 503 });
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const input = actionSchema.parse(await request.json());
    if (typeof input.isAutoSync === "boolean") await savePricingSettings({ isAutoSync: input.isAutoSync });
    const result = input.productId && input.packageSku
      ? await syncDigiflazzPackage(input.productId, input.packageSku)
      : input.productId
        ? await syncDigiflazzProduct(input.productId)
        : input.syncNow
          ? await syncDigiflazzPrices({ force: true })
          : null;
    return Response.json({ ok: true, result, cache: await getDigiflazzPriceListCacheMeta() });
  } catch (error) {
    return errorResponse(error, "Sinkron harga gagal.");
  }
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const input = pricingSchema.parse(await request.json());
    const pricing = await updateDigiflazzPackagePricing(input);
    return Response.json({ ok: true, pricing });
  } catch (error) {
    return errorResponse(error, "Pengaturan harga LFAMILIA gagal disimpan.");
  }
}
