import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";

const schema = z.object({
  id: z.number().int().positive().nullable().optional(),
  email: z.string().trim().email().max(150).transform((value) => value.toLowerCase()),
  name: z.string().trim().min(2).max(80),
  role: z.enum(["owner", "staff"]),
  isActive: z.boolean().default(true),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const result = await getD1().prepare("SELECT id, email, name, role, is_active, created_at, updated_at FROM admin_users ORDER BY role ASC, name ASC").all();
  return Response.json({ users: result.results.map((row) => ({ ...row, isActive: Boolean(row.is_active) })) });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    const db = getD1();
    if (input.id) {
      const current = await db.prepare("SELECT role, is_active FROM admin_users WHERE id = ?").bind(input.id).first<{ role: "owner" | "staff"; is_active: number }>();
      if (!current) throw new Error("Admin tidak ditemukan.");
      if (current.role === "owner" && current.is_active && (input.role !== "owner" || !input.isActive)) await ensureAnotherOwner(input.id);
      await db.prepare("UPDATE admin_users SET email = ?, name = ?, role = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        .bind(input.email, input.name, input.role, input.isActive ? 1 : 0, input.id).run();
      return Response.json({ ok: true, id: input.id });
    }
    const row = await db.prepare("INSERT INTO admin_users (email, name, role, is_active) VALUES (?, ?, ?, ?) RETURNING id")
      .bind(input.email, input.name, input.role, input.isActive ? 1 : 0).first<{ id: number }>();
    return Response.json({ ok: true, id: row?.id }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Admin gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const id = Number(new URL(request.url).searchParams.get("id"));
    if (!Number.isInteger(id) || id < 1) throw new Error("ID admin tidak valid.");
    const row = await getD1().prepare("SELECT role FROM admin_users WHERE id = ?").bind(id).first<{ role: "owner" | "staff" }>();
    if (row?.role === "owner") await ensureAnotherOwner(id);
    await getD1().prepare("DELETE FROM admin_users WHERE id = ?").bind(id).run();
    return Response.json({ ok: true });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Admin gagal dihapus." }, { status: 400 });
  }
}

async function ensureAnotherOwner(excludedId: number) {
  const row = await getD1().prepare("SELECT COUNT(*) AS count FROM admin_users WHERE role = 'owner' AND is_active = 1 AND id <> ?").bind(excludedId).first<{ count: number }>();
  if (Number(row?.count || 0) < 1) throw new Error("Toko harus memiliki setidaknya satu Pemilik aktif.");
}
