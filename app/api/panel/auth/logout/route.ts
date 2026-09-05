import { clearAdminSessionCookie, clearStaffSessionCookie, deleteAdminSession } from "@/lib/server/admin-auth";

export async function POST(request: Request) {
  await deleteAdminSession(request).catch(() => undefined);
  const headers = new Headers({ "Cache-Control": "no-store" });
  headers.append("Set-Cookie", clearAdminSessionCookie());
  headers.append("Set-Cookie", clearStaffSessionCookie());
  return Response.json({ ok: true }, { headers });
}
