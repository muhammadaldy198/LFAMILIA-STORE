import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import { ensureKokinpayNicknameGameCodeBackfill } from "@/lib/server/nickname-config";

export const dynamic = "force-dynamic";

const slugSchema = z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(80);
const updateSchema = z.object({
  slug: slugSchema,
  checkoutType: z.enum(["id", "id-server"]),
  labelId: z.string().trim().min(1).max(80),
  labelServer: z.string().trim().min(1).max(80).optional(),
  nicknameGameCode: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(100).optional().or(z.literal("")),
});

type InputRow = {
  slug: string;
  input_label: string;
  input_placeholder: string;
  input_fields_json: string | null;
  needs_server: number;
  target_template: string;
  nickname_game_code: string | null;
};

function parseFields(value: string | null) {
  try {
    const parsed = JSON.parse(value || "[]") as Array<{ id?: unknown; label?: unknown; placeholder?: unknown }>;
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function serialize(row: InputRow) {
  const fields = parseFields(row.input_fields_json);
  const labelId = typeof fields[0]?.label === "string" && fields[0].label.trim() ? fields[0].label.trim() : row.input_label;
  const labelServer = typeof fields[1]?.label === "string" && fields[1].label.trim() ? fields[1].label.trim() : "Server ID";
  return {
    slug: row.slug,
    checkoutType: row.needs_server ? "id-server" as const : "id" as const,
    labelId,
    labelServer,
    targetTemplate: row.target_template,
    nicknameGameCode: row.nickname_game_code ?? "",
  };
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const slug = slugSchema.parse(new URL(request.url).searchParams.get("slug"));
    await ensureKokinpayNicknameGameCodeBackfill();
    const row = await getD1().prepare(`SELECT slug, input_label, input_placeholder, input_fields_json, needs_server, target_template, nickname_game_code
      FROM products WHERE slug = ? LIMIT 1`).bind(slug).first<InputRow>();
    if (!row) return Response.json({ error: "Produk tidak ditemukan." }, { status: 404 });
    return Response.json({ input: serialize(row) }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const message = error instanceof z.ZodError ? "Slug produk tidak valid." : error instanceof Error ? error.message : "Pengaturan input gagal dimuat.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const input = updateSchema.parse(await request.json());
    const needsServer = input.checkoutType === "id-server";
    const labelServer = input.labelServer?.trim() || "Server ID";
    const fields = [
      { id: "destination", label: input.labelId, placeholder: `Masukkan ${input.labelId}`, required: true },
      ...(needsServer ? [{ id: "server", label: labelServer, placeholder: `Masukkan ${labelServer}`, required: true }] : []),
    ];
    const targetTemplate = needsServer ? "{{destination}}{{server}}" : "{{destination}}";
    const nicknameGameCodeProvided = input.nicknameGameCode !== undefined;
    const nicknameGameCode = input.nicknameGameCode?.trim() || null;
    await ensureKokinpayNicknameGameCodeBackfill();
    const db = getD1();
    const result = await db.prepare(`UPDATE products
      SET input_label = ?, input_placeholder = ?, input_fields_json = ?, needs_server = ?, target_template = ?,
          nickname_game_code = CASE WHEN ? = 1 THEN ? ELSE nickname_game_code END,
          updated_at = CURRENT_TIMESTAMP
      WHERE slug = ?`).bind(
        input.labelId,
        `Masukkan ${input.labelId}`,
        JSON.stringify(fields),
        needsServer ? 1 : 0,
        targetTemplate,
        nicknameGameCodeProvided ? 1 : 0,
        nicknameGameCode,
        input.slug,
      ).run();
    if (!result.meta.changes) return Response.json({ error: "Produk tidak ditemukan." }, { status: 404 });
    const updated = await db.prepare(`SELECT slug, input_label, input_placeholder, input_fields_json, needs_server, target_template, nickname_game_code
      FROM products WHERE slug = ? LIMIT 1`).bind(input.slug).first<InputRow>();
    if (!updated) return Response.json({ error: "Produk tidak ditemukan setelah diperbarui." }, { status: 404 });
    return Response.json({ ok: true, input: serialize(updated) }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message || "Pengaturan input tidak valid." : error instanceof Error ? error.message : "Pengaturan input gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
