import { readStoreMedia } from "@/lib/server/media";

export async function GET(request: Request) {
  try {
    const key = decodeURIComponent(new URL(request.url).pathname.split("/").pop() ?? "");
    const object = await readStoreMedia(key);
    if (!object) return new Response("Gambar tidak ditemukan.", { status: 404 });

    const headers = new Headers();
    object.writeHttpMetadata(headers);
    headers.set("Cache-Control", "public, max-age=31536000, immutable");
    headers.set("X-Content-Type-Options", "nosniff");
    if (object.httpEtag) headers.set("ETag", object.httpEtag);
    return new Response(object.body, { headers });
  } catch {
    return new Response("Penyimpanan gambar belum tersedia.", { status: 503 });
  }
}
