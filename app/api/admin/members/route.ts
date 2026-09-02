import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  listMembersWithTiers,
  listMemberTierSettings,
  saveMemberTierSettings,
} from "@/lib/server/member-tiers";

export const dynamic = "force-dynamic";

const tierSchema = z.object({
  tier: z.enum(["basic", "gold", "diamond", "platinum"]),
  discountPercent: z.number().min(0).max(100),
  benefits: z.string().trim().max(1000),
});

const updateSchema = z.object({
  settings: z.array(tierSchema).length(4),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const [settings, members] = await Promise.all([
      listMemberTierSettings(),
      listMembersWithTiers(),
    ]);
    return Response.json({ settings, members });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Data member gagal dimuat." },
      { status: 503 },
    );
  }
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = updateSchema.parse(await request.json());
    const unique = new Set(input.settings.map((item) => item.tier));
    if (unique.size !== 4) throw new Error("Semua tier member wajib dikirim satu kali.");
    await saveMemberTierSettings(input.settings);
    return Response.json({ ok: true, settings: await listMemberTierSettings() });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message || "Pengaturan privilege tidak valid."
        : error instanceof Error
          ? error.message
          : "Pengaturan privilege gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
