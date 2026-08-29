import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { deleteCategory, readCategories, saveCategory } from "@/lib/server/storefront";

const schema = z.object({
  id: z.number().int().positive().nullable().optional(),
  slug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(60),
  name: z.string().trim().min(2).max(60),
  icon: z.string().trim().max(30).default("grid"),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).max(1000).default(0),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  return Response.json({ categories: await readCategories(true) });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    const id = await saveCategory(input, input.id ?? undefined);
    return Response.json({ ok: true, id });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Kategori gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const id = Number(new URL(request.url).searchParams.get("id"));
    if (!Number.isInteger(id) || id < 1) throw new Error("ID kategori tidak valid.");
    await deleteCategory(id);
    return Response.json({ ok: true });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Kategori gagal dihapus." }, { status: 400 });
  }
}
