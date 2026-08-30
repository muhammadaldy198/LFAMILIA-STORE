"use client";

import Link from "next/link";
import { ArrowRight, Flame, Star } from "lucide-react";
import { ProductArtwork } from "@/components/product-artwork";
import { useStoreProducts } from "@/hooks/use-store-products";

export function PopularNow() {
  const { products } = useStoreProducts();
  const shown = [...products].sort((left, right) => Number(Boolean(right.popular)) - Number(Boolean(left.popular)) || (right.ratingCount ?? 0) - (left.ratingCount ?? 0)).slice(0, 8);
  if (!shown.length) return null;
  return (
    <section className="mx-auto max-w-7xl px-4 py-11 sm:px-6 sm:py-14 lg:px-8">
      <div className="flex items-end justify-between gap-4"><div><p className="eyebrow flex items-center gap-2"><Flame className="size-4 text-orange-400" />Pilihan pelanggan</p><h2 className="section-title">Populer Sekarang!</h2><p className="mt-2 text-xs text-white/40">Produk yang paling sering dicari di LFAMILIA STORE.</p></div><Link href="/catalog" className="hidden items-center gap-2 text-xs font-bold text-[#cfff72] sm:flex">Semua produk<ArrowRight className="size-4" /></Link></div>
      <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{shown.map((product) => <Link key={product.slug} href={`/checkout?product=${product.slug}`} className="group flex min-w-0 items-center gap-3 overflow-hidden rounded-[22px] border border-white/[0.09] bg-gradient-to-br from-white/[0.055] to-white/[0.018] p-3 transition hover:-translate-y-0.5 hover:border-[#b9ff35]/35"><span className="block size-20 shrink-0 overflow-hidden rounded-2xl"><ProductArtwork product={product} compact /></span><div className="min-w-0"><h3 className="truncate text-sm font-black">{product.name}</h3><p className="mt-1 truncate text-[10px] text-white/40">{product.publisher}</p><span className="mt-3 inline-flex items-center gap-1 text-[10px] font-bold text-amber-300"><Star className="size-3 fill-current" />{product.ratingCount ? `${product.ratingAverage?.toFixed(1)} (${product.ratingCount})` : "Belum ada rating"}</span></div></Link>)}</div>
    </section>
  );
}
