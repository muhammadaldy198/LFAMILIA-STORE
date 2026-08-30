import { getNewsBySlug, listNews } from "@/lib/server/content";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  try {
    const slug = new URL(request.url).searchParams.get("slug");
    if (slug) {
      const article = await getNewsBySlug(slug);
      return article ? Response.json({ article }) : Response.json({ error: "Berita tidak ditemukan." }, { status: 404 });
    }
    return Response.json({ articles: await listNews(false) }, { headers: { "Cache-Control": "public, max-age=60" } });
  } catch {
    return Response.json({ articles: [] });
  }
}
