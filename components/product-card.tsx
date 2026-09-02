import Link from "next/link";
import { Clock3, Star, Zap } from "lucide-react";
import type { StoreProduct } from "@/lib/store-data";
import { formatRupiah } from "@/lib/store-data";
import { ProductArtwork } from "@/components/product-artwork";

export function ProductCard({ product }: { product: StoreProduct }) {
  const lowest = Math.min(...product.packages.map((item) => item.price));

  return (
    <Link
      href={`/checkout?product=${product.slug}`}
      className="group block min-w-0 transition duration-200 hover:-translate-y-0.5"
    >
      <div className="relative aspect-[2/3] overflow-hidden rounded-[10px] border border-white/[0.1] bg-[#0d1019] shadow-[0_10px_24px_-18px_rgba(0,0,0,0.9)] transition group-hover:border-[#b9ff35]/40 group-hover:shadow-[0_14px_28px_-20px_rgba(185,255,53,0.3)] sm:rounded-[12px]">
        <ProductArtwork product={product} />
        <div className="pointer-events-none absolute inset-0 z-20 bg-gradient-to-t from-black/18 via-transparent to-white/[0.035]" />
        <span className="absolute right-[6px] top-[6px] z-30 grid size-[20px] place-items-center rounded-full border border-white/10 bg-black/55 backdrop-blur-sm">
          {product.fulfillmentType === "manual" ? (
            <Clock3 className="size-[10px] text-amber-300" />
          ) : (
            <Zap className="size-[10px] text-[#b9ff35]" />
          )}
        </span>
      </div>

      <div className="px-[2px] pt-[7px] sm:pt-[8px]">
        <h3 className="truncate text-[11px] font-black leading-tight tracking-[-0.015em] text-white/92 sm:text-[12px]">
          {product.name}
        </h3>
        <p className="mt-[2px] truncate text-[8px] font-medium text-white/34 sm:text-[9px]">
          {product.publisher}
        </p>
        <strong className="mt-[4px] block truncate text-[10px] font-black leading-none text-[#d8ff8d] sm:text-[11px]">
          {formatRupiah(lowest)}
        </strong>
        <div className="mt-[5px] flex min-w-0 items-center gap-1 text-[8px] text-white/32 sm:text-[9px]">
          <Star
            className={`size-[10px] shrink-0 ${
              product.ratingCount ? "fill-current text-amber-300" : "text-white/24"
            }`}
          />
          <span className="truncate">
            {product.ratingCount
              ? `${Number(product.ratingAverage || 0).toFixed(1)} (${product.ratingCount})`
              : "Belum dinilai"}
          </span>
        </div>
      </div>
    </Link>
  );
}
