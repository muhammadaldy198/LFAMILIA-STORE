import { readProducts } from "@/lib/server/products";
import { readReviewSummaries } from "@/lib/server/reviews";
import { ensureKokinpayNicknameGameCodeBackfill } from "@/lib/server/nickname-config";
import {
  readAvailableVoucherStockKeys,
  readDigiflazzPackageAvailability,
} from "@/lib/server/availability";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    await ensureKokinpayNicknameGameCodeBackfill();
    const [stored, summaries, digiflazzAvailability, voucherStockKeys] = await Promise.all([
      readProducts(false),
      readReviewSummaries().catch(() => new Map<string, { ratingAverage: number; ratingCount: number }>()),
      readDigiflazzPackageAvailability().catch(() => new Map<number, boolean>()),
      readAvailableVoucherStockKeys().catch(() => new Set<string>()),
    ]);

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
        nicknameRequired: Boolean(item.nicknameRequired),
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
        packages: [...item.packages]
          .sort((left, right) =>
            left.price - right.price ||
            left.sortOrder - right.sortOrder ||
            left.label.localeCompare(right.label, "id-ID", { numeric: true, sensitivity: "base" }),
          )
          .map((pkg) => {
          const fulfillmentMode = item.fulfillmentType === "manual"
            ? "manual"
            : pkg.providerCode === "voucher-stock"
              ? "voucher_stock"
              : "provider";
          const fulfillmentReady = item.fulfillmentType === "manual" || Boolean(pkg.providerCode && pkg.providerSku);
          const fulfillmentAvailable = item.fulfillmentType === "manual"
            ? true
            : pkg.providerCode === "voucher-stock"
              ? Boolean(pkg.providerSku && voucherStockKeys.has(pkg.providerSku))
              : pkg.providerCode === "digiflazz"
                ? Boolean(pkg.dbId != null && digiflazzAvailability.get(pkg.dbId) === true)
                : false;

          return {
            id: pkg.id,
            label: pkg.label,
            price: pkg.price,
            note: pkg.note,
            group: pkg.group,
            imageUrl: pkg.imageUrl,
            fulfillmentMode,
            fulfillmentReady,
            fulfillmentAvailable,
          };
        }),
        ...(summaries.get(item.slug) ?? { ratingAverage: 0, ratingCount: 0 }),
      }));
    return Response.json(
      { products, databaseReady: true },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch {
    return Response.json(
      { products: [], databaseReady: false },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
}
