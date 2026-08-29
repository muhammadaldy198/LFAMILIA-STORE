import Link from "next/link";
import type { LucideIcon } from "lucide-react";
import { ArrowLeft } from "lucide-react";
import { StoreLayout } from "@/components/store-layout";

export function ToolShell({ title, description, icon: Icon, children }: { title: string; description: string; icon: LucideIcon; children: React.ReactNode }) {
  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-5xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
        <Link href="/tools" className="inline-flex items-center gap-2 text-xs font-semibold text-white/40 transition hover:text-[#cfff72]"><ArrowLeft className="size-4" />Semua kalkulator</Link>
        <div className="mt-7 flex items-start gap-4"><span className="grid size-12 shrink-0 place-items-center rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.08] text-[#cfff72]"><Icon className="size-6" /></span><div><p className="eyebrow">LFAMILIA Tools</p><h1 className="text-3xl font-black tracking-[-0.04em] sm:text-5xl">{title}</h1><p className="mt-3 max-w-2xl text-sm leading-6 text-white/42 sm:text-base">{description}</p></div></div>
        <div className="mt-8">{children}</div>
      </main>
    </StoreLayout>
  );
}
