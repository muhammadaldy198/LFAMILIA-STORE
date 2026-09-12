import { requireAdminSession } from "@/lib/server/admin";
import { readDigiflazzSellerMonitor } from "@/lib/server/digiflazz-monitor";
import { syncDigiflazzPrices } from "@/lib/server/digiflazz-pricing";
import { getDigiflazzBalance, getDigiflazzReadiness } from "@/lib/server/providers/digiflazz";

export const dynamic = "force-dynamic";

async function readDashboard(role: "super_admin" | "admin") {
  const monitor = await readDigiflazzSellerMonitor();
  const readiness = getDigiflazzReadiness();
  let balance: number | null = null;
  let reason = readiness.reason;
  if (role === "super_admin" && readiness.ready) {
    try {
      balance = (await getDigiflazzBalance()).balance;
      reason = null;
    } catch (error) {
      reason = error instanceof Error ? error.message : "Koneksi DigiFlazz gagal.";
    }
  }
  return {
    ...monitor,
    api: {
      ready: readiness.ready && reason === null,
      balance,
      environment: role === "super_admin" ? readiness.environment : null,
      reason,
    },
  };
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;

  try {
    return Response.json(await readDashboard(access.role), {
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
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;

  try {
    const result = await syncDigiflazzPrices({ force: true });
    return Response.json({ ok: true, result, ...(await readDashboard(access.role)) });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Monitor seller DigiFlazz gagal diperbarui." },
      { status: 400 },
    );
  }
}
