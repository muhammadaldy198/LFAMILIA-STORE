import type { MetadataRoute } from "next";
import { getD1 } from "@/db";

const origin = "https://lfamiliastore.my.id";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const staticPages: MetadataRoute.Sitemap = [
    ["", 1, "daily"], ["/news", 0.7, "daily"], ["/promo", 0.7, "daily"],
    ["/faq", 0.6, "monthly"], ["/contact", 0.5, "monthly"], ["/leaderboard", 0.5, "daily"],
    ["/privacy", 0.3, "yearly"], ["/terms", 0.3, "yearly"], ["/refund", 0.3, "yearly"],
  ].map(([path, priority, changeFrequency]) => ({
    url: `${origin}${path}`,
    lastModified: new Date(),
    changeFrequency: changeFrequency as "daily" | "monthly" | "yearly",
    priority: priority as number,
  }));

  try {
    const rows = await getD1().prepare(
      "SELECT slug, COALESCE(published_at, updated_at, created_at) AS modified FROM news_articles WHERE is_published = 1 ORDER BY COALESCE(published_at, updated_at, created_at) DESC LIMIT 500",
    ).all<{ slug: string; modified: string }>();
    return [...staticPages, ...rows.results.map((article) => ({
      url: `${origin}/news/${encodeURIComponent(article.slug)}`,
      lastModified: new Date(article.modified),
      changeFrequency: "monthly" as const,
      priority: 0.6,
    }))];
  } catch {
    return staticPages;
  }
}
