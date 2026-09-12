import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { deleteFaq, readFaqs, saveFaq } from "@/lib/server/storefront";

const schema = z.object({
  id: z.number().int().positive().nullable().optional(),
  question: z.string().trim().min(5).max(180),
  answer: z.string().trim().min(5).max(2000),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).max(1000).default(0),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  return Response.json({ faqs: await readFaqs(true) });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    const id = await saveFaq(input, input.id ?? undefined);
    return Response.json({ ok: true, id });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "FAQ gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  const id = Number(new URL(request.url).searchParams.get("id"));
  if (!Number.isInteger(id) || id < 1) return Response.json({ error: "ID FAQ tidak valid." }, { status: 400 });
  await deleteFaq(id);
  return Response.json({ ok: true });
}
