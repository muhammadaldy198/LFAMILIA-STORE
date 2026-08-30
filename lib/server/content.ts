import { getD1 } from "@/db";

export type HomeBannerRecord = {
  id: number | null;
  title: string;
  subtitle: string;
  imageUrl: string;
  ctaLabel: string;
  ctaHref: string;
  isActive: boolean;
  sortOrder: number;
};

export type SitePopupRecord = {
  id: number | null;
  title: string;
  body: string;
  primaryLabel?: string;
  primaryHref?: string;
  secondaryLabel?: string;
  secondaryHref?: string;
  dismissDays: number;
  isActive: boolean;
  sortOrder: number;
};

export type NewsRecord = {
  id: number | null;
  slug: string;
  title: string;
  summary: string;
  body: string;
  coverUrl?: string;
  isPublished: boolean;
  publishedAt?: string;
  sortOrder: number;
};

export async function listHomeBanners(includeInactive = false): Promise<HomeBannerRecord[]> {
  const result = await getD1().prepare(
    `SELECT id, title, subtitle, image_url, cta_label, cta_href, is_active, sort_order
     FROM home_banners ${includeInactive ? "" : "WHERE is_active = 1"}
     ORDER BY sort_order ASC, id ASC`,
  ).all<{ id: number; title: string; subtitle: string; image_url: string; cta_label: string; cta_href: string; is_active: number; sort_order: number }>();
  return result.results.map((row) => ({ id: row.id, title: row.title, subtitle: row.subtitle, imageUrl: row.image_url, ctaLabel: row.cta_label, ctaHref: row.cta_href, isActive: Boolean(row.is_active), sortOrder: row.sort_order }));
}

export async function saveHomeBanner(input: Omit<HomeBannerRecord, "id">, id?: number) {
  const db = getD1();
  if (id) {
    await db.prepare("UPDATE home_banners SET title = ?, subtitle = ?, image_url = ?, cta_label = ?, cta_href = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(input.title, input.subtitle, input.imageUrl, input.ctaLabel, input.ctaHref, input.isActive ? 1 : 0, input.sortOrder, id).run();
    return id;
  }
  const row = await db.prepare("INSERT INTO home_banners (title, subtitle, image_url, cta_label, cta_href, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id")
    .bind(input.title, input.subtitle, input.imageUrl, input.ctaLabel, input.ctaHref, input.isActive ? 1 : 0, input.sortOrder).first<{ id: number }>();
  if (!row) throw new Error("Banner gagal disimpan.");
  return row.id;
}

export async function listSitePopups(includeInactive = false): Promise<SitePopupRecord[]> {
  const result = await getD1().prepare(
    `SELECT id, title, body, primary_label, primary_href, secondary_label, secondary_href, dismiss_days, is_active, sort_order
     FROM site_popups ${includeInactive ? "" : "WHERE is_active = 1"}
     ORDER BY sort_order ASC, id ASC`,
  ).all<{ id: number; title: string; body: string; primary_label: string | null; primary_href: string | null; secondary_label: string | null; secondary_href: string | null; dismiss_days: number; is_active: number; sort_order: number }>();
  return result.results.map((row) => ({ id: row.id, title: row.title, body: row.body, primaryLabel: row.primary_label ?? undefined, primaryHref: row.primary_href ?? undefined, secondaryLabel: row.secondary_label ?? undefined, secondaryHref: row.secondary_href ?? undefined, dismissDays: row.dismiss_days, isActive: Boolean(row.is_active), sortOrder: row.sort_order }));
}

export async function saveSitePopup(input: Omit<SitePopupRecord, "id">, id?: number) {
  const db = getD1();
  const values = [input.title, input.body, input.primaryLabel || null, input.primaryHref || null, input.secondaryLabel || null, input.secondaryHref || null, input.dismissDays, input.isActive ? 1 : 0, input.sortOrder];
  if (id) {
    await db.prepare("UPDATE site_popups SET title = ?, body = ?, primary_label = ?, primary_href = ?, secondary_label = ?, secondary_href = ?, dismiss_days = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(...values, id).run();
    return id;
  }
  const row = await db.prepare("INSERT INTO site_popups (title, body, primary_label, primary_href, secondary_label, secondary_href, dismiss_days, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id")
    .bind(...values).first<{ id: number }>();
  if (!row) throw new Error("Pop-up gagal disimpan.");
  return row.id;
}

export async function listNews(includeDrafts = false): Promise<NewsRecord[]> {
  const result = await getD1().prepare(
    `SELECT id, slug, title, summary, body, cover_url, is_published, published_at, sort_order
     FROM news_articles ${includeDrafts ? "" : "WHERE is_published = 1 AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP)"}
     ORDER BY sort_order ASC, COALESCE(published_at, created_at) DESC, id DESC`,
  ).all<{ id: number; slug: string; title: string; summary: string; body: string; cover_url: string | null; is_published: number; published_at: string | null; sort_order: number }>();
  return result.results.map((row) => ({ id: row.id, slug: row.slug, title: row.title, summary: row.summary, body: row.body, coverUrl: row.cover_url ?? undefined, isPublished: Boolean(row.is_published), publishedAt: row.published_at ?? undefined, sortOrder: row.sort_order }));
}

export async function getNewsBySlug(slug: string) {
  const all = await listNews(false);
  return all.find((item) => item.slug === slug) ?? null;
}

export async function saveNews(input: Omit<NewsRecord, "id">, id?: number) {
  const db = getD1();
  const values = [input.slug, input.title, input.summary, input.body, input.coverUrl || null, input.isPublished ? 1 : 0, input.publishedAt || null, input.sortOrder];
  if (id) {
    await db.prepare("UPDATE news_articles SET slug = ?, title = ?, summary = ?, body = ?, cover_url = ?, is_published = ?, published_at = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(...values, id).run();
    return id;
  }
  const row = await db.prepare("INSERT INTO news_articles (slug, title, summary, body, cover_url, is_published, published_at, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id")
    .bind(...values).first<{ id: number }>();
  if (!row) throw new Error("Berita gagal disimpan.");
  return row.id;
}

export async function deleteManagedContent(kind: "banner" | "popup" | "news", id: number) {
  const table = kind === "banner" ? "home_banners" : kind === "popup" ? "site_popups" : "news_articles";
  await getD1().prepare(`DELETE FROM ${table} WHERE id = ?`).bind(id).run();
}
