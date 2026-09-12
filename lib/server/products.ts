import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
import { products as bundledProducts, type ProductInputField, type ProductNotice, type ProductPackage, type StoreProduct } from "@/lib/store-data";

const bundledProductBySlug = new Map(bundledProducts.map((product) => [product.slug, product]));

export type ManagedPackage = ProductPackage & {
  dbId: number | null;
  group?: string;
  isActive: boolean;
  sortOrder: number;
  supplierPrice?: number | null;
  pricingMode?: "manual" | "auto";
  marginType?: "fixed" | "percent";
  marginValue?: number;
};

export type ManagedProduct = Omit<StoreProduct, "packages" | "notices"> & {
  description?: string;
  dbId: number | null;
  packageTabsEnabled: boolean;
  packageTabs: string[];
  isActive: boolean;
  sortOrder: number;
  packages: ManagedPackage[];
  notices: ManagedNotice[];
};

export type ManagedNotice = ProductNotice & {
  id: number | null;
  isActive: boolean;
  sortOrder: number;
};

type ProductRow = {
  id: number;
  slug: string;
  name: string;
  publisher: string;
  category: string;
  image_url: string | null;
  banner_url: string | null;
  description: string | null;
  initials: string;
  accent: string;
  input_label: string;
  input_placeholder: string;
  input_fields_json: string | null;
  needs_server: number;
  popular: number;
  instant: number;
  fulfillment_type: "automatic" | "manual";
  target_template: string;
  manual_instructions: string | null;
  manual_open_time: string | null;
  manual_close_time: string | null;
  manual_timezone: string;
  package_tabs_enabled: number;
  package_tabs_json: string | null;
  is_active: number;
  sort_order: number;
};

type PackageRow = {
  id: number;
  product_id: number;
  sku: string;
  label: string;
  price: number;
  note: string | null;
  package_group: string | null;
  image_url: string | null;
  provider_code: string | null;
  provider_sku: string | null;
  supplier_price: number | null;
  pricing_mode: "manual" | "auto" | null;
  margin_type: "fixed" | "percent" | null;
  margin_value: number | null;
  is_active: number;
  sort_order: number;
};

type NoticeRow = {
  id: number;
  product_id: number;
  title: string;
  body: string;
  is_active: number;
  sort_order: number;
};

function parseInputFields(
  value: string | null,
  inputLabel: string,
  inputPlaceholder: string,
  needsServer: boolean,
): ProductInputField[] {
  if (value !== null) {
    try {
      const parsed = JSON.parse(value) as unknown;
      if (Array.isArray(parsed)) {
        return parsed
          .filter((item): item is Record<string, unknown> => Boolean(item) && typeof item === "object")
          .map((item, index) => ({
            id: typeof item.id === "string" && item.id.trim() ? item.id.trim() : `field-${index + 1}`,
            label: typeof item.label === "string" ? item.label.trim() : "",
            placeholder: typeof item.placeholder === "string" ? item.placeholder.trim() : "",
            required: item.required !== false,
          }))
          .filter((item) => item.label)
          .slice(0, 12);
      }
    } catch {
      // Legacy fallback below.
    }
  }
  return [
    { id: "account-id", label: inputLabel, placeholder: inputPlaceholder, required: true },
    ...(needsServer
      ? [{ id: "server-zone", label: "Server / Zone ID", placeholder: "Contoh: 1234", required: true }]
      : []),
  ];
}

function parsePackageTabs(value: string | null) {
  try {
    const parsed = JSON.parse(value || "[]") as unknown;
    if (!Array.isArray(parsed)) return [];
    return Array.from(new Set(parsed.filter((item): item is string => typeof item === "string").map((item) => item.trim()).filter(Boolean))).slice(0, 20);
  } catch {
    return [];
  }
}

const oneTimeCatalogRepopulationKey = "catalog_repopulation_2026_09_09";

async function applyOneTimeCatalogRepopulation() {
  const db = getD1();
  await db.prepare(`CREATE TABLE IF NOT EXISTS one_time_operations (
    operation_key TEXT PRIMARY KEY NOT NULL,
    completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`).run();

  const completed = await db.prepare(
    "SELECT completed_at FROM one_time_operations WHERE operation_key = ? LIMIT 1",
  ).bind(oneTimeCatalogRepopulationKey).first<{ completed_at: string }>();
  if (completed?.completed_at) return;

  const statements: D1PreparedStatement[] = [];
  for (const [productIndex, product] of bundledProducts.entries()) {
    statements.push(
      db.prepare(
        `INSERT OR IGNORE INTO products (
          slug, name, publisher, category, image_url, banner_url, description, initials, accent,
          input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
          fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
          manual_timezone, package_tabs_enabled, package_tabs_json, is_active, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)`,
      ).bind(
        product.slug,
        product.name,
        product.publisher,
        product.category,
        product.imageUrl ?? null,
        product.bannerUrl ?? null,
        null,
        product.initials,
        product.accent,
        product.inputLabel,
        product.inputPlaceholder,
        JSON.stringify(product.inputFields ?? []),
        product.needsServer ? 1 : 0,
        product.popular ? 1 : 0,
        product.instant ? 1 : 0,
        product.fulfillmentType,
        product.targetTemplate,
        product.manualInstructions ?? null,
        product.manualOpenTime ?? null,
        product.manualCloseTime ?? null,
        product.manualTimezone ?? "Asia/Jakarta",
        product.packageTabsEnabled ? 1 : 0,
        JSON.stringify(product.packageTabs ?? []),
        productIndex,
      ),
    );

    for (const [packageIndex, item] of product.packages.entries()) {
      statements.push(
        db.prepare(
          `INSERT OR IGNORE INTO product_packages (
            product_id, sku, label, price, note, package_group, image_url, provider_code, provider_sku,
            supplier_price, pricing_mode, margin_type, margin_value, is_active, sort_order
          )
          SELECT id, ?, ?, ?, ?, ?, NULL, ?, ?, NULL, 'manual', 'fixed', 0, 1, ?
          FROM products WHERE slug = ?`,
        ).bind(
          item.id,
          item.label,
          item.price,
          item.note ?? null,
          item.group?.trim() || null,
          item.providerCode ?? null,
          item.providerSku ?? null,
          packageIndex,
          product.slug,
        ),
      );
    }

    for (const [noticeIndex, notice] of (product.notices ?? []).entries()) {
      statements.push(
        db.prepare(
          `INSERT INTO product_notices (product_id, title, body, is_active, sort_order)
           SELECT p.id, ?, ?, ?, ?
           FROM products p
           WHERE p.slug = ?
             AND NOT EXISTS (
               SELECT 1 FROM product_notices n
               WHERE n.product_id = p.id AND n.title = ? AND n.body = ?
             )`,
        ).bind(
          notice.title,
          notice.body,
          notice.isActive === false ? 0 : 1,
          notice.sortOrder ?? noticeIndex,
          product.slug,
          notice.title,
          notice.body,
        ),
      );
    }
  }

  for (let index = 0; index < statements.length; index += 40) {
    await db.batch(statements.slice(index, index + 40));
  }

  await db.prepare(
    `INSERT INTO one_time_operations (operation_key, completed_at)
     VALUES (?, CURRENT_TIMESTAMP)
     ON CONFLICT(operation_key) DO UPDATE SET completed_at = excluded.completed_at`,
  ).bind(oneTimeCatalogRepopulationKey).run();
}

export async function readProducts(includeInactive = false): Promise<ManagedProduct[]> {
  await ensureLegacyDatabaseColumns();
  const db = getD1();
  const productSql = includeInactive
    ? `SELECT id, slug, name, publisher, category, image_url, banner_url, description, initials, accent, input_label, input_placeholder, input_fields_json,
        needs_server, popular, instant, fulfillment_type, target_template, manual_instructions,
        manual_open_time, manual_close_time, manual_timezone, package_tabs_enabled, package_tabs_json, is_active, sort_order
       FROM products ORDER BY sort_order ASC, name ASC`
    : `SELECT id, slug, name, publisher, category, image_url, banner_url, description, initials, accent, input_label, input_placeholder, input_fields_json,
        needs_server, popular, instant, fulfillment_type, target_template, manual_instructions,
        manual_open_time, manual_close_time, manual_timezone, package_tabs_enabled, package_tabs_json, is_active, sort_order
       FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC`;
  const packageSql = includeInactive
    ? `SELECT id, product_id, sku, label, price, note, package_group, image_url, provider_code, provider_sku, supplier_price, pricing_mode, margin_type, margin_value, is_active, sort_order
       FROM product_packages ORDER BY sort_order ASC, id ASC`
    : `SELECT id, product_id, sku, label, price, note, package_group, image_url, provider_code, provider_sku, supplier_price, pricing_mode, margin_type, margin_value, is_active, sort_order
       FROM product_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC`;
  const noticeSql = includeInactive
    ? `SELECT id, product_id, title, body, is_active, sort_order FROM product_notices ORDER BY sort_order ASC, id ASC`
    : `SELECT id, product_id, title, body, is_active, sort_order FROM product_notices WHERE is_active = 1 ORDER BY sort_order ASC, id ASC`;

  const [productResult, packageResult, noticeResult] = await db.batch([
    db.prepare(productSql),
    db.prepare(packageSql),
    db.prepare(noticeSql),
  ]);
  const packageRows = packageResult.results as PackageRow[];
  const noticeRows = noticeResult.results as NoticeRow[];

  return (productResult.results as ProductRow[]).map((row) => {
    const bundled = bundledProductBySlug.get(row.slug);
    return {
    dbId: row.id,
    slug: row.slug,
    name: row.name,
    publisher: row.publisher,
    category: row.category,
    imageUrl: row.image_url ?? bundled?.imageUrl,
    bannerUrl: row.banner_url ?? row.image_url ?? bundled?.bannerUrl ?? bundled?.imageUrl,
    description: row.description ?? undefined,
    initials: row.initials,
    accent: row.accent,
    inputLabel: row.input_label,
    inputPlaceholder: row.input_placeholder,
    inputFields: parseInputFields(row.input_fields_json, row.input_label, row.input_placeholder, Boolean(row.needs_server)),
    needsServer: Boolean(row.needs_server),
    popular: Boolean(row.popular),
    instant: Boolean(row.instant),
    fulfillmentType: row.fulfillment_type,
    targetTemplate: row.target_template,
    manualInstructions: row.manual_instructions ?? undefined,
    manualOpenTime: row.manual_open_time ?? undefined,
    manualCloseTime: row.manual_close_time ?? undefined,
    manualTimezone: row.manual_timezone || "Asia/Jakarta",
    packageTabsEnabled: Boolean(row.package_tabs_enabled),
    packageTabs: parsePackageTabs(row.package_tabs_json),
    isActive: Boolean(row.is_active),
    sortOrder: row.sort_order,
    notices: noticeRows.filter((item) => item.product_id === row.id).map((item) => ({
      id: item.id,
      title: item.title,
      body: item.body,
      isActive: Boolean(item.is_active),
      sortOrder: item.sort_order,
    })),
    packages: packageRows.filter((item) => item.product_id === row.id).map((item) => ({
      dbId: item.id,
      id: item.sku,
      label: item.label,
      price: item.price,
      note: item.note ?? undefined,
      group: item.package_group ?? undefined,
      imageUrl: item.image_url ?? undefined,
      providerCode: item.provider_code ?? undefined,
      providerSku: item.provider_sku ?? undefined,
      supplierPrice: item.supplier_price,
      pricingMode: item.pricing_mode ?? "auto",
      marginType: item.margin_type ?? "fixed",
      marginValue: item.margin_value ?? 0,
      isActive: Boolean(item.is_active),
      sortOrder: item.sort_order,
    })),
    };
  });
}

type ProductWrite = Omit<ManagedProduct, "dbId" | "packages" | "notices"> & {
  packages: Array<Omit<ManagedPackage, "dbId">>;
  notices: Array<Omit<ManagedNotice, "id">>;
};

export async function saveProduct(input: ProductWrite, id?: number) {
  await ensureLegacyDatabaseColumns();
  const db = getD1();
  const packageTabs = Array.from(new Set((input.packageTabs ?? []).map((item) => item.trim()).filter(Boolean))).slice(0, 20);
  const values = [
    input.slug,
    input.name,
    input.publisher,
    input.category,
    input.imageUrl ?? null,
    input.bannerUrl ?? null,
    input.description?.trim() || null,
    input.initials,
    input.accent,
    input.inputLabel,
    input.inputPlaceholder,
    input.needsServer ? 1 : 0,
    input.popular ? 1 : 0,
    input.instant ? 1 : 0,
    input.fulfillmentType,
    input.targetTemplate,
    input.manualInstructions ?? null,
    input.manualOpenTime ?? null,
    input.manualCloseTime ?? null,
    input.manualTimezone ?? "Asia/Jakarta",
    input.packageTabsEnabled ? 1 : 0,
    JSON.stringify(packageTabs),
    input.isActive ? 1 : 0,
    input.sortOrder,
  ];

  if (id) {
    await db.prepare(
      `UPDATE products SET slug = ?, name = ?, publisher = ?, category = ?, image_url = ?, banner_url = ?, description = ?, initials = ?, accent = ?,
       input_label = ?, input_placeholder = ?, needs_server = ?, popular = ?, instant = ?, fulfillment_type = ?,
       target_template = ?, manual_instructions = ?, manual_open_time = ?, manual_close_time = ?, manual_timezone = ?,
       package_tabs_enabled = ?, package_tabs_json = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    ).bind(...values, id).run();
  } else {
    await db.prepare(
      `INSERT INTO products (slug, name, publisher, category, image_url, banner_url, description, initials, accent, input_label,
       input_placeholder, needs_server, popular, instant, fulfillment_type, target_template,
       manual_instructions, manual_open_time, manual_close_time, manual_timezone, package_tabs_enabled, package_tabs_json, is_active, sort_order)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    ).bind(...values).run();
  }

  const productRow = await db.prepare("SELECT id FROM products WHERE slug = ?").bind(input.slug).first<{ id: number }>();
  if (!productRow) throw new Error("Produk tidak ditemukan setelah disimpan.");
  await db.prepare("UPDATE products SET input_fields_json = ? WHERE id = ?")
    .bind(JSON.stringify(input.inputFields ?? []), productRow.id)
    .run();

  const packageStatements = input.packages.map((item, index) => db.prepare(
    `INSERT INTO product_packages (product_id, sku, label, price, note, package_group, image_url, provider_code, provider_sku, supplier_price, pricing_mode, margin_type, margin_value, is_active, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON CONFLICT(sku) DO UPDATE SET
       label = excluded.label, price = excluded.price, note = excluded.note, package_group = excluded.package_group,
       image_url = excluded.image_url, provider_code = excluded.provider_code, provider_sku = excluded.provider_sku,
       supplier_price = excluded.supplier_price, pricing_mode = excluded.pricing_mode, margin_type = excluded.margin_type,
       margin_value = excluded.margin_value, is_active = excluded.is_active, sort_order = excluded.sort_order`,
  ).bind(productRow.id, item.id, item.label, item.price, item.note ?? null, item.group?.trim() || null, item.imageUrl ?? null, item.providerCode ?? null, item.providerSku ?? null, item.supplierPrice ?? null, item.pricingMode ?? "auto", item.marginType ?? "fixed", item.marginValue ?? 0, item.isActive ? 1 : 0, index));
  if (input.packages.length) {
    const placeholders = input.packages.map(() => "?").join(", ");
    packageStatements.push(db.prepare(`DELETE FROM product_packages WHERE product_id = ? AND sku NOT IN (${placeholders})`).bind(productRow.id, ...input.packages.map((item) => item.id)));
  } else {
    packageStatements.push(db.prepare("DELETE FROM product_packages WHERE product_id = ?").bind(productRow.id));
  }
  await db.batch(packageStatements);
  const noticeStatements = [
    db.prepare("DELETE FROM product_notices WHERE product_id = ?").bind(productRow.id),
    ...input.notices.map((item, index) => db.prepare(
      `INSERT INTO product_notices (product_id, title, body, is_active, sort_order) VALUES (?, ?, ?, ?, ?)`,
    ).bind(productRow.id, item.title, item.body, item.isActive ? 1 : 0, index)),
  ];
  await db.batch(noticeStatements);
  return productRow.id;
}

export async function saveProductContent(input: {
  id: number;
  imageUrl?: string;
  bannerUrl?: string;
  manualInstructions?: string;
  manualOpenTime?: string;
  manualCloseTime?: string;
  manualTimezone?: string;
  notices: Array<Omit<ManagedNotice, "id">>;
}) {
  const db = getD1();
  const exists = await db.prepare("SELECT id FROM products WHERE id = ? LIMIT 1").bind(input.id).first<{ id: number }>();
  if (!exists) throw new Error("Produk tidak ditemukan.");
  await db.prepare(
    `UPDATE products SET image_url = ?, banner_url = ?, manual_instructions = ?, manual_open_time = ?,
     manual_close_time = ?, manual_timezone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
  ).bind(
    input.imageUrl || null,
    input.bannerUrl || null,
    input.manualInstructions || null,
    input.manualOpenTime || null,
    input.manualCloseTime || null,
    input.manualTimezone || "Asia/Jakarta",
    input.id,
  ).run();
  await db.batch([
    db.prepare("DELETE FROM product_notices WHERE product_id = ?").bind(input.id),
    ...input.notices.map((item, index) => db.prepare(
      `INSERT INTO product_notices (product_id, title, body, is_active, sort_order) VALUES (?, ?, ?, ?, ?)`,
    ).bind(input.id, item.title, item.body, item.isActive ? 1 : 0, index)),
  ]);
}

export async function updateProductPackageProvider(input: {
  packageId: number;
  providerCode: string | null;
  providerSku: string | null;
  pricingMode: "manual" | "auto";
  marginType: "fixed" | "percent";
  marginValue: number;
}) {
  const db = getD1();
  const result = await db.prepare(
    `UPDATE product_packages
     SET provider_code = ?,
         provider_sku = ?,
         pricing_mode = ?,
         margin_type = ?,
         margin_value = ?
     WHERE id = ?`,
  ).bind(
    input.providerCode,
    input.providerSku,
    input.pricingMode,
    input.marginType,
    input.marginValue,
    input.packageId,
  ).run();

  if (!result.meta.changes) throw new Error("Nominal tidak ditemukan.");
}

export async function updateProductPackageStatus(packageId: number, isActive: boolean) {
  const db = getD1();
  const result = await db.prepare(
    "UPDATE product_packages SET is_active = ? WHERE id = ?",
  ).bind(isActive ? 1 : 0, packageId).run();

  if (!result.meta.changes) throw new Error("Nominal tidak ditemukan.");
}

export async function deleteProduct(id: number) {
  const db = getD1();
  await db.prepare("DELETE FROM products WHERE id = ?").bind(id).run();
}
