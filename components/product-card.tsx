import Link from "next/link";
import { Clock3, Star, Zap } from "lucide-react";
import type { StoreProduct } from "@/lib/store-data";
import { formatRupiah } from "@/lib/store-data";
import { ProductArtwork } from "@/components/product-artwork";

export function ProductCard({ product }: { product: StoreProduct }) {
  const lowest = Math.min(...product.packages.map((item) => item.price));
  return (
    <Link href={`/checkout?product=${product.slug}`} className="group block min-w-0 transition duration-300 hover:-translate-y-1">
      <div className="relative aspect-[2/3] overflow-hidden rounded-[16px] border border-white/[0.1] bg-[#0d1019] shadow-[0_14px_32px_-22px_rgba(0,0,0,0.9)] transition group-hover:border-[#b9ff35]/40 group-hover:shadow-[0_20px_45px_-24px_rgba(185,255,53,0.32)] sm:rounded-[20px]">
        <ProductArtwork product={product} />
        <div className="pointer-events-none absolute inset-0 z-20 bg-gradient-to-t from-black/20 via-transparent to-white/[0.04]" />
      </div>
      <div className="px-0.5 pt-2 sm:px-1 sm:pt-2.5">
        <h3 className="truncate text-[9px] font-black tracking-tight text-white/90 sm:text-xs">{product.name}</h3>
        <strong className="mt-1 block truncate text-[9px] font-black text-[#d8ff8d] sm:text-xs">{formatRupiah(lowest)}</strong>
        <div className="mt-1 flex min-w-0 items-center justify-between gap-1 text-[7px] sm:text-[9px]"><span className={`inline-flex min-w-0 items-center gap-1 truncate ${product.ratingCount ? "text-amber-300" : "text-white/28"}`}><Star className={`size-2.5 shrink-0 sm:size-3 ${product.ratingCount ? "fill-current" : ""}`} />{product.ratingCount ? `${Number(product.ratingAverage || 0).toFixed(1)} (${product.ratingCount})` : "Belum dinilai"}</span>{product.fulfillmentType === "manual" ? <Clock3 className="size-2.5 shrink-0 text-amber-300/70 sm:size-3" /> : <Zap className="size-2.5 shrink-0 text-[#b9ff35] sm:size-3" />}</div>
      </div>
    </Link>
  );
}
