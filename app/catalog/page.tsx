"use client";

import { useMemo, useState } from "react";
import { Gamepad2, Grid3X3, Play, Search, Smartphone, Ticket, X } from "lucide-react";
import { Input } from "@/components/ui/input";
import { ProductCard } from "@/components/product-card";
import { StoreLayout } from "@/components/store-layout";
import { useStoreProducts } from "@/hooks/use-store-products";
import { useStorefront } from "@/hooks/use-storefront";
import type { ProductCategory } from "@/lib/store-data";

type Filter = "all" | ProductCategory;

export default function CatalogPage() {
  const { products, databaseReady } = useStoreProducts();
  const { categories } = useStorefront();
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");
  const filtered = useMemo(() => products.filter((product) => {
    const inCategory = filter === "all" || product.category === filter;
    const term = query.trim().toLowerCase();
    return inCategory && (!term || `${product.name} ${product.publisher}`.toLowerCase().includes(term));
  }), [filter, products, query]);

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <div className="max-w-2xl"><p className="eyebrow">Katalog produk</p><h1 className="section-title">Mau top up apa hari ini?</h1><p className="mt-4 text-sm leading-6 text-white/45 sm:text-base">Pilih produk, periksa informasi layanan, lalu lanjutkan pemesanan dengan aman.</p></div>

        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex gap-2 overflow-x-auto pb-1">
            {[
              { value: "all", label: "Semua", icon: Search },
              ...categories.filter((item) => item.isActive).map((item) => ({ value: item.slug, label: item.name, icon: categoryIcon(item.icon, item.slug) })),
            ].map(({ value, label, icon: Icon }) => (
              <button key={value} type="button" onClick={() => setFilter(value)} className={`inline-flex h-10 shrink-0 items-center gap-2 rounded-xl border px-4 text-xs font-bold transition ${filter === value ? "border-[#b9ff35] bg-[#b9ff35] text-[#091006]" : "border-white/10 bg-white/[0.025] text-white/50 hover:text-white"}`}><Icon className="size-4" />{label}</button>
            ))}
          </div>
          <div className="relative w-full sm:max-w-sm"><Search className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-white/30" /><Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari Mobile Legends, Steam..." className="h-11 rounded-xl border-white/10 bg-white/[0.035] pl-10 pr-10 text-sm text-white placeholder:text-white/25" />{query && <button type="button" onClick={() => setQuery("")} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/30 hover:text-white" aria-label="Hapus pencarian"><X className="size-4" /></button>}</div>
        </div>

        <div className="mt-8 flex items-center justify-between border-b border-white/[0.07] pb-4 text-xs"><span className="font-semibold text-white/55">{filtered.length} produk ditemukan</span><span className="text-white/28">{databaseReady ? "Katalog terbaru" : "Memuat katalog"}</span></div>
        {filtered.length ? (
          <div className="mt-6 grid grid-cols-3 gap-x-2.5 gap-y-5 sm:grid-cols-4 sm:gap-4 lg:grid-cols-6">{filtered.map((product) => <ProductCard key={product.slug} product={product} />)}</div>
        ) : (
          <div className="panel mt-6 px-6 py-16 text-center"><Search className="mx-auto size-8 text-white/20" /><h2 className="mt-4 font-bold">Produk tidak ditemukan</h2><p className="mt-2 text-sm text-white/38">Coba kata pencarian atau kategori lain.</p></div>
        )}
      </main>
    </StoreLayout>
  );
}

function categoryIcon(icon: string, slug: string) {
  if (icon === "gamepad" || slug === "game") return Gamepad2;
  if (icon === "ticket" || slug === "voucher") return Ticket;
  if (icon === "play" || slug === "entertainment") return Play;
  if (icon === "smartphone" || slug === "pulsa") return Smartphone;
  return Grid3X3;
}
