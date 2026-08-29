import { getAdminSession } from "@/lib/server/admin";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const session = await getAdminSession(request);
  if (!session) return Response.json({ error: "Akses admin tidak ditemukan." }, { status: 401 });
  return Response.json({ session }, { headers: { "Cache-Control": "no-store" } });
}
