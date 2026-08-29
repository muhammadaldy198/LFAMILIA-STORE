import Link from "next/link";
import { ArrowUpRight, Clock3, Zap } from "lucide-react";
import type { StoreProduct } from "@/lib/store-data";
import { formatRupiah } from "@/lib/store-data";

export function ProductCard({ product }: { product: StoreProduct }) {
  const lowest = Math.min(...product.packages.map((item) => item.price));
  return (
    <Link href={`/checkout?product=${product.slug}`} className="group block rounded-[24px] border border-white/[0.09] bg-[#0d1019] p-3 transition duration-300 hover:-translate-y-1 hover:border-[#b9ff35]/35 hover:shadow-[0_20px_70px_-35px_rgba(185,255,53,0.28)]">
      <div className={`relative flex aspect-[1.35] items-end overflow-hidden rounded-[18px] bg-gradient-to-br ${product.accent} p-4`}>
        <div className="absolute inset-0 bg-[linear-gradient(125deg,transparent_15%,rgba(255,255,255,0.16)_48%,transparent_70%)] opacity-50 transition duration-500 group-hover:translate-x-8" />
        <span className="relative text-5xl font-black tracking-[-0.08em] text-white/90">{product.initials}</span>
        <span className="absolute right-3 top-3 rounded-full border border-white/20 bg-black/25 px-2 py-1 text-[9px] font-bold uppercase tracking-wider text-white/80 backdrop-blur">{product.category === "game" ? "Game" : "Voucher"}</span>
      </div>
      <div className="px-2 pb-2 pt-4">
        <div className="flex items-start justify-between gap-3"><div className="min-w-0"><h3 className="truncate text-sm font-bold tracking-tight text-white sm:text-base">{product.name}</h3><p className="mt-1 text-[11px] text-white/36">{product.publisher}</p></div><span className="grid size-8 shrink-0 place-items-center rounded-full bg-white/[0.06] text-white/45 transition group-hover:bg-[#b9ff35] group-hover:text-[#091006]"><ArrowUpRight className="size-4" /></span></div>
        <div className="mt-4 flex items-end justify-between gap-2 border-t border-white/[0.07] pt-3"><div><span className="block text-[9px] uppercase tracking-wider text-white/30">Mulai</span><strong className="mt-0.5 block text-xs text-[#d8ff8d]">{formatRupiah(lowest)}</strong></div>{product.fulfillmentType === "manual" ? <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase text-amber-300/60"><Clock3 className="size-3" /> Manual</span> : <span className="inline-flex items-center gap-1 text-[9px] font-bold uppercase text-white/38"><Zap className="size-3 text-[#b9ff35]" /> Otomatis</span>}</div>
      </div>
    </Link>
  );
}
