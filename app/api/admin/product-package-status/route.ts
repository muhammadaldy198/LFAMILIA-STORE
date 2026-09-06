import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { updateProductPackageStatus } from "@/lib/server/products";

const schema = z.object({
  packageId: z.number().int().positive(),
  isActive: z.boolean(),
});

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;

  try {
    const input = schema.parse(await request.json());
    await updateProductPackageStatus(input.packageId, input.isActive);
    return Response.json({ ok: true });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Status nominal gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}
