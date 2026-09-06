import { z } from "zod";
import { getIpaymuOperationalReadiness } from "@/lib/server/ipaymu";
import { requireAdminSession } from "@/lib/server/admin";
import {
  listWalletTopups,
  readWalletSettings,
  saveWalletSettings,
} from "@/lib/server/wallet";

const settingsSchema = z.object({
  minTopup: z.number().int().min(1000).max(100_000_000),
  ipaymuTopupEnabled: z.boolean(),
  ipaymuCheckoutEnabled: z.boolean(),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const [settings, topups, ipaymu] = await Promise.all([
    readWalletSettings(),
    listWalletTopups(),
    getIpaymuOperationalReadiness(),
  ]);
  return Response.json({
    settings,
    topups,
    gatewayReadiness: { ipaymu },
  });
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    await saveWalletSettings(settingsSchema.parse(await request.json()));
    return Response.json({ ok: true });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Pengaturan saldo gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
