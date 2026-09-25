import { getD1 } from "@/db";
import type { MemberTier } from "@/lib/server/member-tiers";
import { deletePromotionMutation, saveDiscountVoucherMutation, saveFlashSaleMutation } from "@/lib/server/promotion-mutations.mjs";

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
  used_count: number; reserved_count: number; starts_at: string; ends_at: string; is_active: number;
};

type FlashRow = {
  id: number; product_slug: string; product_name: string; package_sku: string; package_label: string;
  image_url: string | null; accent: string; initials: string; base_price: number; sale_price: number;
  badge: string; starts_at: string; ends_at: string; stock_limit: number | null; sold_count: number; reserved_count: number; is_active: number;
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
  const where = includeInactive ? "" : "WHERE is_active = 1 AND starts_at <= ? AND ends_at >= ? AND (usage_limit IS NULL OR used_count + reserved_count < usage_limit)";
  const statement = getD1().prepare(`SELECT * FROM discount_vouchers ${where} ORDER BY created_at DESC`);
  const result = includeInactive ? await statement.all<VoucherRow>() : await statement.bind(now, now).all<VoucherRow>();
  return result.results.map(voucherFromRow);
}

export async function listFlashSales(includeInactive = false) {
  const now = new Date().toISOString();
  const where = includeInactive ? "" : "WHERE fs.is_active = 1 AND fs.starts_at <= ? AND fs.ends_at >= ? AND (fs.stock_limit IS NULL OR fs.sold_count + fs.reserved_count < fs.stock_limit)";
  const statement = getD1().prepare(
    `SELECT fs.id, fs.product_slug, p.name AS product_name, fs.package_sku, pp.label AS package_label,
      p.image_url, p.accent, p.initials, pp.price AS base_price, fs.sale_price, fs.badge,
      fs.starts_at, fs.ends_at, fs.stock_limit, fs.sold_count, fs.reserved_count, fs.is_active
     FROM flash_sales fs
     JOIN products p ON p.slug = fs.product_slug
     JOIN product_packages pp ON pp.product_id = p.id AND pp.sku = fs.package_sku
     ${where} ORDER BY fs.ends_at ASC`,
  );
  const result = includeInactive ? await statement.all<FlashRow>() : await statement.bind(now, now).all<FlashRow>();
  return result.results.map(flashFromRow);
}

export async function saveDiscountVoucher(input: Omit<DiscountVoucher, "id" | "usedCount"> & { usedCount?: number }, id?: number) {
  return saveDiscountVoucherMutation(getD1(), input, id);
}

export async function saveFlashSale(input: Omit<FlashSale, "id" | "productName" | "packageLabel" | "imageUrl" | "accent" | "initials" | "basePrice" | "soldCount"> & { soldCount?: number }, id?: number) {
  return saveFlashSaleMutation(getD1(), input, id);
}

export async function deletePromotion(kind: "voucher" | "flash", id: number) {
  return deletePromotionMutation(getD1(), kind, id);
}

export type PromotionQuote = {
  basePrice: number;
  sellingPrice: number;
  discountAmount: number;
  finalPrice: number;
  voucherCode: string | null;
  flashSaleId: number | null;
  flashSaleEndsAt: string | null;
  memberTier: MemberTier | null;
  memberDiscountPercent: number;
  memberDiscountAmount: number;
  discountSource: "voucher" | "member" | null;
};

export class PromotionQuoteError extends Error {}

export async function quotePromotion(
  productSlug: string,
  packageSku: string,
  unitPrice: number,
  voucherCode?: string | null,
  member?: { tier?: MemberTier | null; discountPercent?: number } | null,
  quantity = 1,
): Promise<PromotionQuote> {
  const db = getD1();
  const now = new Date().toISOString();
  const flash = await db.prepare(
    `SELECT id, sale_price, ends_at FROM flash_sales
     WHERE product_slug = ? AND package_sku = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
       AND (stock_limit IS NULL OR sold_count + reserved_count < stock_limit) LIMIT 1`,
  ).bind(productSlug, packageSku, now, now).first<{ id: number; sale_price: number; ends_at: string }>();
  const normalizedQuantity = Math.max(1, Math.min(5, Math.trunc(Number(quantity) || 1)));
  const basePrice = unitPrice * normalizedQuantity;
  const sellingPrice = (flash && flash.sale_price < unitPrice ? flash.sale_price : unitPrice) * normalizedQuantity;
  let voucherDiscountAmount = 0;
  let voucher: VoucherRow | null = null;

  if (voucherCode?.trim()) {
    const code = voucherCode.trim().toUpperCase();
    voucher = await db.prepare(
      `SELECT * FROM discount_vouchers WHERE code = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
       AND (usage_limit IS NULL OR used_count + reserved_count < usage_limit) LIMIT 1`,
    ).bind(code, now, now).first<VoucherRow>();
    if (!voucher) throw new PromotionQuoteError("Kode voucher tidak aktif, sudah habis, atau tidak ditemukan.");
    if (sellingPrice < voucher.min_purchase) throw new PromotionQuoteError(`Minimum transaksi voucher ini Rp${voucher.min_purchase.toLocaleString("id-ID")}.`);
    voucherDiscountAmount = voucher.discount_type === "fixed"
      ? voucher.discount_value
      : Math.floor(sellingPrice * voucher.discount_value / 100);
    if (voucher.max_discount !== null) voucherDiscountAmount = Math.min(voucherDiscountAmount, voucher.max_discount);
    voucherDiscountAmount = Math.min(voucherDiscountAmount, Math.max(0, sellingPrice - 1));
  }

  const memberDiscountPercent = Math.max(0, Math.min(100, Number(member?.discountPercent ?? 0)));
  const memberDiscountAmount = Math.min(
    Math.floor(sellingPrice * memberDiscountPercent / 100),
    Math.max(0, sellingPrice - 1),
  );
  const useMemberDiscount = memberDiscountAmount > 0 && memberDiscountAmount >= voucherDiscountAmount;
  const discountAmount = useMemberDiscount ? memberDiscountAmount : voucherDiscountAmount;
  const discountSource = discountAmount > 0 ? (useMemberDiscount ? "member" : "voucher") : null;
  const appliedCode = discountSource === "voucher" ? voucher?.code ?? null : null;

  return {
    basePrice,
    sellingPrice,
    discountAmount,
    finalPrice: Math.max(1, sellingPrice - discountAmount),
    voucherCode: appliedCode,
    flashSaleId: flash?.id ?? null,
    flashSaleEndsAt: flash?.ends_at ?? null,
    memberTier: member?.tier ?? null,
    memberDiscountPercent,
    memberDiscountAmount,
    discountSource,
  };
}

export async function reserveExternalPromotion(input: { orderId: string; voucherCode: string | null; flashSaleId: number | null; expiresAt: string }) {
  if (!input.voucherCode && !input.flashSaleId) return;
  await getD1().prepare(`INSERT INTO promotion_reservations (order_id, voucher_code, flash_sale_id, status, expires_at) VALUES (?, ?, ?, 'reserved', ?)`).bind(input.orderId, input.voucherCode, input.flashSaleId, input.expiresAt).run();
}

export async function updateExternalPromotionExpiry(orderId: string, expiresAt: string | null) {
  if (!expiresAt) return;
  await getD1().prepare(`UPDATE promotion_reservations SET expires_at = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status = 'reserved'`).bind(expiresAt, orderId).run();
}

export async function releaseExternalPromotion(orderId: string) {
  await getD1().prepare(`UPDATE promotion_reservations SET status = 'released', updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status = 'reserved'`).bind(orderId).run();
}

export async function releaseExpiredExternalPromotions() {
  const db = getD1();
  // Heal the narrow window where an order reached paid but reservation
  // consumption failed or was delayed. The existing reservation trigger moves
  // reserved_count -> used_count exactly once.
  await db.prepare(`
    UPDATE promotion_reservations
    SET status = 'consumed', updated_at = CURRENT_TIMESTAMP
    WHERE status = 'reserved'
      AND EXISTS (
        SELECT 1
        FROM orders
        WHERE orders.id = promotion_reservations.order_id
          AND orders.payment_status = 'paid'
      )
  `).run();

  // Never release capacity while its order is pending or already paid. Payment
  // reconciliation/expiry owns pending orders; paid reservations are consumed
  // above. Only terminal non-paid/orphaned reservations may be returned.
  await db.prepare(`
    UPDATE promotion_reservations
    SET status = 'released', updated_at = CURRENT_TIMESTAMP
    WHERE status = 'reserved'
      AND datetime(expires_at) <= datetime('now')
      AND NOT EXISTS (
        SELECT 1
        FROM orders
        WHERE orders.id = promotion_reservations.order_id
          AND orders.payment_status IN ('pending', 'paid')
      )
  `).run();
}

export async function consumeOrderPromotion(voucherCode: string | null, flashSaleId: number | null, orderId?: string) {
  const db = getD1();
  if (orderId) {
    const reserved = await db.prepare(
      `UPDATE promotion_reservations
       SET status = 'consumed', updated_at = CURRENT_TIMESTAMP
       WHERE order_id = ? AND status = 'reserved'`,
    ).bind(orderId).run();
    if (Number(reserved.meta.changes ?? 0) > 0) return;

    const existingReservation = await db.prepare(
      `SELECT status, voucher_code, flash_sale_id
       FROM promotion_reservations WHERE order_id = ? LIMIT 1`,
    ).bind(orderId).first<{
      status: string;
      voucher_code: string | null;
      flash_sale_id: number | null;
    }>();

    if (existingReservation?.status === "consumed") return;

    if (existingReservation?.status === "released") {
      const reclaimed = await db.prepare(
        `UPDATE promotion_reservations
         SET status = 'consumed', updated_at = CURRENT_TIMESTAMP
         WHERE order_id = ? AND status = 'released'`,
      ).bind(orderId).run();
      if (Number(reclaimed.meta.changes ?? 0) > 0) {
        const statements = [];
        if (existingReservation.voucher_code) {
          statements.push(
            db.prepare(
              "UPDATE discount_vouchers SET used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP WHERE code = ?",
            ).bind(existingReservation.voucher_code),
          );
        }
        if (existingReservation.flash_sale_id) {
          statements.push(
            db.prepare(
              "UPDATE flash_sales SET sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            ).bind(existingReservation.flash_sale_id),
          );
        }
        if (statements.length) await db.batch(statements);
      }
      return;
    }

    if (existingReservation) return;
  }

  const statements = [];
  if (voucherCode) statements.push(db.prepare("UPDATE discount_vouchers SET used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP WHERE code = ?").bind(voucherCode));
  if (flashSaleId) statements.push(db.prepare("UPDATE flash_sales SET sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?").bind(flashSaleId));
  if (statements.length) await db.batch(statements);
}
