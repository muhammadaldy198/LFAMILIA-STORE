import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { deleteManagedContent, listHomeBanners, listNews, listSitePopups, saveHomeBanner, saveNews, saveSitePopup } from "@/lib/server/content";
import { isAllowedMediaUrl } from "@/lib/media-url";
import { isAllowedNavigationUrl } from "@/lib/navigation-url";

const mediaUrl = z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar tidak valid.");
const optionalUrl = z.union([
  z.literal(""),
  z.string().trim().max(500).refine(isAllowedNavigationUrl, "URL tujuan tidak valid."),
]);
const bannerSchema = z.object({ id: z.number().int().positive().nullable().optional(), title: z.string().trim().min(2).max(120), subtitle: z.string().trim().max(300), imageUrl: mediaUrl, mobileImageUrl: mediaUrl.optional().or(z.literal("")), ctaLabel: z.string().trim().min(2).max(50), ctaHref: z.string().trim().min(1).max(300).refine(isAllowedNavigationUrl, "Tujuan banner tidak valid."), showDesktop: z.boolean().default(true), showMobile: z.boolean().default(true), isActive: z.boolean(), sortOrder: z.number().int().min(0).max(10000) }).refine((item) => item.showDesktop || item.showMobile, { message: "Pilih minimal satu tampilan banner." });
const popupSchema = z.object({ id: z.number().int().positive().nullable().optional(), title: z.string().trim().min(2).max(140), body: z.string().trim().min(2).max(3000), primaryLabel: z.string().trim().max(80).optional(), primaryHref: optionalUrl, secondaryLabel: z.string().trim().max(80).optional(), secondaryHref: optionalUrl, dismissDays: z.number().int().min(0).max(365), isActive: z.boolean(), sortOrder: z.number().int().min(0).max(10000) });
const newsSchema = z.object({ id: z.number().int().positive().nullable().optional(), slug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(100), title: z.string().trim().min(2).max(180), summary: z.string().trim().max(500), body: z.string().trim().min(2).max(12000), coverUrl: mediaUrl.optional().or(z.literal("")), isPublished: z.boolean(), publishedAt: z.string().trim().max(40).optional().or(z.literal("")), sortOrder: z.number().int().min(0).max(10000) });

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  const [banners, popups, news] = await Promise.all([listHomeBanners(true), listSitePopups(true), listNews(true)]);
  return Response.json({ banners, popups, news });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const payload = await request.json() as { kind?: string; item?: unknown };
    let id: number;
    if (payload.kind === "banner") { const item = bannerSchema.parse(payload.item); id = await saveHomeBanner(item, item.id ?? undefined); }
    else if (payload.kind === "popup") { const item = popupSchema.parse(payload.item); id = await saveSitePopup(item, item.id ?? undefined); }
    else if (payload.kind === "news") { const item = newsSchema.parse(payload.item); id = await saveNews(item, item.id ?? undefined); }
    else throw new Error("Jenis konten tidak valid.");
    return Response.json({ ok: true, id });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Konten gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const url = new URL(request.url);
  const kind = url.searchParams.get("kind");
  const id = Number(url.searchParams.get("id"));
  if (!(["banner", "popup", "news"] as const).includes(kind as "banner" | "popup" | "news") || !Number.isInteger(id) || id < 1) return Response.json({ error: "Konten tidak valid." }, { status: 400 });
  await deleteManagedContent(kind as "banner" | "popup" | "news", id);
  return Response.json({ ok: true });
}
