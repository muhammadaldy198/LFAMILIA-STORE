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
  const mobileImageUrl = banner.mobileImageUrl || mobileFallback(banner.imageUrl);
  return (
    <section className="mx-auto max-w-7xl px-4 pb-2 pt-4 sm:px-6 sm:pb-3 sm:pt-6 lg:px-8">
      <div className="group relative h-[148px] overflow-hidden rounded-[20px] border border-white/10 bg-[#10131b] sm:h-auto sm:aspect-[3.4/1] sm:rounded-[26px] lg:aspect-[4/1]">
        <picture>
          <source media="(max-width: 639px)" srcSet={mobileImageUrl} />
          <img src={banner.imageUrl} alt={banner.title} className="absolute inset-0 size-full object-cover transition duration-700 group-hover:scale-[1.02]" />
        </picture>
        <div className="absolute inset-0 bg-gradient-to-r from-black/90 via-black/48 to-black/5" />
        <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/55 to-transparent" />
        <div className="relative z-10 flex h-full max-w-2xl flex-col justify-end p-4 sm:p-7 lg:p-9">
          <h1 className="max-w-[78%] text-balance text-lg font-black leading-tight tracking-[-0.035em] sm:max-w-xl sm:text-3xl lg:text-4xl">{banner.title}</h1>
          {banner.subtitle && <p className="mt-2 hidden line-clamp-2 max-w-xl text-xs leading-5 text-white/66 sm:block lg:text-sm">{banner.subtitle}</p>}
          <Button asChild className="mt-3 h-8 w-fit rounded-lg bg-[#b9ff35] px-3 text-[10px] font-black text-[#091006] hover:bg-[#d0ff75] sm:mt-4 sm:h-10 sm:rounded-xl sm:px-4 sm:text-xs"><Link href={banner.ctaHref}>{banner.ctaLabel}<ArrowRight className="ml-1.5 size-3.5 sm:size-4" /></Link></Button>
        </div>
        {banners.length > 1 && <>
          <button type="button" aria-label="Banner sebelumnya" onClick={() => setActive((value) => (value - 1 + banners.length) % banners.length)} className="absolute left-2 top-1/2 z-20 grid size-7 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/70 backdrop-blur hover:text-white sm:left-3 sm:size-9"><ChevronLeft className="size-3.5 sm:size-4" /></button>
          <button type="button" aria-label="Banner berikutnya" onClick={() => setActive((value) => (value + 1) % banners.length)} className="absolute right-2 top-1/2 z-20 grid size-7 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/70 backdrop-blur hover:text-white sm:right-3 sm:size-9"><ChevronRight className="size-3.5 sm:size-4" /></button>
          <div className="absolute bottom-2 right-3 z-20 flex gap-1 sm:bottom-3 sm:right-4 sm:gap-1.5">{banners.map((item, index) => <button key={item.id ?? index} type="button" onClick={() => setActive(index)} aria-label={`Tampilkan banner ${index + 1}`} className={`h-1 rounded-full transition-all sm:h-1.5 ${index === active ? "w-5 bg-[#b9ff35] sm:w-7" : "w-1.5 bg-white/35 sm:w-2"}`} />)}</div>
        </>}
      </div>
    </section>
  );
}

function mobileFallback(imageUrl: string) {
  return imageUrl.replace(/-banner(\.[a-z0-9]+)(?:\?.*)?$/i, "-cover$1");
}
