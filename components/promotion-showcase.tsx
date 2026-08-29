"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { Clock3, Copy, Flame, Percent, TicketPercent } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ProductArtwork } from "@/components/product-artwork";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { formatRupiah } from "@/lib/store-data";
import type { DiscountVoucher, FlashSale } from "@/lib/server/promotions";

export function PromotionShowcase({ full = false }: { full?: boolean }) {
  const [vouchers, setVouchers] = useState<DiscountVoucher[]>([]);
  const [flashSales, setFlashSales] = useState<FlashSale[]>([]);
  const [now, setNow] = useState(0);
  useEffect(() => {
    void fetch("/api/promotions", { cache: "no-store" }).then((response) => response.json()).then((data: { vouchers?: DiscountVoucher[]; flashSales?: FlashSale[] }) => {
      setVouchers(data.vouchers ?? []); setFlashSales(data.flashSales ?? []);
    }).catch(() => undefined);
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const shownFlash = useMemo(() => (full ? flashSales : flashSales.slice(0, 4)), [flashSales, full]);
  const shownVouchers = useMemo(() => (full ? vouchers : vouchers.slice(0, 4)), [vouchers, full]);

  return (
    <section className={full ? "" : "mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"}>
      <div className="flex items-end justify-between gap-4"><div><p className="eyebrow">Penawaran terbatas</p><h2 className={full ? "section-title" : "text-2xl font-black tracking-tight sm:text-3xl"}>Promo LFAMILIA</h2></div>{!full && <Link href="/promo" className="text-xs font-bold text-[#cfff72]">Lihat semua</Link>}</div>
      <Tabs defaultValue="flash" className="mt-6">
        <TabsList className="h-11 rounded-xl border border-white/[0.08] bg-[#0d1019] p-1"><TabsTrigger value="flash" className="rounded-lg px-4 text-xs data-[state=active]:bg-[#b9ff35] data-[state=active]:text-[#091006]"><Flame className="mr-2 size-4" />Flash Sale</TabsTrigger><TabsTrigger value="voucher" className="rounded-lg px-4 text-xs data-[state=active]:bg-[#b9ff35] data-[state=active]:text-[#091006]"><TicketPercent className="mr-2 size-4" />Voucher Diskon</TabsTrigger></TabsList>
        <TabsContent value="flash" className="mt-5">{shownFlash.length ? <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{shownFlash.map((item) => <Link href={`/checkout?product=${item.productSlug}&package=${item.packageSku}`} key={item.id} className="group overflow-hidden rounded-2xl border border-white/[0.09] bg-[#0d1019] p-3 transition hover:border-[#b9ff35]/35"><div className="relative aspect-[1.8] overflow-hidden rounded-xl"><ProductArtwork product={item} /><span className="absolute left-2.5 top-2.5 z-30 rounded-full bg-red-500/80 px-2.5 py-1 text-[9px] font-black uppercase text-white backdrop-blur">{item.badge}</span></div><div className="px-1 pb-1 pt-3"><div className="flex items-center justify-between"><span className="inline-flex items-center gap-1 text-[9px] text-white/35"><Clock3 className="size-3" />{countdown(item.endsAt, now)}</span><Flame className="size-4 text-red-300" /></div><h3 className="mt-3 truncate text-sm font-bold">{item.productName}</h3><p className="mt-1 truncate text-[10px] text-white/35">{item.packageLabel}</p><div className="mt-4"><span className="block text-[10px] text-white/28 line-through">{formatRupiah(item.basePrice)}</span><strong className="mt-1 block text-base text-[#d8ff8d]">{formatRupiah(item.salePrice)}</strong></div></div></Link>)}</div> : <EmptyPromo text="Belum ada flash sale yang sedang aktif." />}</TabsContent>
        <TabsContent value="voucher" className="mt-5">{shownVouchers.length ? <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{shownVouchers.map((item) => <article key={item.id} className="rounded-2xl border border-white/[0.09] bg-[#0d1019] p-4"><div className="flex items-center justify-between"><span className="grid size-9 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#b9ff35]"><Percent className="size-4" /></span><span className="text-[9px] text-white/30">hingga {new Date(item.endsAt).toLocaleDateString("id-ID", { day: "numeric", month: "short" })}</span></div><h3 className="mt-4 text-sm font-bold">{item.name}</h3><p className="mt-2 min-h-10 text-[10px] leading-5 text-white/38">{item.description || `Minimum transaksi ${formatRupiah(item.minPurchase)}`}</p><button type="button" onClick={() => void navigator.clipboard.writeText(item.code)} className="mt-4 flex w-full items-center justify-between rounded-xl border border-dashed border-[#b9ff35]/35 bg-[#b9ff35]/[0.06] px-3 py-2.5 font-mono text-xs font-bold text-[#d8ff8d]"><span>{item.code}</span><Copy className="size-3.5" /></button></article>)}</div> : <EmptyPromo text="Belum ada voucher diskon yang sedang aktif." />}</TabsContent>
      </Tabs>
    </section>
  );
}

function EmptyPromo({ text }: { text: string }) {
  return <div className="rounded-2xl border border-dashed border-white/10 bg-white/[0.015] px-5 py-10 text-center"><TicketPercent className="mx-auto size-6 text-white/20" /><p className="mt-3 text-xs text-white/36">{text}</p><Button asChild variant="outline" size="sm" className="mt-4 rounded-lg border-white/10 bg-white/[0.03] text-white"><Link href="/catalog">Buka katalog</Link></Button></div>;
}

function countdown(endsAt: string, now: number) {
  if (!now) return "Berlangsung";
  const seconds = Math.max(0, Math.floor((new Date(endsAt).getTime() - now) / 1000));
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  if (days > 0) return `${days}h ${hours}j`;
  return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;
}
