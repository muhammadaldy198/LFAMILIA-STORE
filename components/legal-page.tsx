import Link from "next/link";
import { ArrowLeft, FileCheck2, Info } from "lucide-react";
import { StoreLayout } from "@/components/store-layout";

export type LegalSection = { title: string; paragraphs?: string[]; items?: string[] };

export function LegalPage({ eyebrow, title, intro, sections }: { eyebrow: string; title: string; intro: string; sections: LegalSection[] }) {
  return (
    <StoreLayout>
      <main className="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <Link href="/" className="inline-flex items-center gap-2 text-xs font-semibold text-white/40 hover:text-white"><ArrowLeft className="size-4" /> Kembali ke beranda</Link>
        <div className="mt-8 border-b border-white/[0.08] pb-8"><div className="flex items-center gap-2"><p className="eyebrow !mb-0">{eyebrow}</p><span className="rounded-full bg-amber-400/10 px-2 py-0.5 text-[8px] font-black uppercase text-amber-300">Draf</span></div><h1 className="mt-3 text-4xl font-black tracking-[-0.05em] sm:text-5xl">{title}</h1><p className="mt-5 max-w-2xl text-sm leading-7 text-white/45">{intro}</p><p className="mt-4 flex items-center gap-2 text-[10px] text-white/28"><FileCheck2 className="size-3.5" /> Terakhir diperbarui: 28 Agustus 2026</p></div>
        <div className="my-6 flex items-start gap-3 rounded-2xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.05] p-4 text-[11px] leading-5 text-white/42"><Info className="mt-0.5 size-4 shrink-0 text-[#b9ff35]" /><p>Dokumen ini masih contoh untuk tahap pembuatan website. Sesuaikan identitas badan usaha, kontak resmi, alur operasional, dan tinjau secara hukum sebelum toko menerima pembayaran.</p></div>
        <article className="space-y-9 py-4">{sections.map((section, index) => <section key={section.title}><h2 className="text-lg font-black"><span className="mr-3 font-mono text-[10px] text-[#b9ff35]">{String(index + 1).padStart(2, "0")}</span>{section.title}</h2>{section.paragraphs?.map((paragraph) => <p key={paragraph} className="mt-3 text-sm leading-7 text-white/46">{paragraph}</p>)}{section.items && <ul className="mt-3 space-y-2 pl-5 text-sm leading-7 text-white/46">{section.items.map((item) => <li key={item} className="list-disc marker:text-[#b9ff35]">{item}</li>)}</ul>}</section>)}</article>
      </main>
    </StoreLayout>
  );
}

