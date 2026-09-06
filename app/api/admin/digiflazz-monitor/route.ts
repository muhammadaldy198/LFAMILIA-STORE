import { requireAdminSession } from "@/lib/server/admin";
import { readDigiflazzSellerMonitor } from "@/lib/server/digiflazz-monitor";
import { syncDigiflazzPrices } from "@/lib/server/digiflazz-pricing";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;

  try {
    return Response.json(await readDigiflazzSellerMonitor(), {
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Monitor seller DigiFlazz gagal dimuat." },
      { status: 503 },
    );
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;

  try {
    const result = await syncDigiflazzPrices({ force: true });
    return Response.json({ ok: true, result, ...(await readDigiflazzSellerMonitor()) });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Monitor seller DigiFlazz gagal diperbarui." },
      { status: 400 },
    );
  }
}
