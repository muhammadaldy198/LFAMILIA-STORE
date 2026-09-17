export const PRODUCT_CATEGORIES = [
  { slug: "game", label: "Top Up Game", icon: "gamepad" },
  { slug: "voucher", label: "Voucher & Gift Card", icon: "ticket" },
  { slug: "entertainment", label: "Entertainment", icon: "play" },
  { slug: "pulsa", label: "Pulsa", icon: "smartphone" },
  { slug: "pln", label: "PLN", icon: "zap" },
] as const;

export type ProductCategorySlug = (typeof PRODUCT_CATEGORIES)[number]["slug"];
export type ProductCategoryLabel = (typeof PRODUCT_CATEGORIES)[number]["label"];

export const PRODUCT_CATEGORY_LABELS = PRODUCT_CATEGORIES.map((item) => item.label) as ProductCategoryLabel[];

export function normalizeProductCategorySlug(value: string): ProductCategorySlug {
  const normalized = value.trim().toLowerCase();
  if (normalized === "voucher" || normalized.includes("voucher") || normalized.includes("gift")) return "voucher";
  if (normalized === "entertainment" || normalized.includes("entertain")) return "entertainment";
  if (normalized === "pulsa" || normalized.includes("pulsa") || normalized.includes("data")) return "pulsa";
  if (normalized === "pln" || normalized.includes("listrik")) return "pln";
  // Legacy PC game entries now belong to the single Top Up Game category.
  return "game";
}

export function productCategoryLabel(value: string): ProductCategoryLabel {
  const slug = normalizeProductCategorySlug(value);
  return PRODUCT_CATEGORIES.find((item) => item.slug === slug)?.label ?? "Top Up Game";
}

export function productCategorySlug(label: ProductCategoryLabel | string): ProductCategorySlug {
  const exact = PRODUCT_CATEGORIES.find((item) => item.label === label);
  return exact?.slug ?? normalizeProductCategorySlug(label);
}
