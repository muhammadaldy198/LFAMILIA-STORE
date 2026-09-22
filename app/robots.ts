import type { MetadataRoute } from "next";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/admin", "/staff", "/api/", "/account", "/checkout", "/reset-password"],
    },
    sitemap: "https://lfamiliastore.my.id/sitemap.xml",
    host: "https://lfamiliastore.my.id",
  };
}
