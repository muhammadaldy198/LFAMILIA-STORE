import type { Metadata } from "next";
import { getNewsBySlug } from "@/lib/server/content";
import { pageMetadata } from "@/lib/seo";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const article = await getNewsBySlug(slug).catch(() => null);
  if (!article) {
    return pageMetadata({
      title: "Berita LFAMILIA",
      description: "Berita dan informasi terbaru dari LFAMILIA STORE.",
      path: `/news/${slug}`,
      type: "article",
    });
  }
  return pageMetadata({
    title: article.title,
    description: article.summary || "Berita dan informasi terbaru dari LFAMILIA STORE.",
    path: `/news/${article.slug}`,
    image: article.coverUrl,
    type: "article",
  });
}

export default function NewsArticleLayout({ children }: { children: React.ReactNode }) {
  return children;
}
