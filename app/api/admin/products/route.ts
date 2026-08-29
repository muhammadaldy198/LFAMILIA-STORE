import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { deleteProduct, readProducts, saveProduct } from "@/lib/server/products";

export const dynamic = "force-dynamic";

const packageSchema = z.object({
  id: z.string().trim().min(2).max(100),
  label: z.string().trim().min(2).max(100),
  price: z.number().int().min(1).max(100_000_000),
  note: z.string().trim().max(40).optional(),
  providerCode: z.string().trim().regex(/^[a-z0-9-]+$/).max(40).optional(),
  providerSku: z.string().trim().max(100).optional(),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).default(0),
});

const noticeSchema = z.object({
  id: z.number().int().positive().nullable().optional(),
  title: z.string().trim().min(2).max(180),
  body: z.string().trim().min(2).max(2000),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).default(0),
});

const productSchema = z.object({
  dbId: z.number().int().positive().nullable().optional(),
  slug: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(80),
  name: z.string().trim().min(2).max(80),
  publisher: z.string().trim().max(80).default(""),
  category: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(60),
  imageUrl: z.string().trim().url().max(500).optional().or(z.literal("")),
  initials: z.string().trim().min(1).max(3),
  accent: z.string().trim().min(5).max(160),
  inputLabel: z.string().trim().min(2).max(80),
  inputPlaceholder: z.string().trim().min(2).max(120),
  needsServer: z.boolean().default(false),
  popular: z.boolean().default(false),
  instant: z.boolean().default(false),
  fulfillmentType: z.enum(["automatic", "manual"]),
  targetTemplate: z.string().trim().min(3).max(120),
  manualInstructions: z.string().trim().max(500).optional(),
  manualOpenTime: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).optional(),
  manualCloseTime: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).optional(),
  manualTimezone: z.string().trim().max(60).default("Asia/Jakarta"),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).max(10_000).default(0),
  packages: z.array(packageSchema).min(1).max(50),
  notices: z.array(noticeSchema).max(10).default([]),
});

function validateStockKeys(input: z.infer<typeof productSchema>) {
  for (const item of input.packages) {
    if (item.providerCode === "voucher-stock" && (!item.providerSku || !/^[a-z0-9][a-z0-9._:-]{1,99}$/.test(item.providerSku))) {
      throw new Error("Kunci stok internal hanya boleh berisi huruf kecil, angka, titik, garis, titik dua, atau underscore.");
    }
  }
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  try {
    const products = await readProducts(true);
    return Response.json({ products, databaseReady: true, seeded: products.length > 0, adminEmail: access.email, role: access.role });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Database belum siap.", databaseReady: false }, { status: 503 });
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = productSchema.parse(await request.json());
    validateStockKeys(input);
    const id = await saveProduct(input);
    return Response.json({ ok: true, id }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Produk gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = productSchema.extend({ dbId: z.number().int().positive() }).parse(await request.json());
    validateStockKeys(input);
    await saveProduct(input, input.dbId);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Produk gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const id = Number(new URL(request.url).searchParams.get("id"));
  if (!Number.isInteger(id) || id < 1) return Response.json({ error: "ID produk tidak valid." }, { status: 400 });
  await deleteProduct(id);
  return Response.json({ ok: true });
}
