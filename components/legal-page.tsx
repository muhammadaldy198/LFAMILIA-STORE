import Link from "next/link";
import { ArrowLeft, ArrowRight, BookOpenCheck, FileCheck2, ShieldCheck } from "lucide-react";
import { StoreLayout } from "@/components/store-layout";

export type LegalSection = { title: string; paragraphs?: string[]; items?: string[] };

type LegalPageProps = {
  eyebrow: string;
  title: string;
  intro: string;
  highlights: string[];
  sections: LegalSection[];
};

function sectionId(title: string) {
  return title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

export function LegalPage({ eyebrow, title, intro, highlights, sections }: LegalPageProps) {
  return (
    <StoreLayout>
      <main className="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <Link href="/" className="inline-flex items-center gap-2 text-xs font-semibold text-white/40 hover:text-white"><ArrowLeft className="size-4" /> Kembali ke beranda</Link>
        <header className="mt-8 overflow-hidden rounded-[28px] border border-white/[0.08] bg-[radial-gradient(circle_at_top_right,rgba(185,255,53,0.11),transparent_42%),#0d1017] p-6 sm:p-10">
          <p className="eyebrow !mb-0">{eyebrow}</p>
          <h1 className="mt-3 text-3xl font-black tracking-[-0.05em] sm:text-5xl">{title}</h1>
          <p className="mt-5 max-w-3xl text-sm leading-7 text-white/48">{intro}</p>
          <p className="mt-5 flex items-center gap-2 text-[10px] text-white/30"><FileCheck2 className="size-3.5 text-[#b9ff35]" /> Terakhir diperbarui: 31 Agustus 2026</p>
        </header>

        <section className="mt-6 rounded-2xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.045] p-5 sm:p-6">
          <div className="flex items-center gap-2"><ShieldCheck className="size-5 text-[#b9ff35]" /><h2 className="text-sm font-black">Ringkasan penting</h2></div>
          <ul className="mt-4 grid gap-3 text-xs leading-6 text-white/46 sm:grid-cols-3">{highlights.map((item) => <li key={item} className="rounded-xl border border-white/[0.07] bg-black/10 px-4 py-3">{item}</li>)}</ul>
        </section>

        <div className="mt-10 grid gap-8 lg:grid-cols-[minmax(0,1fr)_260px]">
          <article className="divide-y divide-white/[0.08] border-y border-white/[0.08]">
            {sections.map((section, index) => (
              <section id={sectionId(section.title)} key={section.title} className="scroll-mt-24 py-8 first:pt-2 lg:first:pt-3">
                <h2 className="text-lg font-black"><span className="mr-3 font-mono text-[10px] text-[#b9ff35]">{String(index + 1).padStart(2, "0")}</span>{section.title}</h2>
                {section.paragraphs?.map((paragraph) => <p key={paragraph} className="mt-3 text-sm leading-7 text-white/48">{paragraph}</p>)}
                {section.items && <ul className="mt-4 space-y-2.5 pl-5 text-sm leading-7 text-white/48">{section.items.map((item) => <li key={item} className="list-disc marker:text-[#b9ff35]">{item}</li>)}</ul>}
              </section>
            ))}
          </article>

          <aside className="h-fit space-y-4 lg:sticky lg:top-24">
            <nav aria-label="Daftar isi" className="rounded-2xl border border-white/[0.08] bg-[#10131b] p-5">
              <div className="flex items-center gap-2"><BookOpenCheck className="size-4 text-[#b9ff35]" /><h2 className="text-xs font-black">Di halaman ini</h2></div>
              <ol className="mt-4 space-y-3 text-[11px] leading-5 text-white/38">{sections.map((section, index) => <li key={section.title}><a href={`#${sectionId(section.title)}`} className="flex gap-2 transition hover:text-white"><span className="font-mono text-[#b9ff35]/70">{String(index + 1).padStart(2, "0")}</span><span>{section.title}</span></a></li>)}</ol>
            </nav>
            <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5"><h2 className="text-sm font-black">Masih ada pertanyaan?</h2><p className="mt-2 text-xs leading-5 text-white/38">Hubungi tim bantuan melalui kanal resmi LFAMILIA.</p><Link href="/contact" className="mt-4 inline-flex items-center gap-2 text-xs font-black text-[#cfff72] hover:text-white">Hubungi kami <ArrowRight className="size-3.5" /></Link></div>
          </aside>
        </div>
      </main>
    </StoreLayout>
  );
}
