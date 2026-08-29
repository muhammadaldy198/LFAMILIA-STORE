import { readCategories, readFaqs, readStorefrontSettings } from "@/lib/server/storefront";

export const dynamic = "force-dynamic";

export async function GET() {
  const [settings, categories, faqs] = await Promise.all([
    readStorefrontSettings(),
    readCategories(false),
    readFaqs(false),
  ]);
  return Response.json({ settings, categories, faqs }, { headers: { "Cache-Control": "public, max-age=60" } });
}
