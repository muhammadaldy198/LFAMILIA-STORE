import { getD1 } from "@/db";
import { products as fallbackProducts, type ProductPackage, type StoreProduct } from "@/lib/store-data";

export type ManagedPackage = ProductPackage & {
  dbId: number | null;
  isActive: boolean;
  sortOrder: number;
};

export type ManagedProduct = StoreProduct & {
  dbId: number | null;
  isActive: boolean;
  sortOrder: number;
  packages: ManagedPackage[];
};

type ProductRow = {
  id: number;
  slug: string;
  name: string;
  publisher: string;
  category: "game" | "voucher";
  initials: string;
  accent: string;
  input_label: string;
  input_placeholder: string;
  needs_server: number;
  popular: number;
  instant: number;
  fulfillment_type: "automatic" | "manual";
  target_template: string;
  manual_instructions: string | null;
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
  provider_code: string | null;
  provider_sku: string | null;
  is_active: number;
  sort_order: number;
};

export function getFallbackProducts(): ManagedProduct[] {
  return fallbackProducts.map((product, productIndex) => ({
    ...product,
    dbId: null,
    isActive: true,
    sortOrder: productIndex,
    packages: product.packages.map((item, packageIndex) => ({
      ...item,
      dbId: null,
      isActive: true,
      sortOrder: packageIndex,
    })),
  }));
}

export async function readProducts(includeInactive = false): Promise<ManagedProduct[]> {
  const db = getD1();
  const productSql = includeInactive
    ? `SELECT id, slug, name, publisher, category, initials, accent, input_label, input_placeholder,
        needs_server, popular, instant, fulfillment_type, target_template, manual_instructions, is_active, sort_order
       FROM products ORDER BY sort_order ASC, name ASC`
    : `SELECT id, slug, name, publisher, category, initials, accent, input_label, input_placeholder,
        needs_server, popular, instant, fulfillment_type, target_template, manual_instructions, is_active, sort_order
       FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC`;
  const packageSql = includeInactive
    ? `SELECT id, product_id, sku, label, price, note, provider_code, provider_sku, is_active, sort_order
       FROM product_packages ORDER BY sort_order ASC, id ASC`
    : `SELECT id, product_id, sku, label, price, note, provider_code, provider_sku, is_active, sort_order
       FROM product_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC`;

  const [productResult, packageResult] = await db.batch([
    db.prepare(productSql),
    db.prepare(packageSql),
  ]);
  const packageRows = packageResult.results as PackageRow[];

  return (productResult.results as ProductRow[]).map((row) => ({
    dbId: row.id,
    slug: row.slug,
    name: row.name,
    publisher: row.publisher,
    category: row.category,
    initials: row.initials,
    accent: row.accent,
    inputLabel: row.input_label,
    inputPlaceholder: row.input_placeholder,
    needsServer: Boolean(row.needs_server),
    popular: Boolean(row.popular),
    instant: Boolean(row.instant),
    fulfillmentType: row.fulfillment_type,
    targetTemplate: row.target_template,
    manualInstructions: row.manual_instructions ?? undefined,
    isActive: Boolean(row.is_active),
    sortOrder: row.sort_order,
    packages: packageRows.filter((item) => item.product_id === row.id).map((item) => ({
      dbId: item.id,
      id: item.sku,
      label: item.label,
      price: item.price,
      note: item.note ?? undefined,
      providerCode: item.provider_code ?? undefined,
      providerSku: item.provider_sku ?? undefined,
      isActive: Boolean(item.is_active),
      sortOrder: item.sort_order,
    })),
  }));
}

type ProductWrite = Omit<ManagedProduct, "dbId" | "packages"> & {
  packages: Array<Omit<ManagedPackage, "dbId">>;
};

export async function saveProduct(input: ProductWrite, id?: number) {
  const db = getD1();
  const values = [
    input.slug,
    input.name,
    input.publisher,
    input.category,
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
    input.isActive ? 1 : 0,
    input.sortOrder,
  ];

  if (id) {
    await db.prepare(
      `UPDATE products SET slug = ?, name = ?, publisher = ?, category = ?, initials = ?, accent = ?,
       input_label = ?, input_placeholder = ?, needs_server = ?, popular = ?, instant = ?, fulfillment_type = ?,
       target_template = ?, manual_instructions = ?, is_active = ?,
       sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    ).bind(...values, id).run();
  } else {
    await db.prepare(
      `INSERT INTO products (slug, name, publisher, category, initials, accent, input_label,
       input_placeholder, needs_server, popular, instant, fulfillment_type, target_template,
       manual_instructions, is_active, sort_order)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    ).bind(...values).run();
  }

  const productRow = await db.prepare("SELECT id FROM products WHERE slug = ?").bind(input.slug).first<{ id: number }>();
  if (!productRow) throw new Error("Produk tidak ditemukan setelah disimpan.");

  const packageStatements = [
    db.prepare("DELETE FROM product_packages WHERE product_id = ?").bind(productRow.id),
    ...input.packages.map((item, index) => db.prepare(
      `INSERT INTO product_packages (product_id, sku, label, price, note, provider_code, provider_sku, is_active, sort_order)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    ).bind(productRow.id, item.id, item.label, item.price, item.note ?? null, item.providerCode ?? null, item.providerSku ?? null, item.isActive ? 1 : 0, index)),
  ];
  await db.batch(packageStatements);
  return productRow.id;
}

export async function deleteProduct(id: number) {
  const db = getD1();
  await db.prepare("DELETE FROM products WHERE id = ?").bind(id).run();
}

export async function seedFallbackProducts() {
  const db = getD1();
  const source = getFallbackProducts();
  const productStatements = source.map((item) => db.prepare(
    `INSERT INTO products (slug, name, publisher, category, initials, accent, input_label,
     input_placeholder, needs_server, popular, instant, fulfillment_type, target_template,
     manual_instructions, is_active, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON CONFLICT(slug) DO UPDATE SET name = excluded.name, publisher = excluded.publisher,
     category = excluded.category, initials = excluded.initials, accent = excluded.accent,
     input_label = excluded.input_label, input_placeholder = excluded.input_placeholder,
     needs_server = excluded.needs_server, popular = excluded.popular, instant = excluded.instant,
     fulfillment_type = excluded.fulfillment_type, target_template = excluded.target_template,
     manual_instructions = excluded.manual_instructions,
     is_active = excluded.is_active, sort_order = excluded.sort_order, updated_at = CURRENT_TIMESTAMP`,
  ).bind(
    item.slug, item.name, item.publisher, item.category, item.initials, item.accent,
    item.inputLabel, item.inputPlaceholder, item.needsServer ? 1 : 0, item.popular ? 1 : 0,
    item.instant ? 1 : 0, item.fulfillmentType, item.targetTemplate, item.manualInstructions ?? null, 1, item.sortOrder,
  ));
  await db.batch(productStatements);

  const idRows = await db.prepare("SELECT id, slug FROM products").all<{ id: number; slug: string }>();
  const idBySlug = new Map(idRows.results.map((row) => [row.slug, row.id]));
  await db.batch(source.map((item) => db.prepare("DELETE FROM product_packages WHERE product_id = ?").bind(idBySlug.get(item.slug))));

  const packageStatements = source.flatMap((product) => product.packages.map((item, index) => db.prepare(
    `INSERT INTO product_packages (product_id, sku, label, price, note, provider_code, provider_sku, is_active, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
  ).bind(idBySlug.get(product.slug), item.id, item.label, item.price, item.note ?? null, item.providerCode ?? null, item.providerSku ?? null, 1, index)));
  await db.batch(packageStatements);
  return source.length;
}
