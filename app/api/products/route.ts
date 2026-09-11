import { readProducts } from "@/lib/server/products";
import { readReviewSummaries } from "@/lib/server/reviews";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const stored = await readProducts(false);
    const summaries = await readReviewSummaries().catch(() => new Map<string, { ratingAverage: number; ratingCount: number }>());
    const products = stored
      .filter((item) => item.packages.length > 0)
      .map((item) => ({
        slug: item.slug,
        name: item.name,
        publisher: item.publisher,
        category: item.category,
        imageUrl: item.imageUrl,
        bannerUrl: item.bannerUrl,
        description: item.description,
        initials: item.initials,
        accent: item.accent,
        inputLabel: item.inputLabel,
        inputPlaceholder: item.inputPlaceholder,
        inputFields: item.inputFields,
        needsServer: item.needsServer,
        popular: item.popular,
        instant: item.instant,
        fulfillmentType: item.fulfillmentType,
        targetTemplate: item.targetTemplate,
        manualInstructions: item.manualInstructions,
        manualOpenTime: item.manualOpenTime,
        manualCloseTime: item.manualCloseTime,
        manualTimezone: item.manualTimezone,
        packageTabsEnabled: item.packageTabsEnabled,
        packageTabs: item.packageTabs,
        notices: item.notices,
        packages: item.packages.map((pkg) => ({
          id: pkg.id,
          label: pkg.label,
          price: pkg.price,
          note: pkg.note,
          group: pkg.group,
          imageUrl: pkg.imageUrl,
          providerCode: pkg.providerCode,
          providerConfigured: Boolean(pkg.providerCode && pkg.providerSku),
        })),
        ...(summaries.get(item.slug) ?? { ratingAverage: 0, ratingCount: 0 }),
      }));
    return Response.json({
      products,
      databaseReady: true,
    });
  } catch {
    return Response.json(
      { products: [], databaseReady: false },
      { status: 503 },
    );
  }
}
