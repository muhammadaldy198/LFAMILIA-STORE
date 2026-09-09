import { cookies } from "next/headers";
import { PANEL_COOKIE_NAME } from "@/lib/server/admin-auth";
import { rejectCrossOriginMutation } from "@/lib/server/security";

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const cookieStore = await cookies();
  cookieStore.delete(PANEL_COOKIE_NAME);
  return Response.redirect(new URL("/staff/panel/login", request.url), 303);
}
