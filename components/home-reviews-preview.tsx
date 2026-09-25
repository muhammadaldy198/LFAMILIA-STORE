"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { BadgeCheck, Star } from "lucide-react";
import type { ProductReview } from "@/lib/server/reviews";

function productTitle(slug: string) {
  return slug.split("-").map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(" ");
}

export function HomeReviewsPreview() {
  const [reviews, setReviews] = useState<ProductReview[]>([]);
  const [loadError, setLoadError] = useState("");
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let active = true;
    void fetch("/api/reviews?featured=1")
      .then(async (response) => {
        const data = await response.json().catch(() => ({})) as { reviews?: ProductReview[]; error?: string };
        if (!response.ok) throw new Error(data.error || "Ulasan gagal dimuat.");
        return data;
      })
      .then((data) => {
        if (!active) return;
        setReviews(data.reviews ?? []);
        setLoadError("");
      })
      .catch((reason) => {
        if (active) setLoadError(reason instanceof Error ? reason.message : "Ulasan gagal dimuat.");
      });
    return () => { active = false; };
  }, [reloadKey]);

  if (!reviews.length) {
    if (!loadError) return null;
    return <section className="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8"><div className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.025] px-4 py-3 text-[10px] text-white/40"><span>{loadError}</span><button type="button" onClick={() => setReloadKey((value) => value + 1)} className="font-bold text-[#d8ff8d]">Muat ulang</button></div></section>;
  }

  return (
    <section className="mx-auto max-w-7xl px-4 py-[34px] sm:px-6 sm:py-[44px] lg:px-8">
      <div className="flex items-end justify-between gap-4">
        <div>
          <p className="eyebrow">Cerita pelanggan</p>
          <h2 className="text-[22px] font-black tracking-[-0.035em] sm:text-[28px]">Ulasan transaksi terbaru</h2>
          <p className="mt-[7px] max-w-2xl text-[11px] leading-[1.55] text-white/42 sm:text-xs">Ulasan hanya berasal dari pelanggan yang telah menyelesaikan pembelian.</p>
        </div>
        <Link href="/#produk" className="hidden text-[10px] font-bold text-[#d8ff8d] sm:inline">Lihat produk</Link>
      </div>
      <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {reviews.map((review) => (
          <Link key={review.id} href={`/checkout?product=${encodeURIComponent(review.productSlug)}`} className="rounded-[11px] border border-white/[0.08] bg-[#0d1019] p-4 transition hover:border-[#b9ff35]/35 hover:bg-white/[0.035]">
            <div className="flex items-start justify-between gap-3">
              <div>
                <strong className="text-[11px] text-white/90">{review.customerName}</strong>
                <span className="mt-1 flex items-center gap-1 text-[8px] font-bold text-[#d8ff8d]"><BadgeCheck className="size-3" />Pembelian terverifikasi</span>
              </div>
              <span className="flex items-center gap-1 text-[11px] font-black text-amber-300"><Star className="size-3.5 fill-current" />{review.rating}</span>
            </div>
            {review.title && <h3 className="mt-4 text-[11px] font-bold">{review.title}</h3>}
            <p className="mt-2 line-clamp-3 text-[10px] leading-[1.55] text-white/48">{review.body}</p>
            <span className="mt-3 block text-[8px] font-semibold text-white/30">{productTitle(review.productSlug)}</span>
          </Link>
        ))}
      </div>
    </section>
  );
}
