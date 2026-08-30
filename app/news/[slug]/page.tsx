import { NewsDetail } from "@/components/news-detail";
import { StoreLayout } from "@/components/store-layout";

export default function NewsDetailPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-6xl px-4 py-10 sm:px-6 lg:px-8"><NewsDetail /></main></StoreLayout>;
}
