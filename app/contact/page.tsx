import { Headphones, ShieldCheck } from "lucide-react";
import { ContactPanel } from "@/components/contact-panel";
import { StoreLayout } from "@/components/store-layout";

export default function ContactPage() {
  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-6xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <section className="flex flex-col justify-between gap-6 border-b border-white/[0.08] pb-9 sm:flex-row sm:items-end">
          <div className="flex items-start gap-4">
            <span className="grid size-12 shrink-0 place-items-center rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] text-[#cfff72]"><Headphones className="size-6" /></span>
            <div><p className="eyebrow">Pusat bantuan · Contact us</p><h1 className="section-title">Hubungi tim LFAMILIA</h1><p className="mt-4 max-w-2xl text-sm leading-7 text-white/45">Pilih kanal resmi atau siapkan laporan transaksi melalui formulir. Sertakan nomor invoice agar kendala lebih mudah diperiksa.</p></div>
          </div>
          <p className="flex shrink-0 items-center gap-2 text-[10px] leading-5 text-white/30"><ShieldCheck className="size-4 text-[#b9ff35]" />Kanal resmi tercantum di halaman ini</p>
        </section>
        <div className="mt-9"><ContactPanel /></div>
      </main>
    </StoreLayout>
  );
}
