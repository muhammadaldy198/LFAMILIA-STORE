import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { listAllReviews, moderateProductReview } from "@/lib/server/reviews";

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  return Response.json({ reviews: await listAllReviews() });
}

const schema = z.object({ id: z.number().int().positive(), isVisible: z.boolean() });

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    await moderateProductReview(input.id, input.isVisible);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : "Ulasan gagal dimoderasi.";
    return Response.json({ error: message }, { status: 400 });
  }
}
