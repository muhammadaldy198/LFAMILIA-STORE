"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import {
  ArrowRight,
  Gamepad2,
  Grid3X3,
  Play,
  Search,
  Smartphone,
  Ticket,
  X,
  Zap,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ProductCard } from "@/components/product-card";
import { useStoreProducts } from "@/hooks/use-store-products";
import { useStorefront } from "@/hooks/use-storefront";
import { normalizeProductCategorySlug } from "@/lib/product-categories";
import type { ProductCategory } from "@/lib/store-data";

type Filter = "all" | ProductCategory;

export function HomeProductBrowser() {
  const { products, databaseReady, loading } = useStoreProducts();
  const { categories } = useStorefront();
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return products
      .filter(
        (product) =>
          (filter === "all" || normalizeProductCategorySlug(product.category) === filter) &&
          (!term ||
            `${product.name} ${product.publisher}`.toLowerCase().includes(term)),
      )
      .slice(0, 12);
  }, [filter, products, query]);

  return (
    <section id="produk" className="mx-auto max-w-7xl px-4 pb-[34px] pt-[12px] sm:px-6 sm:pb-[44px] sm:pt-[16px] lg:px-8">
      <div className="flex flex-col gap-[14px] md:flex-row md:items-end md:justify-between">
        <div>
          <p className="eyebrow">Otomatis & manual</p>
          <h2 className="text-[22px] font-black leading-tight tracking-[-0.035em] sm:text-[28px]">
            Pilih produk favoritmu
          </h2>
          <p className="mt-[7px] max-w-2xl text-[11px] leading-[1.5] text-white/42 sm:text-xs">
            Top up game, voucher, hiburan, pulsa, dan PLN dalam satu katalog.
          </p>
        </div>

        <div className="relative w-full md:max-w-[340px]">
          <Search className="pointer-events-none absolute left-[11px] top-1/2 size-[14px] -translate-y-1/2 text-white/30" />
          <Input
            id="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Cari game, voucher, pulsa, PLN..."
            className="h-[36px] rounded-[8px] border-white/10 bg-[#10131b] pl-[34px] pr-[34px] text-[11px] text-white placeholder:text-white/25"
          />
          {query && (
            <button
              type="button"
              onClick={() => setQuery("")}
              className="absolute right-[10px] top-1/2 -translate-y-1/2 text-white/35 hover:text-white"
              aria-label="Hapus pencarian"
            >
              <X className="size-[14px]" />
            </button>
          )}
        </div>
      </div>

      <div className="mt-[15px] flex items-center justify-between gap-[10px] border-b border-white/[0.07] pb-[10px]">
        <div className="flex gap-[6px] overflow-x-auto pb-[2px] scrollbar-none">
          {[
            { value: "all", label: "Semua", icon: Grid3X3 },
            ...categories
              .filter((item) => item.isActive)
              .map((item) => ({
                value: item.slug,
                label: item.name,
                icon: categoryIcon(item.icon, item.slug),
              })),
          ].map(({ value, label, icon: Icon }) => (
            <button
              key={value}
              type="button"
              onClick={() => setFilter(value)}
              className={`inline-flex h-[30px] shrink-0 items-center gap-[5px] rounded-[7px] border px-[10px] text-[10px] font-bold transition ${
                filter === value
                  ? "border-[#b9ff35] bg-[#b9ff35] text-[#091006]"
                  : "border-white/10 bg-white/[0.025] text-white/48 hover:text-white"
              }`}
            >
              <Icon className="size-[12px]" />
              {label}
            </button>
          ))}
        </div>
        <span className="hidden shrink-0 text-[9px] text-white/25 sm:block">
          {loading ? "Memuat katalog" : databaseReady ? "Katalog terbaru" : "Katalog tidak tersedia"}
        </span>
      </div>

      {visible.length ? (
        <div className="mt-[16px] grid grid-cols-3 gap-x-[10px] gap-y-[18px] sm:grid-cols-4 sm:gap-x-[14px] sm:gap-y-[20px] lg:grid-cols-6">
          {visible.map((product) => (
            <ProductCard key={product.slug} product={product} />
          ))}
        </div>
      ) : (
        <div className="panel mt-[16px] py-[34px] text-center">
          <Search className="mx-auto size-6 text-white/20" />
          <p className="mt-[8px] text-xs text-white/40">Produk tidak ditemukan.</p>
        </div>
      )}

      <div className="mt-[22px] flex justify-center">
        <Button
          asChild
          variant="outline"
          className="h-[34px] rounded-[8px] border-white/10 bg-white/[0.03] px-[14px] text-[10px] font-bold text-white hover:bg-white/[0.08] hover:text-white"
        >
          <Link href="/catalog">
            Lihat semua produk
            <ArrowRight className="ml-1.5 size-[13px]" />
          </Link>
        </Button>
      </div>
    </section>
  );
}

function categoryIcon(icon: string, slug: string) {
  if (icon === "gamepad" || slug === "game") return Gamepad2;
  if (icon === "ticket" || slug === "voucher") return Ticket;
  if (icon === "play" || slug === "entertainment") return Play;
  if (icon === "smartphone" || slug === "pulsa") return Smartphone;
  if (icon === "zap" || slug === "pln") return Zap;
  return Grid3X3;
}
