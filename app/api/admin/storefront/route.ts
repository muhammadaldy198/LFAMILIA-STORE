import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { readStorefrontSettings, saveStorefrontSettings } from "@/lib/server/storefront";

const optionalUrl = z.string().trim().url().max(500).optional().or(z.literal(""));
const schema = z.object({
  storeName: z.string().trim().min(2).max(80),
  storeShortName: z.string().trim().min(1).max(6),
  tagline: z.string().trim().min(3).max(160),
  logoUrl: optionalUrl,
  announcement: z.string().trim().max(160).optional().or(z.literal("")),
  bannerEnabled: z.boolean(),
  bannerEyebrow: z.string().trim().min(2).max(80),
  bannerTitle: z.string().trim().min(2).max(100),
  bannerHighlight: z.string().trim().min(2).max(100),
  bannerDescription: z.string().trim().min(3).max(300),
  bannerImageUrl: optionalUrl,
  bannerCtaLabel: z.string().trim().min(2).max(40),
  bannerCtaHref: z.string().trim().min(1).max(200),
  supportWhatsapp: z.string().trim().regex(/^\+?[0-9]{8,16}$/).optional().or(z.literal("")),
  supportEmail: z.string().trim().email().max(150).optional().or(z.literal("")),
  instagramUrl: optionalUrl,
  supportHours: z.string().trim().min(3).max(120),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  return Response.json({ settings: await readStorefrontSettings() });
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    await saveStorefrontSettings(input);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Pengaturan toko gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
