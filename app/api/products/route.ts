import { getFallbackProducts, readProducts } from "@/lib/server/products";
import { readReviewSummaries } from "@/lib/server/reviews";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const stored = await readProducts(false);
    const summaries = await readReviewSummaries().catch(() => new Map<string, { ratingAverage: number; ratingCount: number }>());
    const products = stored.filter((item) => item.packages.length > 0).map((item) => ({ ...item, ...(summaries.get(item.slug) ?? { ratingAverage: 0, ratingCount: 0 }) }));
    return Response.json({ products: products.length ? products : getFallbackProducts(), databaseReady: true, seeded: products.length > 0 });
  } catch {
    return Response.json({ products: getFallbackProducts(), databaseReady: false, seeded: false });
  }
}
