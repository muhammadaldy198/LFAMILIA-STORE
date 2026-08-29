import Link from "next/link";
import { ArrowRight, Calculator, Disc3, Sparkles } from "lucide-react";
import { StoreLayout } from "@/components/store-layout";

const tools = [
  { href: "/tools/win-rate", label: "Kalkulator Win Rate", text: "Hitung jumlah kemenangan untuk mencapai target WR.", icon: Calculator },
  { href: "/tools/zodiac", label: "Kalkulator Zodiac", text: "Estimasi summon dan diamond menuju 100 poin.", icon: Sparkles },
  { href: "/tools/magic-wheel", label: "Kalkulator Magic Wheel", text: "Hitung draw dan diamond menuju 200 Magic Point.", icon: Disc3 },
];

export default function ToolsPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"><div className="max-w-3xl"><p className="eyebrow">LFAMILIA Tools</p><h1 className="section-title">Alat bantu untuk para gamer.</h1><p className="mt-4 text-sm leading-6 text-white/44 sm:text-base">Hitung kebutuhan game langsung dari satu tempat.</p></div><div className="mt-9 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{tools.map(({ href, label, text, icon: Icon }) => <Link key={href} href={href} className="group rounded-[24px] border border-white/[0.08] bg-[#10131b] p-6 transition hover:-translate-y-0.5 hover:border-[#b9ff35]/30"><div className="flex items-start justify-between gap-4"><span className="grid size-12 place-items-center rounded-2xl bg-[#b9ff35]/[0.09] text-[#cfff72]"><Icon className="size-6" /></span><span className="grid size-9 place-items-center rounded-full border border-white/10 text-white/30 transition group-hover:bg-[#b9ff35] group-hover:text-[#091006]"><ArrowRight className="size-4" /></span></div><h2 className="mt-7 text-lg font-black">{label}</h2><p className="mt-2 text-sm leading-6 text-white/38">{text}</p></Link>)}</div></main></StoreLayout>;
}
