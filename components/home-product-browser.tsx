"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { ArrowRight, Gamepad2, Grid3X3, Play, Search, Smartphone, Ticket, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ProductCard } from "@/components/product-card";
import { useStoreProducts } from "@/hooks/use-store-products";
import { useStorefront } from "@/hooks/use-storefront";
import type { ProductCategory } from "@/lib/store-data";

type Filter = "all" | ProductCategory;

export function HomeProductBrowser() {
  const { products, databaseReady } = useStoreProducts();
  const { categories } = useStorefront();
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");
  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return products.filter((product) => (filter === "all" || product.category === filter)
      && (!term || `${product.name} ${product.publisher}`.toLowerCase().includes(term))).slice(0, 12);
  }, [filter, products, query]);

  return (
    <section id="produk" className="mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
      <div className="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
        <div><p className="eyebrow">Otomatis & manual</p><h2 className="section-title">Pilih game favoritmu</h2><p className="mt-3 text-sm text-white/42">Produk otomatis diproses provider 24 jam; produk khusus masuk antrean admin.</p></div>
        <div className="relative w-full md:max-w-sm"><Search className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-white/30" /><Input id="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari Mobile Legends, Free Fire..." className="h-12 rounded-2xl border-white/10 bg-[#10131b] pl-10 pr-10 text-sm text-white placeholder:text-white/25" />{query && <button type="button" onClick={() => setQuery("")} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Hapus pencarian"><X className="size-4" /></button>}</div>
      </div>
      <div className="mt-6 flex items-center justify-between gap-4 border-b border-white/[0.07] pb-4">
        <div className="flex gap-2 overflow-x-auto pb-1">
          {[
            { value: "all", label: "Semua", icon: Search },
            ...categories.filter((item) => item.isActive).map((item) => ({ value: item.slug, label: item.name, icon: categoryIcon(item.icon, item.slug) })),
          ].map(({ value, label, icon: Icon }) => <button key={value} type="button" onClick={() => setFilter(value)} className={`inline-flex h-9 shrink-0 items-center gap-2 rounded-xl border px-3.5 text-[11px] font-bold transition ${filter === value ? "border-[#b9ff35] bg-[#b9ff35] text-[#091006]" : "border-white/10 bg-white/[0.025] text-white/45 hover:text-white"}`}><Icon className="size-3.5" />{label}</button>)}
        </div>
        <span className="hidden text-[10px] text-white/25 sm:block">{databaseReady ? "Katalog terbaru" : "Memuat katalog"}</span>
      </div>
      {visible.length ? <div className="mt-6 grid grid-cols-3 gap-x-2.5 gap-y-5 sm:grid-cols-4 sm:gap-4 lg:grid-cols-6">{visible.map((product) => <ProductCard key={product.slug} product={product} />)}</div> : <div className="panel mt-6 py-14 text-center"><Search className="mx-auto size-7 text-white/20" /><p className="mt-3 text-sm text-white/40">Produk tidak ditemukan.</p></div>}
      <div className="mt-8 flex justify-center"><Button asChild variant="outline" className="h-11 rounded-xl border-white/10 bg-white/[0.03] px-5 text-white hover:bg-white/[0.08] hover:text-white"><Link href="/catalog">Lihat semua produk <ArrowRight className="ml-2 size-4" /></Link></Button></div>
    </section>
  );
}

function categoryIcon(icon: string, slug: string) {
  if (icon === "gamepad" || slug === "game") return Gamepad2;
  if (icon === "ticket" || slug === "voucher") return Ticket;
  if (icon === "play" || slug === "entertainment") return Play;
  if (icon === "smartphone" || slug === "pulsa") return Smartphone;
  return Grid3X3;
}
