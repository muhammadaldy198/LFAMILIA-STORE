import { Headphones } from "lucide-react";
import { ContactPanel } from "@/components/contact-panel";
import { StoreLayout } from "@/components/store-layout";

export default function ContactPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-6xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"><div className="flex items-start gap-4"><span className="grid size-12 shrink-0 place-items-center rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] text-[#cfff72]"><Headphones className="size-6" /></span><div><p className="eyebrow">Pusat bantuan</p><h1 className="section-title">Hubungi LFAMILIA STORE</h1><p className="mt-4 max-w-2xl text-sm leading-6 text-white/42">Butuh bantuan terkait produk atau pesanan? Siapkan pesanmu di sini.</p></div></div><div className="mt-9"><ContactPanel /></div></main></StoreLayout>;
}
