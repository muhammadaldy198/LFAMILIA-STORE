import { cookies } from "next/headers";
import { PANEL_COOKIE_NAME } from "@/lib/server/admin-auth";

export async function POST(request: Request) {
  const cookieStore = await cookies();
  cookieStore.delete(PANEL_COOKIE_NAME);
  return Response.redirect(new URL("/admin/panel/login", request.url), 303);
}
