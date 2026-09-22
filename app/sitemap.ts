import type { MetadataRoute } from "next";
import { getD1 } from "@/db";

const BASE = "https://lfamiliastore.my.id";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const staticRoutes = ["", "/faq", "/contact", "/privacy", "/terms", "/refund", "/news"].map((path) => ({
    url: `${BASE}${path}`,
    lastModified: new Date(),
    changeFrequency: path === "" ? "daily" as const : "weekly" as const,
    priority: path === "" ? 1 : 0.6,
  }));

  try {
    const [products, news] = await Promise.all([
      getD1().prepare("SELECT slug, updated_at FROM products WHERE is_active = 1 ORDER BY sort_order ASC").all<{ slug: string; updated_at: string }>(),
      getD1().prepare("SELECT slug, updated_at FROM news_articles WHERE is_published = 1 ORDER BY published_at DESC").all<{ slug: string; updated_at: string }>(),
    ]);
    return [
      ...staticRoutes,
      ...products.results.map((item) => ({ url: `${BASE}/product/${encodeURIComponent(item.slug)}`, lastModified: new Date(item.updated_at), changeFrequency: "daily" as const, priority: 0.9 })),
      ...news.results.map((item) => ({ url: `${BASE}/news/${encodeURIComponent(item.slug)}`, lastModified: new Date(item.updated_at), changeFrequency: "weekly" as const, priority: 0.7 })),
    ];
  } catch {
    return staticRoutes;
  }
}
