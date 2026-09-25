"use client";

import Link from "next/link";
import { ArrowRight, Flame } from "lucide-react";
import { ProductArtwork } from "@/components/product-artwork";
import { useStoreProducts } from "@/hooks/use-store-products";

export function PopularNow() {
  const { products } = useStoreProducts();
  const shown = [...products]
    .sort(
      (left, right) =>
        Number(Boolean(right.popular)) - Number(Boolean(left.popular)) ||
        (right.ratingCount ?? 0) - (left.ratingCount ?? 0),
    )
    .slice(0, 8);

  if (!shown.length) return null;

  return (
    <section className="mx-auto max-w-7xl px-4 pb-[10px] pt-[22px] sm:px-6 sm:pb-[14px] sm:pt-[28px] lg:px-8">
      <div className="flex items-end justify-between gap-[10px]">
        <div>
          <p className="eyebrow flex items-center gap-[6px]">
            <Flame className="size-[14px] text-orange-400" />
            Populer sekarang!
          </p>
          <p className="mt-[4px] text-[10px] text-white/55 sm:text-[11px]">
            Berikut adalah beberapa produk yang paling populer saat ini.
          </p>
        </div>
        <Link
          href="/catalog"
          className="hidden items-center gap-[6px] text-[10px] font-bold text-[#cfff72] sm:flex"
        >
          Semua produk
          <ArrowRight className="size-[13px]" />
        </Link>
      </div>

      <div className="mt-[11px] grid grid-cols-2 gap-[8px] sm:grid-cols-3 sm:gap-[10px] lg:grid-cols-4">
        {shown.map((product) => (
          <Link
            key={product.slug}
            href={`/checkout?product=${product.slug}`}
            className={`group relative flex min-w-0 items-center gap-[8px] overflow-hidden rounded-[20px] border border-white/10 bg-gradient-to-br p-[8px] shadow-[0_12px_24px_-22px_rgba(0,0,0,0.92)] transition duration-300 hover:-translate-y-0.5 hover:border-white/30 sm:gap-[10px] sm:rounded-[22px] sm:p-[10px] ${product.accent}`}
          >
            <span
              className="pointer-events-none absolute inset-0 bg-[linear-gradient(132deg,transparent_46%,rgba(255,255,255,0.14)_46%,transparent_72%)] opacity-80"
              aria-hidden="true"
            />
            <span className="relative z-10 block size-[54px] shrink-0 overflow-hidden rounded-[13px] border border-white/15 bg-black/10 shadow-[0_8px_16px_-12px_rgba(0,0,0,0.9)] sm:size-[68px] sm:rounded-[15px]">
              <ProductArtwork product={product} compact />
            </span>
            <span className="relative z-10 min-w-0 pr-1">
              <span className="block text-[11px] font-black leading-[15px] tracking-tight text-white sm:text-[13px] sm:leading-[18px]">
                {product.name}
              </span>
              <span className="mt-[2px] block truncate text-[9px] font-medium text-white/70 sm:mt-[3px] sm:text-[11px]">
                {product.publisher}
              </span>
            </span>
          </Link>
        ))}
      </div>
    </section>
  );
}
