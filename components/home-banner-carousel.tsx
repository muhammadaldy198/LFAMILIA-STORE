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
    void fetch("/api/home-content", { cache: "no-store" })
      .then((response) => response.json())
      .then((data: { banners?: HomeBannerRecord[] }) => {
        if (mounted) setBanners(data.banners ?? []);
      })
      .catch(() => undefined);
    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (banners.length < 2) return;
    const timer = window.setInterval(
      () => setActive((value) => (value + 1) % banners.length),
      6500,
    );
    return () => window.clearInterval(timer);
  }, [banners.length]);

  if (!banners.length) return null;

  const banner = banners[Math.min(active, banners.length - 1)];
  const mobileImageUrl = banner.mobileImageUrl || mobileFallback(banner.imageUrl);

  return (
    <section className="mx-auto max-w-7xl px-4 pb-1 pt-3 sm:px-6 sm:pb-2 sm:pt-4 lg:px-8">
      <div className="group relative h-[132px] overflow-hidden rounded-[12px] border border-white/10 bg-[#10131b] sm:h-[184px] sm:rounded-[14px] lg:h-[218px]">
        <picture>
          <source media="(max-width: 639px)" srcSet={mobileImageUrl} />
          <img
            src={banner.imageUrl}
            alt={banner.title}
            className="absolute inset-0 size-full object-cover transition duration-700 group-hover:scale-[1.015]"
          />
        </picture>

        <div className="absolute inset-0 bg-gradient-to-r from-black/82 via-black/38 to-transparent" />
        <div className="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-black/48 to-transparent" />

        <div className="relative z-10 flex h-full max-w-[72%] flex-col justify-end px-[14px] py-[12px] sm:max-w-xl sm:px-[22px] sm:py-[18px] lg:px-[26px] lg:py-[22px]">
          <h1 className="line-clamp-2 text-[15px] font-black leading-[1.12] tracking-[-0.025em] sm:text-[25px] lg:text-[30px]">
            {banner.title}
          </h1>
          {banner.subtitle && (
            <p className="mt-[6px] hidden line-clamp-2 max-w-lg text-[11px] leading-[1.45] text-white/62 sm:block lg:text-xs">
              {banner.subtitle}
            </p>
          )}
          <Button
            asChild
            className="mt-[8px] h-[28px] w-fit rounded-[7px] bg-[#b9ff35] px-[10px] text-[9px] font-black text-[#091006] hover:bg-[#d0ff75] sm:mt-[10px] sm:h-[32px] sm:px-[12px] sm:text-[10px]"
          >
            <Link href={banner.ctaHref}>
              {banner.ctaLabel}
              <ArrowRight className="ml-1 size-3 sm:size-3.5" />
            </Link>
          </Button>
        </div>

        {banners.length > 1 && (
          <>
            <button
              type="button"
              aria-label="Banner sebelumnya"
              onClick={() =>
                setActive((value) => (value - 1 + banners.length) % banners.length)
              }
              className="absolute left-2 top-1/2 z-20 grid size-[26px] -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/70 backdrop-blur transition hover:text-white sm:left-3 sm:size-[30px]"
            >
              <ChevronLeft className="size-3.5" />
            </button>
            <button
              type="button"
              aria-label="Banner berikutnya"
              onClick={() => setActive((value) => (value + 1) % banners.length)}
              className="absolute right-2 top-1/2 z-20 grid size-[26px] -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/70 backdrop-blur transition hover:text-white sm:right-3 sm:size-[30px]"
            >
              <ChevronRight className="size-3.5" />
            </button>
            <div className="absolute bottom-[8px] right-[10px] z-20 flex gap-1 sm:bottom-[10px] sm:right-[12px]">
              {banners.map((item, index) => (
                <button
                  key={item.id ?? index}
                  type="button"
                  onClick={() => setActive(index)}
                  aria-label={`Tampilkan banner ${index + 1}`}
                  className={`h-[4px] rounded-full transition-all ${
                    index === active ? "w-[20px] bg-[#b9ff35]" : "w-[6px] bg-white/35"
                  }`}
                />
              ))}
            </div>
          </>
        )}
      </div>
    </section>
  );
}

function mobileFallback(imageUrl: string) {
  return imageUrl.replace(/-banner(\.[a-z0-9]+)(?:\?.*)?$/i, "-cover$1");
}
