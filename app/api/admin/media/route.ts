import { requireAdminSession } from "@/lib/server/admin";
import { uploadStoreMedia } from "@/lib/server/media";
import { rejectCrossOriginMutation } from "@/lib/server/security";

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;

  try {
    const form = await request.formData();
    const file = form.get("file");
    if (!(file instanceof File)) throw new Error("Pilih file gambar terlebih dahulu.");
    const key = await uploadStoreMedia(file);
    return Response.json({ url: `/api/media/${key}` }, { status: 201 });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Gambar gagal diunggah." }, { status: 400 });
  }
}
