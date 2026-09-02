import { getD1 } from "@/db";

export type ProductDeliveryMode = "direct" | "voucher" | "manual";

export type ProductDeliveryConfig = {
  slug: string;
  name: string;
  category: string;
  mode: ProductDeliveryMode;
  inputLabel: string;
  inputPlaceholder: string;
  needsServer: boolean;
  targetTemplate: string;
  isActive: boolean;
};

let tablePromise: Promise<void> | null = null;

export async function ensureProductDeliveryTable() {
  if (!tablePromise) {
    tablePromise = (async () => {
      const db = getD1();
      await db.prepare(`CREATE TABLE IF NOT EXISTS product_delivery_modes (
        product_slug TEXT PRIMARY KEY,
        mode TEXT NOT NULL CHECK (mode IN ('direct', 'voucher', 'manual')),
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )`).run();
    })().catch((error) => {
      tablePromise = null;
      throw error;
    });
  }
  return tablePromise;
}

function normalizeMode(value: string | null, category: string, fulfillmentType: string): ProductDeliveryMode {
  if (value === "direct" || value === "voucher" || value === "manual") return value;
  if (fulfillmentType === "manual") return "manual";
  if (category.trim().toLowerCase() === "voucher") return "voucher";
  return "direct";
}

async function ensureDefaultModes() {
  const db = getD1();
  await db.prepare(`INSERT INTO product_delivery_modes (product_slug, mode, updated_at)
    SELECT p.slug,
      CASE
        WHEN p.fulfillment_type = 'manual' THEN 'manual'
        WHEN LOWER(TRIM(p.category)) = 'voucher' THEN 'voucher'
        ELSE 'direct'
      END,
      CURRENT_TIMESTAMP
    FROM products p
    WHERE NOT EXISTS (
      SELECT 1 FROM product_delivery_modes dm WHERE dm.product_slug = p.slug
    )`).run();
}

async function reconcileExplicitModes() {
  const db = getD1();
  await ensureDefaultModes();
  await db.prepare(`UPDATE products
    SET fulfillment_type = CASE
      WHEN slug IN (SELECT product_slug FROM product_delivery_modes WHERE mode = 'manual') THEN 'manual'
      WHEN slug IN (SELECT product_slug FROM product_delivery_modes WHERE mode IN ('direct', 'voucher'))
        AND EXISTS (
          SELECT 1 FROM product_packages pp
          WHERE pp.product_id = products.id AND pp.is_active = 1
        )
        AND NOT EXISTS (
          SELECT 1 FROM product_packages pp
          WHERE pp.product_id = products.id
            AND pp.is_active = 1
            AND (
              pp.provider_code IS NULL OR TRIM(pp.provider_code) = '' OR
              pp.provider_sku IS NULL OR TRIM(pp.provider_sku) = ''
            )
        )
      THEN 'automatic'
      ELSE 'manual'
    END,
    instant = CASE
      WHEN slug IN (SELECT product_slug FROM product_delivery_modes WHERE mode = 'manual') THEN 0
      WHEN slug IN (SELECT product_slug FROM product_delivery_modes WHERE mode IN ('direct', 'voucher'))
        AND EXISTS (
          SELECT 1 FROM product_packages pp
          WHERE pp.product_id = products.id AND pp.is_active = 1
        )
        AND NOT EXISTS (
          SELECT 1 FROM product_packages pp
          WHERE pp.product_id = products.id
            AND pp.is_active = 1
            AND (
              pp.provider_code IS NULL OR TRIM(pp.provider_code) = '' OR
              pp.provider_sku IS NULL OR TRIM(pp.provider_sku) = ''
            )
        )
      THEN 1
      ELSE 0
    END,
    needs_server = CASE
      WHEN slug IN (SELECT product_slug FROM product_delivery_modes WHERE mode = 'voucher') THEN 0
      ELSE needs_server
    END
    WHERE slug IN (SELECT product_slug FROM product_delivery_modes)`).run();
}

export async function listProductDeliveryConfigs(includeInactive = false): Promise<ProductDeliveryConfig[]> {
  await ensureProductDeliveryTable();
  await reconcileExplicitModes();
  const result = await getD1().prepare(`SELECT
      p.slug,
      p.name,
      p.category,
      p.fulfillment_type,
      p.input_label,
      p.input_placeholder,
      p.needs_server,
      p.target_template,
      p.is_active,
      dm.mode
    FROM products p
    LEFT JOIN product_delivery_modes dm ON dm.product_slug = p.slug
    ${includeInactive ? "" : "WHERE p.is_active = 1"}
    ORDER BY p.sort_order ASC, p.name ASC`).all<{
      slug: string;
      name: string;
      category: string;
      fulfillment_type: string;
      input_label: string;
      input_placeholder: string;
      needs_server: number;
      target_template: string;
      is_active: number;
      mode: string | null;
    }>();

  return result.results.map((row) => ({
    slug: row.slug,
    name: row.name,
    category: row.category,
    mode: normalizeMode(row.mode, row.category, row.fulfillment_type),
    inputLabel: row.input_label,
    inputPlaceholder: row.input_placeholder,
    needsServer: Boolean(row.needs_server),
    targetTemplate: row.target_template,
    isActive: Boolean(row.is_active),
  }));
}

export async function saveProductDeliveryConfig(input: {
  slug: string;
  mode: ProductDeliveryMode;
  inputLabel?: string;
  inputPlaceholder?: string;
  needsServer?: boolean;
  targetTemplate?: string;
}) {
  await ensureProductDeliveryTable();
  const db = getD1();
  const product = await db.prepare("SELECT id, slug FROM products WHERE slug = ? LIMIT 1")
    .bind(input.slug)
    .first<{ id: number; slug: string }>();
  if (!product) throw new Error("Produk tidak ditemukan.");

  const readiness = await db.prepare(`SELECT
      COUNT(*) AS active_count,
      SUM(CASE WHEN provider_code IS NULL OR TRIM(provider_code) = '' OR provider_sku IS NULL OR TRIM(provider_sku) = '' THEN 1 ELSE 0 END) AS missing_count
    FROM product_packages
    WHERE product_id = ? AND is_active = 1`)
    .bind(product.id)
    .first<{ active_count: number; missing_count: number | null }>();
  const providerReady = Number(readiness?.active_count ?? 0) > 0 && Number(readiness?.missing_count ?? 0) === 0;
  const automatic = input.mode !== "manual" && providerReady;
  const isVoucher = input.mode === "voucher";
  const inputLabel = input.inputLabel?.trim() || "User ID";
  const inputPlaceholder = input.inputPlaceholder?.trim() || "Masukkan User ID";
  const targetTemplate = isVoucher ? "{{destination}}" : input.targetTemplate?.trim() || "{{destination}}";

  await db.batch([
    db.prepare(`INSERT INTO product_delivery_modes (product_slug, mode, updated_at)
      VALUES (?, ?, CURRENT_TIMESTAMP)
      ON CONFLICT(product_slug) DO UPDATE SET mode = excluded.mode, updated_at = CURRENT_TIMESTAMP`)
      .bind(input.slug, input.mode),
    db.prepare(`UPDATE products SET
      fulfillment_type = ?,
      instant = ?,
      input_label = ?,
      input_placeholder = ?,
      needs_server = ?,
      target_template = ?,
      updated_at = CURRENT_TIMESTAMP
      WHERE slug = ?`)
      .bind(
        automatic ? "automatic" : "manual",
        automatic ? 1 : 0,
        inputLabel,
        inputPlaceholder,
        isVoucher ? 0 : input.needsServer ? 1 : 0,
        targetTemplate,
        input.slug,
      ),
  ]);
}
