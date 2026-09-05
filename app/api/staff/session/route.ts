import { getRolePanelSession } from "@/lib/server/admin-auth";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const session = await getRolePanelSession(request, "staff");
  if (!session) {
    return Response.json({ error: "Belum login sebagai Staff." }, { status: 401, headers: { "Cache-Control": "no-store" } });
  }
  return Response.json({ session }, { headers: { "Cache-Control": "no-store" } });
}
