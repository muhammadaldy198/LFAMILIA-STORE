import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
import { defaultStorefrontSettings, faqs as fallbackFaqs, type StorefrontSettings } from "@/lib/store-data";
import { safeHttpUrl, safeNavigationUrl } from "@/lib/navigation-url";

type SettingsRow = {
  store_name: string;
  store_short_name: string;
  tagline: string;
  logo_url: string | null;
  announcement: string | null;
  banner_enabled: number;
  banner_eyebrow: string;
  banner_title: string;
  banner_highlight: string;
  banner_description: string;
  banner_image_url: string | null;
  banner_cta_label: string;
  banner_cta_href: string;
  support_whatsapp: string | null;
  support_email: string | null;
  instagram_url: string | null;
  discord_url: string | null;
  support_hours: string;
  support_widget_enabled: number;
};

export type ProductCategoryRecord = {
  id: number | null;
  slug: string;
  name: string;
  icon: string;
  isActive: boolean;
  sortOrder: number;
};

export type FaqRecord = {
  id: number | null;
  question: string;
  answer: string;
  isActive: boolean;
  sortOrder: number;
};

export async function readStorefrontSettings(): Promise<StorefrontSettings> {
  try {
    await ensureLegacyDatabaseColumns();
    const row = await getD1().prepare("SELECT * FROM store_settings WHERE id = 1").first<SettingsRow>();
    if (!row) return defaultStorefrontSettings;
    return {
      storeName: row.store_name,
      storeShortName: row.store_short_name,
      tagline: row.tagline,
      logoUrl: row.logo_url ?? defaultStorefrontSettings.logoUrl,
      announcement: row.announcement ?? undefined,
      bannerEnabled: Boolean(row.banner_enabled),
      bannerEyebrow: row.banner_eyebrow,
      bannerTitle: row.banner_title,
      bannerHighlight: row.banner_highlight,
      bannerDescription: row.banner_description,
      bannerImageUrl: row.banner_image_url ?? defaultStorefrontSettings.bannerImageUrl,
      bannerCtaLabel: row.banner_cta_label,
      bannerCtaHref: safeNavigationUrl(row.banner_cta_href, "/catalog"),
      supportWhatsapp: row.support_whatsapp ?? undefined,
      supportEmail: row.support_email ?? undefined,
      instagramUrl: safeHttpUrl(row.instagram_url) || undefined,
      discordUrl: safeHttpUrl(row.discord_url) || undefined,
      supportHours: row.support_hours,
      supportWidgetEnabled: Boolean(row.support_widget_enabled),
    };
  } catch {
    return defaultStorefrontSettings;
  }
}

export async function saveStorefrontSettings(input: StorefrontSettings) {
  await ensureLegacyDatabaseColumns();
  await getD1().prepare(
    `INSERT INTO store_settings (
      id, store_name, store_short_name, tagline, logo_url, announcement, banner_enabled,
      banner_eyebrow, banner_title, banner_highlight, banner_description, banner_image_url,
      banner_cta_label, banner_cta_href, support_whatsapp, support_email, instagram_url,
      discord_url, support_hours, support_widget_enabled, updated_at
    ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(id) DO UPDATE SET
      store_name = excluded.store_name, store_short_name = excluded.store_short_name,
      tagline = excluded.tagline, logo_url = excluded.logo_url, announcement = excluded.announcement,
      banner_enabled = excluded.banner_enabled, banner_eyebrow = excluded.banner_eyebrow,
      banner_title = excluded.banner_title, banner_highlight = excluded.banner_highlight,
      banner_description = excluded.banner_description, banner_image_url = excluded.banner_image_url,
      banner_cta_label = excluded.banner_cta_label, banner_cta_href = excluded.banner_cta_href,
      support_whatsapp = excluded.support_whatsapp, support_email = excluded.support_email,
      instagram_url = excluded.instagram_url, discord_url = excluded.discord_url,
      support_hours = excluded.support_hours, support_widget_enabled = excluded.support_widget_enabled,
      updated_at = CURRENT_TIMESTAMP`,
  ).bind(
    input.storeName, input.storeShortName, input.tagline, input.logoUrl || null,
    input.announcement || null, input.bannerEnabled ? 1 : 0, input.bannerEyebrow,
    input.bannerTitle, input.bannerHighlight, input.bannerDescription,
    input.bannerImageUrl || null, input.bannerCtaLabel, input.bannerCtaHref,
    input.supportWhatsapp || null, input.supportEmail || null, input.instagramUrl || null,
    input.discordUrl || null, input.supportHours, input.supportWidgetEnabled ? 1 : 0,
  ).run();
}

export async function readCategories(includeInactive = false): Promise<ProductCategoryRecord[]> {
  try {
    const result = await getD1().prepare(
      `SELECT id, slug, name, icon, is_active, sort_order FROM product_categories
       ${includeInactive ? "" : "WHERE is_active = 1"} ORDER BY sort_order ASC, name ASC`,
    ).all<{ id: number; slug: string; name: string; icon: string; is_active: number; sort_order: number }>();
    return result.results.map((row) => ({ id: row.id, slug: row.slug, name: row.name, icon: row.icon, isActive: Boolean(row.is_active), sortOrder: row.sort_order }));
  } catch {
    return [
      { id: null, slug: "game", name: "Top Up Game", icon: "gamepad", isActive: true, sortOrder: 0 },
      { id: null, slug: "voucher", name: "Voucher & Gift Card", icon: "ticket", isActive: true, sortOrder: 1 },
      { id: null, slug: "entertainment", name: "Entertainment", icon: "play", isActive: true, sortOrder: 2 },
      { id: null, slug: "pulsa", name: "Pulsa & Data", icon: "smartphone", isActive: true, sortOrder: 3 },
      { id: null, slug: "pln", name: "PLN", icon: "zap", isActive: true, sortOrder: 4 },
    ];
  }
}

export async function saveCategory(input: Omit<ProductCategoryRecord, "id">, id?: number) {
  const db = getD1();
  if (id) {
    await db.prepare("UPDATE product_categories SET slug = ?, name = ?, icon = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(input.slug, input.name, input.icon, input.isActive ? 1 : 0, input.sortOrder, id).run();
    return id;
  }
  const result = await db.prepare("INSERT INTO product_categories (slug, name, icon, is_active, sort_order) VALUES (?, ?, ?, ?, ?) RETURNING id")
    .bind(input.slug, input.name, input.icon, input.isActive ? 1 : 0, input.sortOrder).first<{ id: number }>();
  if (!result) throw new Error("Kategori gagal disimpan.");
  return result.id;
}

export async function deleteCategory(id: number) {
  const db = getD1();
  const category = await db.prepare("SELECT slug FROM product_categories WHERE id = ?").bind(id).first<{ slug: string }>();
  if (!category) return;
  const usage = await db.prepare("SELECT COUNT(*) AS count FROM products WHERE category = ?").bind(category.slug).first<{ count: number }>();
  if (Number(usage?.count || 0) > 0) throw new Error("Kategori masih digunakan produk dan tidak dapat dihapus.");
  await db.prepare("DELETE FROM product_categories WHERE id = ?").bind(id).run();
}

export async function readFaqs(includeInactive = false): Promise<FaqRecord[]> {
  try {
    const result = await getD1().prepare(
      `SELECT id, question, answer, is_active, sort_order FROM faq_entries
       ${includeInactive ? "" : "WHERE is_active = 1"} ORDER BY sort_order ASC, id ASC`,
    ).all<{ id: number; question: string; answer: string; is_active: number; sort_order: number }>();
    if (result.results.length) return result.results.map((row) => ({
      id: row.id,
      question: row.question,
      answer: row.answer,
      isActive: Boolean(row.is_active),
      sortOrder: row.sort_order,
    }));
  } catch {
    // Fall back to bundled FAQ content until the migration is applied.
  }
  return fallbackFaqs.map((item, index) => ({ id: null, ...item, isActive: true, sortOrder: index }));
}

export async function saveFaq(input: Omit<FaqRecord, "id">, id?: number) {
  const db = getD1();
  if (id) {
    await db.prepare("UPDATE faq_entries SET question = ?, answer = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(input.question, input.answer, input.isActive ? 1 : 0, input.sortOrder, id).run();
    return id;
  }
  const result = await db.prepare("INSERT INTO faq_entries (question, answer, is_active, sort_order) VALUES (?, ?, ?, ?) RETURNING id")
    .bind(input.question, input.answer, input.isActive ? 1 : 0, input.sortOrder).first<{ id: number }>();
  if (!result) throw new Error("FAQ gagal disimpan.");
  return result.id;
}

export async function deleteFaq(id: number) {
  await getD1().prepare("DELETE FROM faq_entries WHERE id = ?").bind(id).run();
}

