import Link from "next/link";
import { Clock3, Star, Zap } from "lucide-react";
import type { StoreProduct } from "@/lib/store-data";
import { formatRupiah } from "@/lib/store-data";
import { ProductArtwork } from "@/components/product-artwork";

export function ProductCard({ product }: { product: StoreProduct }) {
  const lowest = Math.min(...product.packages.map((item) => item.price));
  return (
    <Link href={`/checkout?product=${product.slug}`} className="group block min-w-0 transition duration-300 hover:-translate-y-1">
      <div className="relative aspect-[3/4] overflow-hidden rounded-[16px] border border-white/[0.1] bg-[#0d1019] shadow-[0_14px_32px_-22px_rgba(0,0,0,0.9)] transition group-hover:border-[#b9ff35]/40 group-hover:shadow-[0_20px_45px_-24px_rgba(185,255,53,0.32)] sm:rounded-[20px]">
        <ProductArtwork product={product} />
        <div className="absolute inset-0 z-20 bg-gradient-to-t from-black via-black/5 to-black/10" />
        <span className="absolute right-2 top-2 z-30 rounded-full border border-white/20 bg-black/45 px-1.5 py-0.5 text-[7px] font-extrabold uppercase tracking-wider text-white/85 backdrop-blur sm:right-3 sm:top-3 sm:px-2 sm:py-1 sm:text-[8px]">{product.category === "game" ? "Game" : product.category === "voucher" ? "Voucher" : product.category}</span>
        <div className="absolute inset-x-0 bottom-0 z-30 p-2.5 sm:p-3.5"><h3 className="line-clamp-2 text-[11px] font-black leading-tight tracking-tight text-white sm:text-sm">{product.name}</h3><p className="mt-1 truncate text-[8px] text-white/55 sm:text-[10px]">{product.publisher}</p></div>
      </div>
      <div className="px-0.5 pt-2 sm:px-1 sm:pt-2.5">
        <strong className="block truncate text-[9px] font-black text-[#d8ff8d] sm:text-xs">{formatRupiah(lowest)}</strong>
        <div className="mt-1 flex min-w-0 items-center justify-between gap-1 text-[7px] sm:text-[9px]"><span className={`inline-flex min-w-0 items-center gap-1 truncate ${product.ratingCount ? "text-amber-300" : "text-white/28"}`}><Star className={`size-2.5 shrink-0 sm:size-3 ${product.ratingCount ? "fill-current" : ""}`} />{product.ratingCount ? `${Number(product.ratingAverage || 0).toFixed(1)} (${product.ratingCount})` : "Belum dinilai"}</span>{product.fulfillmentType === "manual" ? <Clock3 className="size-2.5 shrink-0 text-amber-300/70 sm:size-3" /> : <Zap className="size-2.5 shrink-0 text-[#b9ff35] sm:size-3" />}</div>
      </div>
    </Link>
  );
}
