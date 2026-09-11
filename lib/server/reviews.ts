import { getD1 } from "@/db";

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
    `SELECT r.id, r.product_slug, r.rating, r.title, r.body, u.name AS customer_name,
      r.is_verified_purchase, r.is_visible, r.created_at, r.updated_at
     FROM product_reviews r JOIN customer_users u ON u.id = r.customer_id
     WHERE r.product_slug = ? AND r.is_visible = 1
     ORDER BY r.created_at DESC LIMIT 100`,
  ).bind(productSlug).all<ReviewRow>();
  return result.results.map(reviewFromRow);
}

export async function listAllReviews() {
  const result = await getD1().prepare(
    `SELECT r.id, r.product_slug, r.rating, r.title, r.body, u.name AS customer_name,
      r.is_verified_purchase, r.is_visible, r.created_at, r.updated_at
     FROM product_reviews r JOIN customer_users u ON u.id = r.customer_id
     ORDER BY r.created_at DESC LIMIT 300`,
  ).all<ReviewRow>();
  return result.results.map(reviewFromRow);
}

export async function listFeaturedReviews(limit = 6) {
  const safeLimit = Math.min(Math.max(Math.trunc(limit), 1), 12);
  const result = await getD1().prepare(
    `SELECT r.id, r.product_slug, r.rating, r.title, r.body, u.name AS customer_name,
      r.is_verified_purchase, r.is_visible, r.created_at, r.updated_at
     FROM product_reviews r JOIN customer_users u ON u.id = r.customer_id
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

export async function saveProductReview(input: { customerId: string; productSlug: string; rating: number; title?: string; body: string }) {
  const db = getD1();
  const product = await db.prepare("SELECT slug FROM products WHERE slug = ? AND is_active = 1 LIMIT 1").bind(input.productSlug).first<{ slug: string }>();
  if (!product) throw new Error("Produk tidak ditemukan.");
  const purchase = await db.prepare(
    "SELECT id FROM orders WHERE customer_id = ? AND product_slug = ? AND payment_status = 'paid' LIMIT 1",
  ).bind(input.customerId, input.productSlug).first<{ id: string }>();
  if (!purchase) throw new Error("Ulasan tersedia setelah kamu menyelesaikan pembelian produk ini.");
  await db.prepare(
    `INSERT INTO product_reviews (customer_id, product_slug, rating, title, body, is_verified_purchase, is_visible)
     VALUES (?, ?, ?, ?, ?, 1, 1)
     ON CONFLICT(customer_id, product_slug) DO UPDATE SET rating = excluded.rating, title = excluded.title,
      body = excluded.body, is_verified_purchase = 1, is_visible = 1, updated_at = CURRENT_TIMESTAMP`,
  ).bind(input.customerId, input.productSlug, input.rating, input.title || null, input.body).run();
}

export async function moderateProductReview(id: number, isVisible: boolean) {
  await getD1().prepare("UPDATE product_reviews SET is_visible = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
    .bind(isVisible ? 1 : 0, id).run();
}

function publicName(name: string) {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length < 2) return words[0] || "Pelanggan";
  return `${words[0]} ${words[words.length - 1][0]?.toUpperCase() ?? ""}.`;
}
