import { requireAdminSession } from "@/lib/server/admin";
import { seedFallbackProducts } from "@/lib/server/products";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const count = await seedFallbackProducts();
    return Response.json({ ok: true, count });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Katalog utama gagal diimpor." }, { status: 500 });
  }
}
