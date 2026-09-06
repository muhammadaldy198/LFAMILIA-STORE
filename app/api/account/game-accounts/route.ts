import { z } from "zod";
import { getD1 } from "@/db";
import { requireCustomerSession } from "@/lib/server/customer-auth";

export const dynamic = "force-dynamic";

type ProductField = { id: string; label: string; required?: boolean };
type ProductRow = {
  slug: string;
  name: string;
  category: string;
  input_fields_json: string | null;
  input_label: string;
  needs_server: number;
};

async function ensureTable() {
  await getD1().prepare(`
    CREATE TABLE IF NOT EXISTS customer_game_accounts (
      id TEXT PRIMARY KEY NOT NULL,
      customer_id TEXT NOT NULL,
      product_slug TEXT NOT NULL,
      label TEXT NOT NULL,
      values_json TEXT NOT NULL DEFAULT '[]',
      nickname TEXT,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
    )
  `).run();
  await getD1().prepare("CREATE INDEX IF NOT EXISTS customer_game_accounts_customer_product_idx ON customer_game_accounts(customer_id, product_slug, updated_at DESC)").run();
}

function parseFields(row: ProductRow): ProductField[] {
  try {
    const parsed = JSON.parse(row.input_fields_json || "[]") as unknown;
    if (Array.isArray(parsed)) {
      const fields = parsed.flatMap((value) => {
        if (!value || typeof value !== "object" || Array.isArray(value)) return [];
        const item = value as Record<string, unknown>;
        const id = typeof item.id === "string" ? item.id.trim() : "";
        const label = typeof item.label === "string" ? item.label.trim() : "";
        if (!id || !label) return [];
        return [{ id, label, required: item.required !== false }];
      });
      if (fields.length) return fields;
    }
  } catch {
    // Legacy products fall back to their standard account fields.
  }
  return [
    { id: "account-id", label: row.input_label || "User ID", required: true },
    ...(row.needs_server ? [{ id: "server-zone", label: "Server / Zone ID", required: true }] : []),
  ];
}

async function readProduct(slug: string) {
  const row = await getD1().prepare(
    "SELECT slug, name, category, input_fields_json, input_label, needs_server FROM products WHERE slug = ? AND is_active = 1 LIMIT 1",
  ).bind(slug).first<ProductRow>();
  if (!row || row.category.trim().toLowerCase() !== "game") throw new Error("Produk game tidak ditemukan.");
  return { row, fields: parseFields(row) };
}

const valueSchema = z.object({
  id: z.string().trim().min(1).max(60),
  value: z.string().trim().max(300),
});

const payloadSchema = z.object({
  productSlug: z.string().trim().regex(/^[a-z0-9-]{2,80}$/),
  label: z.string().trim().min(2).max(60),
  values: z.array(valueSchema).min(1).max(10),
  nickname: z.string().trim().max(100).optional().or(z.literal("")),
});

function normalizeValues(fields: ProductField[], values: Array<{ id: string; value: string }>) {
  const submitted = new Map(values.map((item) => [item.id, item.value.trim()]));
  return fields.map((field) => {
    const value = submitted.get(field.id) ?? "";
    if (field.required !== false && !value) throw new Error(`${field.label} wajib diisi.`);
    return { id: field.id, label: field.label, value };
  }).filter((item) => item.value);
}

export async function GET(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  await ensureTable();
  const product = new URL(request.url).searchParams.get("product")?.trim() ?? "";
  const query = `SELECT a.id, a.product_slug, a.label, a.values_json, a.nickname, a.updated_at,
      p.name AS product_name
    FROM customer_game_accounts a
    JOIN products p ON p.slug = a.product_slug
    WHERE a.customer_id = ? ${product ? "AND a.product_slug = ?" : ""}
    ORDER BY a.updated_at DESC`;
  const statement = getD1().prepare(query);
  const result = product
    ? await statement.bind(customer.id, product).all<{ id: string; product_slug: string; label: string; values_json: string; nickname: string | null; updated_at: string; product_name: string }>()
    : await statement.bind(customer.id).all<{ id: string; product_slug: string; label: string; values_json: string; nickname: string | null; updated_at: string; product_name: string }>();
  return Response.json({
    accounts: result.results.map((row) => ({
      id: row.id,
      productSlug: row.product_slug,
      productName: row.product_name,
      label: row.label,
      nickname: row.nickname,
      values: JSON.parse(row.values_json || "[]"),
      updatedAt: row.updated_at,
    })),
  });
}

export async function POST(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    await ensureTable();
    const input = payloadSchema.parse(await request.json());
    const { fields } = await readProduct(input.productSlug);
    const values = normalizeValues(fields, input.values);
    const id = crypto.randomUUID();
    await getD1().prepare(
      "INSERT INTO customer_game_accounts (id, customer_id, product_slug, label, values_json, nickname) VALUES (?, ?, ?, ?, ?, ?)",
    ).bind(id, customer.id, input.productSlug, input.label, JSON.stringify(values), input.nickname || null).run();
    return Response.json({ ok: true, id }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Akun game gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function PATCH(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    await ensureTable();
    const body = await request.json() as unknown;
    const input = payloadSchema.extend({ id: z.string().uuid() }).parse(body);
    const { fields } = await readProduct(input.productSlug);
    const values = normalizeValues(fields, input.values);
    const result = await getD1().prepare(
      "UPDATE customer_game_accounts SET product_slug = ?, label = ?, values_json = ?, nickname = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND customer_id = ?",
    ).bind(input.productSlug, input.label, JSON.stringify(values), input.nickname || null, input.id, customer.id).run();
    if (Number(result.meta.changes ?? 0) === 0) throw new Error("Akun game tidak ditemukan.");
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Akun game gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  await ensureTable();
  const id = new URL(request.url).searchParams.get("id")?.trim() ?? "";
  if (!/^[0-9a-f-]{36}$/i.test(id)) return Response.json({ error: "ID akun game tidak valid." }, { status: 400 });
  await getD1().prepare("DELETE FROM customer_game_accounts WHERE id = ? AND customer_id = ?").bind(id, customer.id).run();
  return Response.json({ ok: true });
}
