import { clearStaffSessionCookie, deleteRolePanelSession } from "@/lib/server/admin-auth";

export async function POST(request: Request) {
  await deleteRolePanelSession(request, "staff").catch(() => undefined);
  return Response.json(
    { ok: true },
    { headers: { "Cache-Control": "no-store", "Set-Cookie": clearStaffSessionCookie() } },
  );
}
