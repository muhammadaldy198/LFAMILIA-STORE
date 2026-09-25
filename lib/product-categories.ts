export const PRODUCT_CATEGORIES = [
  { slug: "game", label: "Top Up Game", icon: "gamepad" },
  { slug: "voucher", label: "Voucher & Gift Card", icon: "ticket" },
  { slug: "entertainment", label: "Entertainment", icon: "play" },
  { slug: "pulsa", label: "Pulsa", icon: "smartphone" },
  { slug: "pln", label: "PLN", icon: "zap" },
] as const;

export type ProductCategorySlug = string;
export type ProductCategoryLabel = string;

export const PRODUCT_CATEGORY_LABELS = PRODUCT_CATEGORIES.map((item) => item.label);

export function normalizeProductCategorySlug(value: string): ProductCategorySlug {
  const normalized = value.trim().toLowerCase();
  if (!normalized) return "game";
  if (normalized === "voucher" || normalized.includes("voucher") || normalized.includes("gift")) return "voucher";
  if (normalized === "entertainment" || normalized.includes("entertain")) return "entertainment";
  if (normalized === "pulsa" || normalized.includes("pulsa") || normalized.includes("data")) return "pulsa";
  if (normalized === "pln" || normalized.includes("listrik")) return "pln";
  if (normalized === "game" || normalized === "games" || normalized.includes("top-up-game") || normalized.includes("topup-game")) return "game";
  return normalized.replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "game";
}

export function productCategoryLabel(value: string): ProductCategoryLabel {
  const slug = normalizeProductCategorySlug(value);
  const known = PRODUCT_CATEGORIES.find((item) => item.slug === slug);
  if (known) return known.label;
  return slug.split("-").filter(Boolean).map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(" ") || "Top Up Game";
}

export function productCategorySlug(label: ProductCategoryLabel | string): ProductCategorySlug {
  const exact = PRODUCT_CATEGORIES.find((item) => item.label === label);
  return exact?.slug ?? normalizeProductCategorySlug(label);
}
