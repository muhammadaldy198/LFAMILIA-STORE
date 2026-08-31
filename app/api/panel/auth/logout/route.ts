import { clearAdminSessionCookie, deleteAdminSession } from "@/lib/server/admin-auth";

export async function POST(request: Request) {
  await deleteAdminSession(request).catch(() => undefined);
  return Response.json({ ok: true }, {
    headers: {
      "Cache-Control": "no-store",
      "Set-Cookie": clearAdminSessionCookie(),
    },
  });
}
