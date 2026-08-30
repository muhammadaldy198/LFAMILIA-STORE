"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowRight, ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import type { HomeBannerRecord } from "@/lib/server/content";

export function HomeBannerCarousel() {
  const [banners, setBanners] = useState<HomeBannerRecord[]>([]);
  const [active, setActive] = useState(0);

  useEffect(() => {
    let mounted = true;
    void fetch("/api/home-content", { cache: "no-store" }).then((response) => response.json()).then((data: { banners?: HomeBannerRecord[] }) => {
      if (mounted) setBanners(data.banners ?? []);
    }).catch(() => undefined);
    return () => { mounted = false; };
  }, []);

  useEffect(() => {
    if (banners.length < 2) return;
    const timer = window.setInterval(() => setActive((value) => (value + 1) % banners.length), 6500);
    return () => window.clearInterval(timer);
  }, [banners.length]);

  if (!banners.length) return null;
  const banner = banners[Math.min(active, banners.length - 1)];
  return (
    <section className="mx-auto max-w-7xl px-4 pb-3 pt-5 sm:px-6 sm:pt-7 lg:px-8">
      <div className="group relative aspect-[16/7] min-h-52 overflow-hidden rounded-[26px] border border-white/10 bg-[#10131b] sm:min-h-72">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={banner.imageUrl} alt={banner.title} className="absolute inset-0 size-full object-cover transition duration-700 group-hover:scale-[1.02]" />
        <div className="absolute inset-0 bg-gradient-to-r from-black/90 via-black/48 to-black/5" />
        <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/55 to-transparent" />
        <div className="relative z-10 flex h-full max-w-2xl flex-col justify-end p-6 sm:p-10 lg:p-12">
          <h1 className="text-balance text-2xl font-black leading-tight tracking-[-0.035em] sm:text-4xl lg:text-5xl">{banner.title}</h1>
          {banner.subtitle && <p className="mt-3 line-clamp-2 max-w-xl text-xs leading-6 text-white/66 sm:text-sm">{banner.subtitle}</p>}
          <Button asChild className="mt-5 h-10 w-fit rounded-xl bg-[#b9ff35] px-4 text-xs font-black text-[#091006] hover:bg-[#d0ff75] sm:h-11"><Link href={banner.ctaHref}>{banner.ctaLabel}<ArrowRight className="ml-2 size-4" /></Link></Button>
        </div>
        {banners.length > 1 && <>
          <button type="button" aria-label="Banner sebelumnya" onClick={() => setActive((value) => (value - 1 + banners.length) % banners.length)} className="absolute left-3 top-1/2 z-20 grid size-9 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/70 backdrop-blur hover:text-white"><ChevronLeft className="size-4" /></button>
          <button type="button" aria-label="Banner berikutnya" onClick={() => setActive((value) => (value + 1) % banners.length)} className="absolute right-3 top-1/2 z-20 grid size-9 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/70 backdrop-blur hover:text-white"><ChevronRight className="size-4" /></button>
          <div className="absolute bottom-3 right-4 z-20 flex gap-1.5">{banners.map((item, index) => <button key={item.id ?? index} type="button" onClick={() => setActive(index)} aria-label={`Tampilkan banner ${index + 1}`} className={`h-1.5 rounded-full transition-all ${index === active ? "w-7 bg-[#b9ff35]" : "w-2 bg-white/35"}`} />)}</div>
        </>}
      </div>
    </section>
  );
}
