import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { getPricingSettings, savePricingSettings, syncDigiflazzPrices } from "@/lib/server/digiflazz-pricing";
export const dynamic = "force-dynamic";
const schema = z.object({ isAutoSync: z.boolean(), marginType: z.enum(["fixed", "percent"]), marginValue: z.number().int().min(0).max(1000000), syncNow: z.boolean().optional() });
export async function GET(request: Request) { const access = await requireAdminSession(request, "owner"); if (access instanceof Response) return access; try { return Response.json({ settings: await getPricingSettings() }); } catch (error) { return Response.json({ error: error instanceof Error ? error.message : "Pengaturan harga belum siap." }, { status: 503 }); } }
export async function POST(request: Request) { const access = await requireAdminSession(request, "owner"); if (access instanceof Response) return access; try { const input = schema.parse(await request.json()); await savePricingSettings(input); const result = input.syncNow ? await syncDigiflazzPrices() : null; return Response.json({ ok: true, result }); } catch (error) { return Response.json({ error: error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Sinkron harga gagal." }, { status: 400 }); } }
