import type { MetadataRoute } from "next";
import { SITE_ORIGIN } from "@/lib/seo";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: ["/", "/catalog", "/promo", "/news", "/faq", "/contact", "/tools", "/leaderboard", "/status", "/terms", "/privacy", "/refund"],
      disallow: [
        "/api/",
        "/admin/",
        "/staff/",
        "/panel/",
        "/account",
        "/checkout",
        "/payment",
        "/track",
        "/login",
        "/forgot-password",
        "/reset-password",
      ],
    },
    sitemap: `${SITE_ORIGIN}/sitemap.xml`,
    host: SITE_ORIGIN,
  };
}
