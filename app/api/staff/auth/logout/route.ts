import { cookies } from "next/headers";
import { PANEL_COOKIE_NAME } from "@/lib/server/admin-auth";

export async function POST() {
  const cookieStore = await cookies();
  cookieStore.delete(PANEL_COOKIE_NAME);
  return Response.json({ ok: true }, { headers: { "Cache-Control": "no-store" } });
}
