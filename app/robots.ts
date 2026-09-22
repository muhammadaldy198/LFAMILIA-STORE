import type { MetadataRoute } from "next";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [{
      userAgent: "*",
      allow: "/",
      disallow: ["/admin", "/admin/", "/staff", "/staff/", "/api/", "/account", "/checkout", "/payment", "/reset-password", "/forgot-password"],
    }],
    sitemap: "https://lfamiliastore.my.id/sitemap.xml",
    host: "https://lfamiliastore.my.id",
  };
}
