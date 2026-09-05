import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import { createAdminCredential, deleteAdminCredential, isValidAdminId, normalizeAdminId, updateAdminCredential } from "@/lib/server/admin-auth";

const schema = z.object({
  id: z.number().int().positive().nullable().optional(),
  username: z.string().trim().min(3, "ID login minimal 3 karakter.").max(32).refine(isValidAdminId, "ID login hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus.").transform(normalizeAdminId),
  name: z.string().trim().min(2).max(80),
  role: z.enum(["owner", "staff"]),
  isActive: z.boolean().default(true),
  password: z.string().max(72).optional().default(""),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const result = await getD1().prepare("SELECT id, email AS username, name, role, is_active, created_at, updated_at FROM admin_users ORDER BY role ASC, name ASC").all();
  return Response.json({ users: result.results.map((row) => ({ ...row, isActive: Boolean(row.is_active) })) });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    const db = getD1();
    if (input.password && input.password.length < 10) throw new Error("Password minimal 10 karakter.");
    if (input.id) {
      const current = await db.prepare("SELECT email, role, is_active FROM admin_users WHERE id = ?").bind(input.id).first<{ email: string; role: "owner" | "staff"; is_active: number }>();
      if (!current) throw new Error("Akun panel tidak ditemukan.");
      if (current.role === "owner" && current.is_active && (input.role !== "owner" || !input.isActive)) await ensureAnotherOwner(input.id);
      const duplicate = await db.prepare("SELECT id FROM admin_users WHERE lower(email) = ? AND id <> ? LIMIT 1").bind(input.username, input.id).first<{ id: number }>();
      if (duplicate) throw new Error("ID login sudah digunakan.");
      await updateAdminCredential(current.email, { username: input.username, name: input.name, password: input.password || undefined, isActive: input.isActive });
      await db.prepare("UPDATE admin_users SET email = ?, name = ?, role = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        .bind(input.username, input.name, input.role, input.isActive ? 1 : 0, input.id).run();
      return Response.json({ ok: true, id: input.id });
    }
    if (input.password.length < 10) throw new Error("Password wajib diisi minimal 10 karakter untuk akun baru.");
    await createAdminCredential({ username: input.username, name: input.name, password: input.password, isActive: input.isActive });
    let row: { id: number } | null = null;
    try {
      row = await db.prepare("INSERT INTO admin_users (email, name, role, is_active) VALUES (?, ?, ?, ?) RETURNING id")
        .bind(input.username, input.name, input.role, input.isActive ? 1 : 0).first<{ id: number }>();
    } catch (error) {
      await deleteAdminCredential(input.username).catch(() => undefined);
      throw error;
    }
    return Response.json({ ok: true, id: row?.id }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Akun panel gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const id = Number(new URL(request.url).searchParams.get("id"));
    if (!Number.isInteger(id) || id < 1) throw new Error("ID login tidak valid.");
    const row = await getD1().prepare("SELECT email, role FROM admin_users WHERE id = ?").bind(id).first<{ email: string; role: "owner" | "staff" }>();
    if (!row) throw new Error("Akun panel tidak ditemukan.");
    if (row?.role === "owner") await ensureAnotherOwner(id);
    await deleteAdminCredential(row.email);
    await getD1().prepare("DELETE FROM admin_users WHERE id = ?").bind(id).run();
    return Response.json({ ok: true });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Akun panel gagal dihapus." }, { status: 400 });
  }
}

async function ensureAnotherOwner(excludedId: number) {
  const row = await getD1().prepare("SELECT COUNT(*) AS count FROM admin_users WHERE role = 'owner' AND is_active = 1 AND id <> ?").bind(excludedId).first<{ count: number }>();
  if (Number(row?.count || 0) < 1) throw new Error("Toko harus memiliki setidaknya satu Pemilik aktif.");
}
