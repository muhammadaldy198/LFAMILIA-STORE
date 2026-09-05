import { clearAdminSessionCookie, deleteRolePanelSession } from "@/lib/server/admin-auth";

export async function POST(request: Request) {
  await deleteRolePanelSession(request, "owner").catch(() => undefined);
  return Response.json(
    { ok: true },
    { headers: { "Cache-Control": "no-store", "Set-Cookie": clearAdminSessionCookie() } },
  );
}
