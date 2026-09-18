import { getD1 } from "@/db";
import { normalizeWhatsappPhone } from "@/lib/phone";

export type ProductReview = {
  id: number;
  productSlug: string;
  rating: number;
  title: string | null;
  body: string;
  customerName: string;
  isVerifiedPurchase: boolean;
  isVisible: boolean;
  createdAt: string;
  updatedAt: string;
};

type ReviewRow = {
  id: number;
  product_slug: string;
  rating: number;
  title: string | null;
  body: string;
  customer_name: string;
  is_verified_purchase: number;
  is_visible: number;
  created_at: string;
  updated_at: string;
};

const REVIEW_SELECT = `
  SELECT r.id, r.product_slug, r.rating, r.title, r.body,
    COALESCE(NULLIF(TRIM(r.reviewer_name), ''), NULLIF(TRIM(u.name), ''), 'Pelanggan') AS customer_name,
    r.is_verified_purchase, r.is_visible, r.created_at, r.updated_at
  FROM product_reviews r
  LEFT JOIN customer_users u ON u.id = r.customer_id
`;

function reviewFromRow(row: ReviewRow): ProductReview {
  return {
    id: row.id,
    productSlug: row.product_slug,
    rating: row.rating,
    title: row.title,
    body: row.body,
    customerName: publicName(row.customer_name),
    isVerifiedPurchase: Boolean(row.is_verified_purchase),
    isVisible: Boolean(row.is_visible),
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

export async function listProductReviews(productSlug: string) {
  const result = await getD1().prepare(
    `${REVIEW_SELECT}
     WHERE r.product_slug = ? AND r.is_visible = 1
     ORDER BY r.created_at DESC LIMIT 100`,
  ).bind(productSlug).all<ReviewRow>();
  return result.results.map(reviewFromRow);
}

export async function listAllReviews() {
  const result = await getD1().prepare(
    `${REVIEW_SELECT}
     ORDER BY r.created_at DESC LIMIT 300`,
  ).all<ReviewRow>();
  return result.results.map(reviewFromRow);
}

export async function listFeaturedReviews(limit = 6) {
  const safeLimit = Math.min(Math.max(Math.trunc(limit), 1), 12);
  const result = await getD1().prepare(
    `${REVIEW_SELECT}
     WHERE r.is_visible = 1
     ORDER BY r.created_at DESC LIMIT ?`,
  ).bind(safeLimit).all<ReviewRow>();
  return result.results.map(reviewFromRow);
}

export async function readReviewSummaries() {
  const result = await getD1().prepare(
    `SELECT product_slug, ROUND(AVG(rating), 1) AS rating_average, COUNT(*) AS rating_count
     FROM product_reviews WHERE is_visible = 1 GROUP BY product_slug`,
  ).all<{ product_slug: string; rating_average: number; rating_count: number }>();
  return new Map(result.results.map((row) => [row.product_slug, { ratingAverage: Number(row.rating_average || 0), ratingCount: Number(row.rating_count || 0) }]));
}

export async function saveProductReview(input: {
  customerId: string;
  productSlug: string;
  rating: number;
  title?: string;
  body: string;
}) {
  const db = getD1();
  const product = await db.prepare("SELECT slug FROM products WHERE slug = ? AND is_active = 1 LIMIT 1")
    .bind(input.productSlug)
    .first<{ slug: string }>();
  if (!product) throw new Error("Produk tidak ditemukan.");

  const existing = await db.prepare(
    "SELECT id FROM product_reviews WHERE customer_id = ? AND product_slug = ? LIMIT 1",
  ).bind(input.customerId, input.productSlug).first<{ id: number }>();

  const customer = await db.prepare("SELECT name FROM customer_users WHERE id = ? LIMIT 1")
    .bind(input.customerId)
    .first<{ name: string }>();
  const reviewerName = customer?.name?.trim() || "Pelanggan";

  if (existing) {
    await db.prepare(
      `UPDATE product_reviews
       SET rating = ?, title = ?, body = ?, reviewer_name = ?, is_verified_purchase = 1,
         is_visible = 1, updated_at = CURRENT_TIMESTAMP
       WHERE id = ?`,
    ).bind(input.rating, input.title || null, input.body, reviewerName, existing.id).run();
    return;
  }

  const purchase = await db.prepare(
    `SELECT o.id
     FROM orders o
     WHERE o.customer_id = ? AND o.product_slug = ? AND o.payment_status = 'paid'
       AND NOT EXISTS (SELECT 1 FROM product_reviews r WHERE r.order_id = o.id)
     ORDER BY o.created_at DESC LIMIT 1`,
  ).bind(input.customerId, input.productSlug).first<{ id: string }>();
  if (!purchase) throw new Error("Ulasan tersedia setelah kamu menyelesaikan pembelian produk ini.");

  await db.prepare(
    `INSERT INTO product_reviews
      (customer_id, order_id, reviewer_name, product_slug, rating, title, body, is_verified_purchase, is_visible)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)`,
  ).bind(input.customerId, purchase.id, reviewerName, input.productSlug, input.rating, input.title || null, input.body).run();
}

export async function saveGuestProductReview(input: {
  referenceId: string;
  phone: string;
  productSlug: string;
  rating: number;
  title?: string;
  body: string;
}) {
  const db = getD1();
  const cleanReference = input.referenceId.trim().toUpperCase();
  const token = (cleanReference.split("-").at(-1) || cleanReference).replace(/^LF/, "");
  if (!token) throw new Error("Nomor invoice tidak valid.");

  const order = await db.prepare(
    `SELECT id, reference_id, product_slug, buyer_name, buyer_phone
     FROM orders
     WHERE product_slug = ? AND payment_status = 'paid'
       AND (UPPER(reference_id) = ? OR UPPER(reference_id) LIKE ?)
     ORDER BY created_at DESC LIMIT 1`,
  ).bind(input.productSlug, cleanReference, `%-${token}`).first<{
    id: string;
    reference_id: string;
    product_slug: string;
    buyer_name: string;
    buyer_phone: string;
  }>();
  if (!order) throw new Error("Invoice lunas untuk produk ini tidak ditemukan.");

  let orderPhone: string;
  try {
    orderPhone = normalizeWhatsappPhone(order.buyer_phone || "");
  } catch {
    throw new Error("Pesanan ini tidak memiliki nomor WhatsApp yang dapat diverifikasi.");
  }
  const suppliedPhone = normalizeWhatsappPhone(input.phone);
  if (orderPhone !== suppliedPhone) throw new Error("Nomor WhatsApp tidak cocok dengan invoice.");

  const existing = await db.prepare("SELECT id FROM product_reviews WHERE order_id = ? LIMIT 1")
    .bind(order.id)
    .first<{ id: number }>();
  if (existing) throw new Error("Invoice ini sudah pernah digunakan untuk memberikan ulasan.");

  await db.prepare(
    `INSERT INTO product_reviews
      (customer_id, order_id, reviewer_name, product_slug, rating, title, body, is_verified_purchase, is_visible)
     VALUES (NULL, ?, ?, ?, ?, ?, ?, 1, 1)`,
  ).bind(
    order.id,
    order.buyer_name?.trim() || "Pelanggan",
    input.productSlug,
    input.rating,
    input.title || null,
    input.body,
  ).run();
}

export async function moderateProductReview(id: number, isVisible: boolean) {
  await getD1().prepare("UPDATE product_reviews SET is_visible = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
    .bind(isVisible ? 1 : 0, id).run();
}

function publicName(name: string) {
  const words = name.trim().split(/\s+/).filter(Boolean);
  const first = words[0] || "Pelanggan";
  const maskedFirst = first.length <= 2
    ? `${first[0] || "P"}**`
    : `${first.slice(0, Math.min(3, first.length))}***`;
  if (words.length < 2) return maskedFirst;
  return `${maskedFirst} ${words[words.length - 1][0]?.toUpperCase() ?? ""}.`;
}
