import { z } from "zod";
import { getCustomerSession, requireCustomerSession } from "@/lib/server/customer-auth";
import { listProductReviews, saveProductReview } from "@/lib/server/reviews";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const productSlug = new URL(request.url).searchParams.get("product")?.trim() ?? "";
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(productSlug)) return Response.json({ error: "Produk tidak valid." }, { status: 400 });
  const customer = await getCustomerSession(request);
  const reviews = await listProductReviews(productSlug);
  return Response.json({ reviews, customer });
}

const schema = z.object({
  productSlug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(80),
  rating: z.number().int().min(1).max(5),
  title: z.string().trim().max(100).optional(),
  body: z.string().trim().min(5).max(1200),
});

export async function POST(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    const input = schema.parse(await request.json());
    await saveProductReview({ ...input, customerId: customer.id });
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Ulasan gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
