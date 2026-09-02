"use client";

import Link from "next/link";
import { ArrowRight, Flame } from "lucide-react";
import { ProductCard } from "@/components/product-card";
import { useStoreProducts } from "@/hooks/use-store-products";

export function PopularNow() {
  const { products } = useStoreProducts();
  const shown = [...products]
    .sort(
      (left, right) =>
        Number(Boolean(right.popular)) - Number(Boolean(left.popular)) ||
        (right.ratingCount ?? 0) - (left.ratingCount ?? 0),
    )
    .slice(0, 6);

  if (!shown.length) return null;

  return (
    <section className="mx-auto max-w-7xl px-4 py-[30px] sm:px-6 sm:py-[38px] lg:px-8">
      <div className="flex items-end justify-between gap-[10px]">
        <div>
          <p className="eyebrow flex items-center gap-[6px]">
            <Flame className="size-[14px] text-orange-400" />
            Pilihan pelanggan
          </p>
          <h2 className="text-[22px] font-black leading-tight tracking-[-0.035em] sm:text-[28px]">
            Populer Sekarang!
          </h2>
          <p className="mt-[6px] text-[11px] text-white/40 sm:text-xs">
            Produk yang paling sering dicari di LFAMILIA STORE.
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

      <div className="mt-[16px] grid grid-cols-3 gap-x-[10px] gap-y-[18px] sm:grid-cols-4 sm:gap-x-[14px] sm:gap-y-[20px] lg:grid-cols-6">
        {shown.map((product) => (
          <ProductCard key={product.slug} product={product} />
        ))}
      </div>
    </section>
  );
}
