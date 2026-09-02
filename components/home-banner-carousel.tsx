"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ChevronLeft, ChevronRight } from "lucide-react";
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
  const showControls = banners.length > 1;

  function previousSlide() {
    setActive((value) => (value - 1 + banners.length) % banners.length);
  }

  function nextSlide() {
    setActive((value) => (value + 1) % banners.length);
  }

  return (
    <section className="mx-auto max-w-7xl px-4 pb-1 pt-3 sm:px-6 sm:pb-2 sm:pt-4 lg:px-8">
      <div className="relative">
        <Link
          href={banner.ctaHref || "/catalog"}
          aria-label={banner.title || "Buka banner promo"}
          className="group relative block h-[148px] overflow-hidden rounded-[12px] border border-white/10 bg-[#10131b] sm:h-[204px] sm:rounded-[14px] lg:h-[240px]"
        >
          <picture>
            <source media="(max-width: 639px)" srcSet={mobileImageUrl} />
            <img
              src={banner.imageUrl}
              alt={banner.title || "Banner LFAMILIA STORE"}
              className="absolute inset-0 size-full object-cover transition duration-500 group-hover:scale-[1.015]"
            />
          </picture>
        </Link>

        {showControls && (
          <>
            <button
              type="button"
              onClick={previousSlide}
              aria-label="Banner sebelumnya"
              className="absolute left-2 top-1/2 z-10 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/85 backdrop-blur-sm transition hover:bg-black/65 sm:left-3 sm:size-9"
            >
              <ChevronLeft className="size-4 sm:size-5" />
            </button>
            <button
              type="button"
              onClick={nextSlide}
              aria-label="Banner berikutnya"
              className="absolute right-2 top-1/2 z-10 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/85 backdrop-blur-sm transition hover:bg-black/65 sm:right-3 sm:size-9"
            >
              <ChevronRight className="size-4 sm:size-5" />
            </button>
          </>
        )}
      </div>
    </section>
  );
}

function mobileFallback(imageUrl: string) {
  return imageUrl.replace(/-banner(\.[a-z0-9]+)(?:\?.*)?$/i, "-cover$1");
}
