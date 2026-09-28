import type { MetadataRoute } from "next";

const origin = "https://lfamiliastore.my.id";
const laravelOrigin =
  process.env.LFAMILIA_LARAVEL_INTERNAL_URL?.replace(/\/$/, "") ||
  "http://127.0.0.1:8080";

type NewsArticle = {
  slug?: string;
  publishedAt?: string | null;
  updatedAt?: string | null;
  createdAt?: string | null;
};

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const generatedAt = new Date();
  const staticPages: MetadataRoute.Sitemap = [
    ["", 1, "daily"],
    ["/news", 0.7, "daily"],
    ["/promo", 0.7, "daily"],
    ["/faq", 0.6, "monthly"],
    ["/contact", 0.5, "monthly"],
    ["/leaderboard", 0.5, "daily"],
    ["/privacy", 0.3, "yearly"],
    ["/terms", 0.3, "yearly"],
    ["/refund", 0.3, "yearly"],
  ].map(([path, priority, changeFrequency]) => ({
    url: `${origin}${path}`,
    lastModified: generatedAt,
    changeFrequency: changeFrequency as "daily" | "monthly" | "yearly",
    priority: priority as number,
  }));

  try {
    const response = await fetch(`${laravelOrigin}/api/news`, {
      cache: "no-store",
      headers: { accept: "application/json" },
    });
    if (!response.ok) return staticPages;

    const payload = (await response.json()) as { articles?: NewsArticle[] };
    const articles = Array.isArray(payload.articles) ? payload.articles : [];

    return [
      ...staticPages,
      ...articles.flatMap((article) => {
        const slug = article.slug?.trim();
        if (!slug) return [];

        const modifiedValue =
          article.publishedAt ||
          article.updatedAt ||
          article.createdAt ||
          generatedAt.toISOString();
        const modified = new Date(modifiedValue);
        return [{
          url: `${origin}/news/${encodeURIComponent(slug)}`,
          lastModified: Number.isNaN(modified.getTime()) ? generatedAt : modified,
          changeFrequency: "monthly" as const,
          priority: 0.6,
        }];
      }),
    ];
  } catch {
    return staticPages;
  }
}