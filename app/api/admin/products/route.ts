import { z } from "zod";
import { getD1 } from "@/db";
import { kokinpayGameRequiresServer } from "@/lib/kokinpay-game-codes";
import { requireAdminSession } from "@/lib/server/admin";
import { deleteProduct, readProducts, saveProduct } from "@/lib/server/products";
import { isAllowedMediaUrl } from "@/lib/media-url";
import { readDigiflazzSellerMonitor } from "@/lib/server/digiflazz-monitor";
import { syncDigiflazzProduct } from "@/lib/server/digiflazz-pricing";
import { ensureKokinpayNicknameGameCodeBackfill } from "@/lib/server/nickname-config";

export const dynamic = "force-dynamic";

const packageSchema = z.object({
  id: z.string().trim().min(2).max(100),
  label: z.string().trim().min(2).max(100),
  price: z.number().int().min(1).max(100_000_000),
  note: z.string().trim().max(40).optional(),
  group: z.string().trim().max(60).optional(),
  imageUrl: z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar nominal tidak valid.").optional().or(z.literal("")),
  providerCode: z.enum(["digiflazz", "voucher-stock"]).optional(),
  providerSku: z.string().trim().max(100).optional(),
  supplierPrice: z.number().int().min(0).max(100_000_000).nullable().optional(),
  providerMaxPrice: z.number().int().min(1).max(100_000_000).nullable().optional(),
  pricingMode: z.enum(["manual", "auto"]).default("auto"),
  marginType: z.enum(["fixed", "percent"]).default("fixed"),
  marginValue: z.number().int().min(0).max(1_000_000).default(0),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).default(0),
});

const inputFieldSchema = z.object({
  id: z.string().trim().regex(/^[a-z0-9-]+$/).max(60),
  label: z.string().trim().min(1).max(80),
  placeholder: z.string().trim().max(120).optional().default(""),
  required: z.boolean().default(true),
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
  imageUrl: z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar tidak valid.").optional().or(z.literal("")),
  bannerUrl: z.string().trim().max(500).refine(isAllowedMediaUrl, "URL banner tidak valid.").optional().or(z.literal("")),
  initials: z.string().trim().min(1).max(3),
  accent: z.string().trim().min(5).max(160),
  inputLabel: z.string().trim().min(2).max(80),
  inputPlaceholder: z.string().trim().min(2).max(120),
  inputFields: z.array(inputFieldSchema).max(12).default([]),
  needsServer: z.boolean().default(false),
  popular: z.boolean().default(false),
  instant: z.boolean().default(false),
  fulfillmentType: z.enum(["automatic", "manual"]),
  targetTemplate: z.string().trim().min(3).max(500),
  manualInstructions: z.string().trim().max(500).optional(),
  manualOpenTime: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).optional(),
  manualCloseTime: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/).optional(),
  manualTimezone: z.string().trim().max(60).default("Asia/Jakarta"),
  packageTabsEnabled: z.boolean().default(false),
  packageTabs: z.array(z.string().trim().min(1).max(60)).max(20).default([]),
  isActive: z.boolean().default(true),
  sortOrder: z.number().int().min(0).max(10_000).default(0),
  packages: z.array(packageSchema).max(50).default([]),
  notices: z.array(noticeSchema).max(10).default([]),
});

type ProductInput = z.infer<typeof productSchema>;
type PricingSnapshot = {
  sku: string;
  provider_sku: string;
  supplier_price: number | null;
  provider_max_price: number | null;
  margin_type: "fixed" | "percent";
  margin_value: number;
  price: number;
};

function validateProduct(input: ProductInput) {
  if (input.isActive && input.packages.length === 0) {
    throw new Error("Tambahkan minimal satu nominal dari katalog sebelum mengaktifkan produk.");
  }
  const fieldIds = input.inputFields.map((item) => item.id);
  if (new Set(fieldIds).size !== fieldIds.length) {
    throw new Error("Nama kolom data pelanggan tidak boleh duplikat.");
  }
  if (input.fulfillmentType === "automatic") {
    const tokens = [...input.targetTemplate.matchAll(/\{\{([a-z0-9-]+)\}\}/gi)]
      .map((match) => match[1].toLowerCase());
    const allowedTokens = new Set(["destination", "server", ...fieldIds]);
    const unknownToken = tokens.find((token) => !allowedTokens.has(token));
    if (unknownToken) {
      throw new Error(`Token tujuan provider {{${unknownToken}}} tidak cocok dengan kolom pelanggan.`);
    }
  }
  const tabs = input.packageTabs.map((item) => item.trim());
  if (new Set(tabs.map((item) => item.toLowerCase())).size !== tabs.length) {
    throw new Error("Nama tab nominal tidak boleh duplikat.");
  }
  for (const item of input.packages) {
    if (item.providerCode === "digiflazz" && item.isActive && !item.providerSku) {
      throw new Error("Nominal DigiFlazz aktif wajib memiliki SKU provider.");
    }
    if (item.providerCode === "voucher-stock" && (!item.providerSku || !/^[a-z0-9][a-z0-9._:-]{1,99}$/.test(item.providerSku))) {
      throw new Error("Kunci stok internal hanya boleh berisi huruf kecil, angka, titik, garis, titik dua, atau underscore.");
    }
    if (input.packageTabsEnabled && item.group && !tabs.includes(item.group.trim())) {
      throw new Error(`Tab nominal "${item.group}" belum dibuat pada produk ini.`);
    }
  }
}

async function validateNicknameCheckoutContract(dbId: number, input: ProductInput) {
  await ensureKokinpayNicknameGameCodeBackfill();
  const row = await getD1().prepare(
    "SELECT nickname_game_code FROM products WHERE id = ? LIMIT 1",
  ).bind(dbId).first<{ nickname_game_code: string | null }>();
  const gameCode = row?.nickname_game_code?.trim();
  if (!gameCode) return;

  if (input.category.trim().toLowerCase() === "voucher") {
    throw new Error("Produk voucher tidak boleh memakai Kode Game Nickname. Kosongkan kode game terlebih dahulu.");
  }
  if (!kokinpayGameRequiresServer(gameCode)) return;

  const serverField = input.inputFields[1];
  const hasServerField = Boolean(serverField && serverField.required !== false);
  const hasServerTarget = /\{\{server\}\}/i.test(input.targetTemplate);
  if (!input.needsServer || !hasServerField || !hasServerTarget) {
    throw new Error("Produk dengan kode game nickname ini wajib memakai input ID + Server dan target {{server}}.");
  }
}

async function captureDigiflazzPricing(productId: number) {
  const result = await getD1().prepare(`
    SELECT sku, provider_sku, supplier_price, provider_max_price,
           COALESCE(margin_type, 'fixed') AS margin_type,
           COALESCE(margin_value, 0) AS margin_value,
           price
    FROM product_packages
    WHERE product_id = ? AND provider_code = 'digiflazz' AND provider_sku IS NOT NULL
  `).bind(productId).all<PricingSnapshot>();
  return result.results;
}

function pricingKey(sku: string, providerSku: string | undefined | null) {
  return `${sku}\u0000${providerSku || ""}`;
}

function removePricingAuthorityFromProduct(input: ProductInput, snapshots: PricingSnapshot[]): ProductInput {
  const stored = new Map(snapshots.map((item) => [pricingKey(item.sku, item.provider_sku), item]));
  return {
    ...input,
    packages: input.packages.map((item) => {
      if (item.providerCode !== "digiflazz") return item;
      const existing = stored.get(pricingKey(item.id, item.providerSku));
      return {
        ...item,
        // Product management owns catalog/SKU presentation only. Existing
        // LFAMILIA pricing is kept from D1 and restored after the catalog save.
        price: existing?.price ?? item.price,
        supplierPrice: existing?.supplier_price ?? null,
        providerMaxPrice: null,
        pricingMode: "auto" as const,
        marginType: existing?.margin_type ?? "fixed",
        marginValue: existing?.margin_value ?? 0,
      };
    }),
  };
}

async function restoreDigiflazzPricing(productId: number, input: ProductInput, snapshots: PricingSnapshot[]) {
  const activeKeys = new Set(input.packages
    .filter((item) => item.providerCode === "digiflazz" && item.providerSku)
    .map((item) => pricingKey(item.id, item.providerSku)));
  const keep = snapshots.filter((item) => activeKeys.has(pricingKey(item.sku, item.provider_sku)));
  if (!keep.length) return;
  await getD1().batch(keep.map((item) => getD1().prepare(`
    UPDATE product_packages
       SET supplier_price = ?,
           provider_max_price = ?,
           margin_type = ?,
           margin_value = ?,
           pricing_mode = 'auto',
           price = ?,
           updated_at = CURRENT_TIMESTAMP
     WHERE product_id = ? AND sku = ? AND provider_code = 'digiflazz' AND provider_sku = ?
  `).bind(
    item.supplier_price,
    item.provider_max_price,
    item.margin_type,
    item.margin_value,
    item.price,
    productId,
    item.sku,
    item.provider_sku,
  )));
}

async function refreshSavedDigiflazzSnapshots(productId: number, input: ProductInput) {
  if (!input.packages.some((item) => item.providerCode === "digiflazz" && item.providerSku)) return null;
  try {
    await syncDigiflazzProduct(productId);
    return null;
  } catch (error) {
    return error instanceof Error ? error.message : "Snapshot DigiFlazz belum dapat diperbarui dari cache.";
  }
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    await ensureKokinpayNicknameGameCodeBackfill();
    const products = (await readProducts(true)).map((product) => ({
      ...product,
      packages: [...product.packages].sort((left, right) =>
        left.price - right.price ||
        left.sortOrder - right.sortOrder ||
        left.label.localeCompare(right.label, "id-ID", { numeric: true, sensitivity: "base" }),
      ),
    }));
    const sellerMonitor = access.role === "super_admin" ? await readDigiflazzSellerMonitor() : null;
    return Response.json({ products, databaseReady: true, adminEmail: access.email, role: access.role, sellerMonitor });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Database belum siap.", databaseReady: false }, { status: 503 });
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const parsed = productSchema.parse(await request.json());
    const input = removePricingAuthorityFromProduct(parsed, []);
    validateProduct(input);
    const id = await saveProduct(input);
    const digiflazzSyncWarning = await refreshSavedDigiflazzSnapshots(id, input);
    return Response.json({ ok: true, id, digiflazzSyncWarning }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Produk gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const parsed = productSchema.extend({ dbId: z.number().int().positive() }).parse(await request.json());
    const snapshots = await captureDigiflazzPricing(parsed.dbId);
    const input = removePricingAuthorityFromProduct(parsed, snapshots);
    validateProduct(input);
    await validateNicknameCheckoutContract(input.dbId!, input);
    await saveProduct(input, input.dbId!);
    await restoreDigiflazzPricing(input.dbId!, input, snapshots);
    const digiflazzSyncWarning = await refreshSavedDigiflazzSnapshots(input.dbId!, input);
    return Response.json({ ok: true, digiflazzSyncWarning });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : error instanceof Error ? error.message : "Produk gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  const id = Number(new URL(request.url).searchParams.get("id"));
  if (!Number.isInteger(id) || id < 1) return Response.json({ error: "ID produk tidak valid." }, { status: 400 });
  await deleteProduct(id);
  return Response.json({ ok: true });
}
