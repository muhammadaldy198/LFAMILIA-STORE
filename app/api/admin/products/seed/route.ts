import { getAdminEmail, unauthorizedResponse } from "@/lib/server/admin";
import { seedFallbackProducts } from "@/lib/server/products";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  if (!getAdminEmail(request)) return unauthorizedResponse();
  try {
    const count = await seedFallbackProducts();
    return Response.json({ ok: true, count });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Data awal gagal diimpor." }, { status: 500 });
  }
}
