import { getD1 } from "@/db";

export type DiscountVoucher = {
  id: number;
  code: string;
  name: string;
  description: string;
  discountType: "fixed" | "percentage";
  discountValue: number;
  minPurchase: number;
  maxDiscount: number | null;
  usageLimit: number | null;
  usedCount: number;
  startsAt: string;
  endsAt: string;
  isActive: boolean;
};

export type FlashSale = {
  id: number;
  productSlug: string;
  productName: string;
  packageSku: string;
  packageLabel: string;
  imageUrl: string | null;
  accent: string;
  initials: string;
  basePrice: number;
  salePrice: number;
  badge: string;
  startsAt: string;
  endsAt: string;
  stockLimit: number | null;
  soldCount: number;
  isActive: boolean;
};

type VoucherRow = {
  id: number; code: string; name: string; description: string; discount_type: "fixed" | "percentage";
  discount_value: number; min_purchase: number; max_discount: number | null; usage_limit: number | null;
  used_count: number; starts_at: string; ends_at: string; is_active: number;
};

type FlashRow = {
  id: number; product_slug: string; product_name: string; package_sku: string; package_label: string;
  image_url: string | null; accent: string; initials: string; base_price: number; sale_price: number;
  badge: string; starts_at: string; ends_at: string; stock_limit: number | null; sold_count: number; is_active: number;
};

function voucherFromRow(row: VoucherRow): DiscountVoucher {
  return {
    id: row.id, code: row.code, name: row.name, description: row.description,
    discountType: row.discount_type, discountValue: row.discount_value, minPurchase: row.min_purchase,
    maxDiscount: row.max_discount, usageLimit: row.usage_limit, usedCount: row.used_count,
    startsAt: row.starts_at, endsAt: row.ends_at, isActive: Boolean(row.is_active),
  };
}

function flashFromRow(row: FlashRow): FlashSale {
  return {
    id: row.id, productSlug: row.product_slug, productName: row.product_name,
    packageSku: row.package_sku, packageLabel: row.package_label, imageUrl: row.image_url,
    accent: row.accent, initials: row.initials, basePrice: row.base_price, salePrice: row.sale_price,
    badge: row.badge, startsAt: row.starts_at, endsAt: row.ends_at,
    stockLimit: row.stock_limit, soldCount: row.sold_count, isActive: Boolean(row.is_active),
  };
}

export async function listDiscountVouchers(includeInactive = false) {
  const now = new Date().toISOString();
  const where = includeInactive ? "" : "WHERE is_active = 1 AND starts_at <= ? AND ends_at >= ? AND (usage_limit IS NULL OR used_count < usage_limit)";
  const statement = getD1().prepare(`SELECT * FROM discount_vouchers ${where} ORDER BY created_at DESC`);
  const result = includeInactive ? await statement.all<VoucherRow>() : await statement.bind(now, now).all<VoucherRow>();
  return result.results.map(voucherFromRow);
}

export async function listFlashSales(includeInactive = false) {
  const now = new Date().toISOString();
  const where = includeInactive ? "" : "WHERE fs.is_active = 1 AND fs.starts_at <= ? AND fs.ends_at >= ? AND (fs.stock_limit IS NULL OR fs.sold_count < fs.stock_limit)";
  const statement = getD1().prepare(
    `SELECT fs.id, fs.product_slug, p.name AS product_name, fs.package_sku, pp.label AS package_label,
      p.image_url, p.accent, p.initials, pp.price AS base_price, fs.sale_price, fs.badge,
      fs.starts_at, fs.ends_at, fs.stock_limit, fs.sold_count, fs.is_active
     FROM flash_sales fs
     JOIN products p ON p.slug = fs.product_slug
     JOIN product_packages pp ON pp.product_id = p.id AND pp.sku = fs.package_sku
     ${where} ORDER BY fs.ends_at ASC`,
  );
  const result = includeInactive ? await statement.all<FlashRow>() : await statement.bind(now, now).all<FlashRow>();
  return result.results.map(flashFromRow);
}

export async function saveDiscountVoucher(input: Omit<DiscountVoucher, "id" | "usedCount"> & { usedCount?: number }, id?: number) {
  const db = getD1();
  const values = [input.code.toUpperCase(), input.name, input.description, input.discountType, input.discountValue, input.minPurchase, input.maxDiscount, input.usageLimit, input.startsAt, input.endsAt, input.isActive ? 1 : 0];
  if (id) {
    await db.prepare(`UPDATE discount_vouchers SET code = ?, name = ?, description = ?, discount_type = ?, discount_value = ?, min_purchase = ?, max_discount = ?, usage_limit = ?, starts_at = ?, ends_at = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`).bind(...values, id).run();
    return id;
  }
  const row = await db.prepare(`INSERT INTO discount_vouchers (code, name, description, discount_type, discount_value, min_purchase, max_discount, usage_limit, starts_at, ends_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id`).bind(...values).first<{ id: number }>();
  if (!row) throw new Error("Voucher diskon gagal disimpan.");
  return row.id;
}

export async function saveFlashSale(input: Omit<FlashSale, "id" | "productName" | "packageLabel" | "imageUrl" | "accent" | "initials" | "basePrice" | "soldCount"> & { soldCount?: number }, id?: number) {
  const db = getD1();
  const packageRow = await db.prepare(`SELECT pp.price FROM products p JOIN product_packages pp ON pp.product_id = p.id WHERE p.slug = ? AND pp.sku = ?`).bind(input.productSlug, input.packageSku).first<{ price: number }>();
  if (!packageRow) throw new Error("Produk atau nominal flash sale tidak ditemukan.");
  if (input.salePrice >= packageRow.price) throw new Error("Harga flash sale harus lebih rendah dari harga normal.");
  const values = [input.productSlug, input.packageSku, input.salePrice, input.badge, input.startsAt, input.endsAt, input.stockLimit, input.isActive ? 1 : 0];
  if (id) {
    await db.prepare(`UPDATE flash_sales SET product_slug = ?, package_sku = ?, sale_price = ?, badge = ?, starts_at = ?, ends_at = ?, stock_limit = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`).bind(...values, id).run();
    return id;
  }
  const row = await db.prepare(`INSERT INTO flash_sales (product_slug, package_sku, sale_price, badge, starts_at, ends_at, stock_limit, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id`).bind(...values).first<{ id: number }>();
  if (!row) throw new Error("Flash sale gagal disimpan.");
  return row.id;
}

export async function deletePromotion(kind: "voucher" | "flash", id: number) {
  await getD1().prepare(`DELETE FROM ${kind === "voucher" ? "discount_vouchers" : "flash_sales"} WHERE id = ?`).bind(id).run();
}

export type PromotionQuote = {
  basePrice: number;
  sellingPrice: number;
  discountAmount: number;
  finalPrice: number;
  voucherCode: string | null;
  flashSaleId: number | null;
  flashSaleEndsAt: string | null;
};

export async function quotePromotion(productSlug: string, packageSku: string, basePrice: number, voucherCode?: string | null): Promise<PromotionQuote> {
  const db = getD1();
  const now = new Date().toISOString();
  const flash = await db.prepare(
    `SELECT id, sale_price, ends_at FROM flash_sales
     WHERE product_slug = ? AND package_sku = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
       AND (stock_limit IS NULL OR sold_count < stock_limit) LIMIT 1`,
  ).bind(productSlug, packageSku, now, now).first<{ id: number; sale_price: number; ends_at: string }>();
  const sellingPrice = flash && flash.sale_price < basePrice ? flash.sale_price : basePrice;
  let discountAmount = 0;
  let appliedCode: string | null = null;

  if (voucherCode?.trim()) {
    const code = voucherCode.trim().toUpperCase();
    const voucher = await db.prepare(
      `SELECT * FROM discount_vouchers WHERE code = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
       AND (usage_limit IS NULL OR used_count < usage_limit) LIMIT 1`,
    ).bind(code, now, now).first<VoucherRow>();
    if (!voucher) throw new Error("Kode voucher tidak aktif, sudah habis, atau tidak ditemukan.");
    if (sellingPrice < voucher.min_purchase) throw new Error(`Minimum transaksi voucher ini Rp${voucher.min_purchase.toLocaleString("id-ID")}.`);
    discountAmount = voucher.discount_type === "fixed"
      ? voucher.discount_value
      : Math.floor(sellingPrice * voucher.discount_value / 100);
    if (voucher.max_discount !== null) discountAmount = Math.min(discountAmount, voucher.max_discount);
    discountAmount = Math.min(discountAmount, Math.max(0, sellingPrice - 1));
    appliedCode = voucher.code;
  }

  return {
    basePrice,
    sellingPrice,
    discountAmount,
    finalPrice: Math.max(1, sellingPrice - discountAmount),
    voucherCode: appliedCode,
    flashSaleId: flash?.id ?? null,
    flashSaleEndsAt: flash?.ends_at ?? null,
  };
}

export async function consumeOrderPromotion(voucherCode: string | null, flashSaleId: number | null) {
  const statements = [];
  const db = getD1();
  if (voucherCode) statements.push(db.prepare("UPDATE discount_vouchers SET used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP WHERE code = ?").bind(voucherCode));
  if (flashSaleId) statements.push(db.prepare("UPDATE flash_sales SET sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?").bind(flashSaleId));
  if (statements.length) await db.batch(statements);
}
