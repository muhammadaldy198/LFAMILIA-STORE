import { getD1 } from "@/db";
import { readCategories, readFaqs, readStorefrontSettings } from "@/lib/server/storefront";

export const dynamic = "force-dynamic";

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
      question: row.question,
      answer: row.answer,
      isActive: Boolean(row.is_active),
      sortOrder: row.sort_order,
    }));
  } catch {
    return readFaqs(false);
  }
}

export async function GET() {
  const [settings, categories, faqs] = await Promise.all([
    readStorefrontSettings(),
    readCategories(false),
    readPublicFaqs(),
  ]);
  return Response.json(
    { settings, categories, faqs },
    { headers: { "Cache-Control": "public, max-age=60" } },
  );
}
