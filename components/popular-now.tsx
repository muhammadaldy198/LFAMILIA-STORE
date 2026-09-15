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
    <section className="mx-auto max-w-7xl px-4 py-[30px] sm:px-6 sm:py-[38px] lg:px-8">
      <div className="flex items-end justify-between gap-[10px]">
        <div>
          <p className="eyebrow flex items-center gap-[6px]">
            <Flame className="size-[14px] text-orange-400" />
            Populer sekarang!
          </p>
          <p className="mt-[6px] text-[11px] text-white/55 sm:text-xs">
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

      <div className="mt-[16px] grid grid-cols-2 gap-[10px] sm:grid-cols-3 sm:gap-[14px] lg:grid-cols-4">
        {shown.map((product) => (
          <Link
            key={product.slug}
            href={`/checkout?product=${product.slug}`}
            className={`group relative flex min-w-0 items-center gap-[10px] overflow-hidden rounded-[18px] border border-white/10 bg-gradient-to-br p-[10px] shadow-[0_14px_28px_-22px_rgba(0,0,0,0.92)] transition duration-300 hover:-translate-y-0.5 hover:border-white/30 sm:gap-[14px] sm:rounded-[22px] sm:p-[14px] ${product.accent}`}
          >
            <span
              className="pointer-events-none absolute inset-0 bg-[linear-gradient(132deg,transparent_46%,rgba(255,255,255,0.14)_46%,transparent_72%)] opacity-80"
              aria-hidden="true"
            />
            <span className="relative z-10 block size-[64px] shrink-0 overflow-hidden rounded-[13px] border border-white/15 bg-black/10 shadow-[0_10px_20px_-12px_rgba(0,0,0,0.9)] sm:size-[84px] sm:rounded-[15px]">
              <ProductArtwork product={product} compact />
            </span>
            <span className="relative z-10 min-w-0 pr-1">
              <span className="block text-[12px] font-black leading-[17px] tracking-tight text-white sm:text-[15px] sm:leading-5">
                {product.name}
              </span>
              <span className="mt-[3px] block truncate text-[10px] font-medium text-white/75 sm:mt-1 sm:text-[13px]">
                {product.publisher}
              </span>
            </span>
          </Link>
        ))}
      </div>
    </section>
  );
}
