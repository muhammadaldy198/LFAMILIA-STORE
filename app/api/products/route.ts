import { getFallbackProducts, readProducts } from "@/lib/server/products";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const stored = await readProducts(false);
    const products = stored.filter((item) => item.packages.length > 0);
    return Response.json({ products: products.length ? products : getFallbackProducts(), databaseReady: true, seeded: products.length > 0 });
  } catch {
    return Response.json({ products: getFallbackProducts(), databaseReady: false, seeded: false });
  }
}

