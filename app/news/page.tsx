import { Newspaper } from "lucide-react";
import { NewsBrowser } from "@/components/news-browser";
import { StoreLayout } from "@/components/store-layout";

export default function NewsPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"><div className="flex items-start gap-4"><span className="grid size-12 shrink-0 place-items-center rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] text-[#cfff72]"><Newspaper className="size-6" /></span><div><p className="eyebrow">Update terbaru</p><h1 className="section-title">Berita LFAMILIA</h1><p className="mt-3 max-w-2xl text-sm leading-6 text-white/42">Info produk, jadwal layanan, promo, dan pengumuman toko.</p></div></div><div className="mt-9"><NewsBrowser /></div></main></StoreLayout>;
}
