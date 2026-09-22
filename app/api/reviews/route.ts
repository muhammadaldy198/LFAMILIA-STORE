import { z } from "zod";
import { getCustomerSession } from "@/lib/server/customer-auth";
import { listFeaturedReviews, listProductReviews, saveGuestProductReview, saveProductReview } from "@/lib/server/reviews";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  if (params.get("featured") === "1") {
    return Response.json({ reviews: await listFeaturedReviews(6) }, { headers: { "Cache-Control": "public, max-age=60" } });
  }
  const productSlug = params.get("product")?.trim() ?? "";
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(productSlug)) return Response.json({ error: "Produk tidak valid." }, { status: 400 });
  const customer = await getCustomerSession(request);
  const reviews = await listProductReviews(productSlug);
  return Response.json({ reviews, customer }, { headers: { "Cache-Control": "no-store" } });
}

const schema = z.object({
  productSlug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(80),
  rating: z.number().int().min(1).max(5),
  title: z.string().trim().max(100).optional(),
  body: z.string().trim().min(5).max(1200),
  referenceId: z.string().trim().max(100).optional(),
  phone: z.string().trim().max(24).optional(),
});

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "customer-review", 10, 3600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak ulasan dikirim. Coba lagi nanti." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  try {
    const input = schema.parse(await request.json());
    const customer = await getCustomerSession(request);

    if (customer) {
      await saveProductReview({
        customerId: customer.id,
        productSlug: input.productSlug,
        rating: input.rating,
        title: input.title,
        body: input.body,
      });
    } else {
      if (!input.referenceId || !input.phone) {
        return Response.json(
          { error: "Masukkan nomor invoice dan nomor kontak yang digunakan saat checkout untuk memverifikasi pembelian." },
          { status: 400, headers: { "Cache-Control": "no-store" } },
        );
      }
      await saveGuestProductReview({
        referenceId: input.referenceId,
        phone: input.phone,
        productSlug: input.productSlug,
        rating: input.rating,
        title: input.title,
        body: input.body,
      });
    }

    return Response.json({ ok: true }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Ulasan gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
