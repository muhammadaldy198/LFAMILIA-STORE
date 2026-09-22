import type { MetadataRoute } from "next";
import { absoluteUrl } from "@/lib/seo";
import { listNews } from "@/lib/server/content";

export const dynamic = "force-dynamic";

const publicRoutes = [
  { path: "/", priority: 1, changeFrequency: "daily" as const },
  { path: "/catalog", priority: 0.9, changeFrequency: "daily" as const },
  { path: "/promo", priority: 0.8, changeFrequency: "daily" as const },
  { path: "/news", priority: 0.75, changeFrequency: "daily" as const },
  { path: "/faq", priority: 0.65, changeFrequency: "monthly" as const },
  { path: "/contact", priority: 0.6, changeFrequency: "monthly" as const },
  { path: "/tools", priority: 0.7, changeFrequency: "weekly" as const },
  { path: "/tools/win-rate", priority: 0.62, changeFrequency: "monthly" as const },
  { path: "/tools/magic-wheel", priority: 0.62, changeFrequency: "monthly" as const },
  { path: "/tools/zodiac", priority: 0.62, changeFrequency: "monthly" as const },
  { path: "/leaderboard", priority: 0.55, changeFrequency: "daily" as const },
  { path: "/status", priority: 0.5, changeFrequency: "weekly" as const },
  { path: "/terms", priority: 0.35, changeFrequency: "yearly" as const },
  { path: "/privacy", priority: 0.35, changeFrequency: "yearly" as const },
  { path: "/refund", priority: 0.35, changeFrequency: "yearly" as const },
];

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base = publicRoutes.map((route) => ({
    url: absoluteUrl(route.path),
    priority: route.priority,
    changeFrequency: route.changeFrequency,
  }));
  const articles = await listNews(false).catch(() => []);
  return [
    ...base,
    ...articles.map((article) => ({
      url: absoluteUrl(`/news/${article.slug}`),
      lastModified: article.publishedAt ? new Date(article.publishedAt) : undefined,
      changeFrequency: "monthly" as const,
      priority: 0.58,
    })),
  ];
}
