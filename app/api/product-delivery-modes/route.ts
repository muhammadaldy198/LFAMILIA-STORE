import { listProductDeliveryConfigs } from "@/lib/server/product-delivery";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const products = await listProductDeliveryConfigs(false);
    return Response.json({
      products: products.map((item) => ({ slug: item.slug, mode: item.mode })),
    }, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return Response.json({ products: [] }, { headers: { "Cache-Control": "no-store" } });
  }
}
