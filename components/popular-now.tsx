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
    <section className="mx-auto max-w-7xl px-4 py-11 sm:px-6 sm:py-14 lg:px-8">
      <div className="flex items-end justify-between gap-4">
        <div>
          <p className="eyebrow flex items-center gap-2">
            <Flame className="size-4 text-orange-400" />
            Pilihan pelanggan
          </p>
          <h2 className="section-title">Populer Sekarang!</h2>
          <p className="mt-2 text-xs text-white/40">
            Produk yang paling sering dicari di LFAMILIA STORE.
          </p>
        </div>
        <Link
          href="/catalog"
          className="hidden items-center gap-2 text-xs font-bold text-[#cfff72] sm:flex"
        >
          Semua produk
          <ArrowRight className="size-4" />
        </Link>
      </div>

      <div className="mt-6 grid grid-cols-3 gap-x-2.5 gap-y-5 sm:grid-cols-4 sm:gap-4 lg:grid-cols-6">
        {shown.map((product) => (
          <ProductCard key={product.slug} product={product} />
        ))}
      </div>
    </section>
  );
}
