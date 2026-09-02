"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
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
    </section>
  );
}

function mobileFallback(imageUrl: string) {
  return imageUrl.replace(/-banner(\.[a-z0-9]+)(?:\?.*)?$/i, "-cover$1");
}
