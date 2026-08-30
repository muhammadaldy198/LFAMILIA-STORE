import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import { readStoreMedia } from "@/lib/server/media";

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const key = new URL(request.url).searchParams.get("key")?.trim() ?? "";
  if (!/^media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)$/.test(key)) return new Response("Bukti tidak valid.", { status: 400 });
  const linked = await getD1().prepare("SELECT id FROM wallet_topups WHERE proof_url = ? OR proof_url = ? LIMIT 1")
    .bind(key, `/api/media/${key}`).first<{ id: string }>();
  if (!linked) return new Response("Bukti tidak ditemukan.", { status: 404 });
  const object = await readStoreMedia(key);
  if (!object) return new Response("Bukti tidak ditemukan.", { status: 404 });
  const headers = new Headers();
  object.writeHttpMetadata(headers);
  headers.set("Cache-Control", "private, no-store");
  headers.set("X-Content-Type-Options", "nosniff");
  return new Response(object.body, { headers });
}
