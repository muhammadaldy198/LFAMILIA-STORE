import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { readProducts, saveProductContent } from "@/lib/server/products";
import { isAllowedMediaUrl } from "@/lib/media-url";

const noticeSchema = z.object({
  title: z.string().trim().min(2).max(180),
  body: z.string().trim().min(2).max(2000),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).default(0),
});

const schema = z.object({
  dbId: z.number().int().positive(),
  imageUrl: z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar tidak valid.").optional().or(z.literal("")),
  bannerUrl: z.string().trim().max(500).refine(isAllowedMediaUrl, "URL banner tidak valid.").optional().or(z.literal("")),
  manualInstructions: z.string().trim().max(500).optional(),
  manualOpenTime: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).optional().or(z.literal("")),
  manualCloseTime: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).optional().or(z.literal("")),
  manualTimezone: z.enum(["Asia/Jakarta", "Asia/Makassar", "Asia/Jayapura"]).default("Asia/Jakarta"),
  notices: z.array(noticeSchema).max(10).default([]),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const products = await readProducts(true);
    return Response.json({
      products: products.map((product) => ({
        dbId: product.dbId,
        name: product.name,
        slug: product.slug,
        imageUrl: product.imageUrl || "",
        bannerUrl: product.bannerUrl || "",
        manualInstructions: product.manualInstructions || "",
        manualOpenTime: product.manualOpenTime || "",
        manualCloseTime: product.manualCloseTime || "",
        manualTimezone: product.manualTimezone || "Asia/Jakarta",
        notices: product.notices.map((notice) => ({ title: notice.title, body: notice.body, isActive: notice.isActive, sortOrder: notice.sortOrder })),
      })),
    }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Konten produk gagal dimuat." }, { status: 503 });
  }
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    await saveProductContent({
      id: input.dbId,
      imageUrl: input.imageUrl,
      bannerUrl: input.bannerUrl,
      manualInstructions: input.manualInstructions,
      manualOpenTime: input.manualOpenTime,
      manualCloseTime: input.manualCloseTime,
      manualTimezone: input.manualTimezone,
      notices: input.notices,
    });
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Informasi produk gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
