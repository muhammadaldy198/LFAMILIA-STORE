import { PromotionShowcase } from "@/components/promotion-showcase";
import { StoreLayout } from "@/components/store-layout";

export default function PromoPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"><PromotionShowcase full /></main></StoreLayout>;
}
