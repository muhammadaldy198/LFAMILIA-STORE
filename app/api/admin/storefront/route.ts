import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { readStorefrontSettings, saveStorefrontSettings } from "@/lib/server/storefront";
import { isAllowedMediaUrl } from "@/lib/media-url";
import { isAllowedHttpUrl, isAllowedNavigationUrl } from "@/lib/navigation-url";

const optionalUrl = z.union([
  z.literal(""),
  z.string().trim().max(500).refine(isAllowedHttpUrl, "URL harus menggunakan HTTP atau HTTPS."),
]);
const optionalMediaUrl = z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar tidak valid.").optional().or(z.literal(""));
const schema = z.object({
  storeName: z.string().trim().min(2).max(80),
  storeShortName: z.string().trim().min(1).max(6),
  tagline: z.string().trim().min(3).max(160),
  logoUrl: optionalMediaUrl,
  announcement: z.string().trim().max(160).optional().or(z.literal("")),
  bannerEnabled: z.boolean(),
  bannerEyebrow: z.string().trim().min(2).max(80),
  bannerTitle: z.string().trim().min(2).max(100),
  bannerHighlight: z.string().trim().min(2).max(100),
  bannerDescription: z.string().trim().min(3).max(300),
  bannerImageUrl: optionalMediaUrl,
  bannerCtaLabel: z.string().trim().min(2).max(40),
  bannerCtaHref: z.string().trim().min(1).max(200).refine(isAllowedNavigationUrl, "Tujuan tombol banner tidak valid."),
  supportWhatsapp: z.string().trim().regex(/^\+?[0-9]{8,16}$/).optional().or(z.literal("")),
  supportEmail: z.string().trim().email().max(150).optional().or(z.literal("")),
  instagramUrl: optionalUrl,
  discordUrl: optionalUrl,
  supportHours: z.string().trim().min(3).max(120),
  supportWidgetEnabled: z.boolean(),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  return Response.json({ settings: await readStorefrontSettings({ repairSchema: false }) });
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    const current = access.role === "staff" ? await readStorefrontSettings() : null;
    await saveStorefrontSettings(current ? {
      ...input,
      storeName: current.storeName,
      storeShortName: current.storeShortName,
      tagline: current.tagline,
      logoUrl: current.logoUrl,
      supportWhatsapp: current.supportWhatsapp,
      supportEmail: current.supportEmail,
      instagramUrl: current.instagramUrl,
      discordUrl: current.discordUrl,
    } : input);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Pengaturan toko gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
