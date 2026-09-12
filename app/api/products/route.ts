import { getD1 } from "@/db";
import { readProducts } from "@/lib/server/products";
import { readReviewSummaries } from "@/lib/server/reviews";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const stored = await readProducts(false);
    const summaries = await readReviewSummaries().catch(() => new Map<string, { ratingAverage: number; ratingCount: number }>());
    const monitorRows = await getD1().prepare("SELECT package_id, buyer_product_status, seller_product_status, unlimited_stock, stock FROM digiflazz_seller_monitor").all<{ package_id: number; buyer_product_status: number; seller_product_status: number; unlimited_stock: number; stock: number }>().catch(() => ({ results: [] }));
    const availability = new Map(monitorRows.results.map((row) => [row.package_id, Boolean(row.buyer_product_status && row.seller_product_status && (row.unlimited_stock || Number(row.stock) > 0))]));
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
          fulfillmentMode: item.fulfillmentType === "manual" ? "manual" : pkg.providerCode === "voucher-stock" ? "voucher_stock" : "provider",
          fulfillmentReady: item.fulfillmentType === "manual" || Boolean(pkg.providerCode && pkg.providerSku),
          fulfillmentAvailable: item.fulfillmentType === "manual" || availability.get(pkg.dbId) !== false,
        })),
        ...(summaries.get(item.slug) ?? { ratingAverage: 0, ratingCount: 0 }),
      }));
    return Response.json({
      products,
      databaseReady: true,
    }, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return Response.json(
      { products: [], databaseReady: false },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
}
