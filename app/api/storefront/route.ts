import { getD1 } from "@/db";
import { neutralizePublicCopy } from "@/lib/public-copy";
import { readCategories, readFaqs, readStorefrontSettings } from "@/lib/server/storefront";

export const dynamic = "force-dynamic";

type PublicStorefrontSettings = Awaited<ReturnType<typeof readStorefrontSettings>>;

async function readPublicFaqs() {
  try {
    const result = await getD1()
      .prepare(
        `SELECT id, question, answer, is_active, sort_order
         FROM faq_entries
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC`,
      )
      .all<{ id: number; question: string; answer: string; is_active: number; sort_order: number }>();
    return result.results.map((row) => ({
      id: row.id,
      question: neutralizePublicCopy(row.question),
      answer: neutralizePublicCopy(row.answer),
      isActive: Boolean(row.is_active),
      sortOrder: row.sort_order,
    }));
  } catch {
    const faqs = await readFaqs(false);
    return faqs.map((row) => ({
      ...row,
      question: neutralizePublicCopy(row.question),
      answer: neutralizePublicCopy(row.answer),
    }));
  }
}

function neutralizeStorefrontSettings(settings: PublicStorefrontSettings): PublicStorefrontSettings {
  return {
    ...settings,
    storeName: neutralizePublicCopy(settings.storeName),
    storeShortName: neutralizePublicCopy(settings.storeShortName),
    tagline: neutralizePublicCopy(settings.tagline),
    announcement: settings.announcement ? neutralizePublicCopy(settings.announcement) : settings.announcement,
    bannerEyebrow: neutralizePublicCopy(settings.bannerEyebrow),
    bannerTitle: neutralizePublicCopy(settings.bannerTitle),
    bannerHighlight: neutralizePublicCopy(settings.bannerHighlight),
    bannerDescription: neutralizePublicCopy(settings.bannerDescription),
    bannerCtaLabel: neutralizePublicCopy(settings.bannerCtaLabel),
    supportHours: neutralizePublicCopy(settings.supportHours),
    merchantLegalName: settings.merchantLegalName ? neutralizePublicCopy(settings.merchantLegalName) : "",
    merchantRegistrationId: settings.merchantRegistrationId ? neutralizePublicCopy(settings.merchantRegistrationId) : "",
    merchantAddress: settings.merchantAddress ? neutralizePublicCopy(settings.merchantAddress) : "",
  };
}

export async function GET() {
  const [settings, categories, faqs] = await Promise.all([
    readStorefrontSettings(),
    readCategories(false),
    readPublicFaqs(),
  ]);
  return Response.json(
    { settings: neutralizeStorefrontSettings(settings), categories, faqs },
    { headers: { "Cache-Control": "public, max-age=60" } },
  );
}
