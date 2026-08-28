import Link from "next/link";
import { ChevronRight, Clock3, Heart, LogOut, PackageCheck, Settings, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { StoreLayout } from "@/components/store-layout";
import { demoOrder, formatRupiah } from "@/lib/store-data";

export default function AccountPage() {
  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-6xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
        <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p className="eyebrow">Area pelanggan</p><h1 className="section-title">Halo, Familia!</h1><p className="mt-3 text-sm text-white/38">Ini adalah contoh tampilan akun setelah login.</p></div><span className="w-fit rounded-full bg-amber-400/10 px-3 py-1.5 text-[10px] font-black uppercase text-amber-300">Akun demo</span></div>
        <div className="grid items-start gap-6 lg:grid-cols-[280px_1fr]">
          <aside className="panel p-4"><div className="flex items-center gap-3 border-b border-white/[0.08] p-2 pb-5"><span className="grid size-11 place-items-center rounded-xl bg-[#b9ff35]/12 text-[#b9ff35]"><UserRound className="size-5" /></span><div><strong className="text-sm">Familia Demo</strong><p className="mt-1 text-[10px] text-white/32">familia@example.com</p></div></div><nav className="mt-3 space-y-1">{[[Clock3, "Riwayat transaksi"], [Heart, "Tujuan favorit"], [Settings, "Pengaturan akun"]].map(([Icon, label]) => { const IconComponent = Icon as typeof Clock3; return <span key={label as string} className="flex items-center justify-between rounded-xl px-3 py-3 text-xs text-white/48 first:bg-white/[0.055] first:text-white"><span className="flex items-center gap-3"><IconComponent className="size-4" />{label as string}</span><ChevronRight className="size-3.5" /></span>; })}</nav><Button variant="ghost" className="mt-3 w-full justify-start rounded-xl text-xs text-red-300/60 hover:bg-red-400/[0.06] hover:text-red-200"><LogOut className="mr-3 size-4" />Keluar (demo)</Button></aside>
          <section><div className="grid grid-cols-2 gap-3 sm:grid-cols-3"><div className="panel p-4"><span className="text-[10px] uppercase tracking-wider text-white/30">Total pesanan</span><strong className="mt-3 block text-2xl font-black">1</strong></div><div className="panel p-4"><span className="text-[10px] uppercase tracking-wider text-white/30">Berhasil</span><strong className="mt-3 block text-2xl font-black text-[#b9ff35]">1</strong></div><div className="panel col-span-2 p-4 sm:col-span-1"><span className="text-[10px] uppercase tracking-wider text-white/30">Total belanja</span><strong className="mt-3 block text-lg font-black">{formatRupiah(demoOrder.amount)}</strong></div></div>
            <div className="panel mt-5 overflow-hidden"><div className="flex items-center justify-between border-b border-white/[0.08] p-5"><div><h2 className="font-bold">Transaksi terbaru</h2><p className="mt-1 text-[10px] text-white/30">Riwayat akun demo</p></div><PackageCheck className="size-5 text-[#b9ff35]" /></div><Link href="/track" className="group block p-5 transition hover:bg-white/[0.025]"><div className="flex items-start justify-between gap-4"><div><p className="font-mono text-[10px] text-white/32">{demoOrder.id}</p><h3 className="mt-2 font-bold">{demoOrder.product}</h3><p className="mt-1 text-xs text-white/38">{demoOrder.item} • {demoOrder.destination}</p></div><span className="rounded-full bg-[#b9ff35]/10 px-2.5 py-1 text-[9px] font-black uppercase text-[#cfff72]">Berhasil</span></div><div className="mt-5 flex items-end justify-between border-t border-white/[0.07] pt-4"><div><span className="block text-[9px] text-white/28">Total</span><strong className="mt-1 block text-sm">{formatRupiah(demoOrder.amount)}</strong></div><span className="inline-flex items-center gap-1 text-[11px] font-bold text-[#cfff72]">Detail <ChevronRight className="size-3.5 transition group-hover:translate-x-1" /></span></div></Link></div>
          </section>
        </div>
      </main>
    </StoreLayout>
  );
}

