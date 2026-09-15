"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowRight, ChevronLeft, ChevronRight } from "lucide-react";
import type { HomeBannerRecord } from "@/lib/server/content";

export function HomeBannerCarousel() {
  const [banners, setBanners] = useState<HomeBannerRecord[]>([]);
  const [active, setActive] = useState(0);
  const [mobile, setMobile] = useState(false);

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
    const media = window.matchMedia("(max-width: 639px)");
    const update = () => setMobile(media.matches);
    update();
    media.addEventListener("change", update);
    return () => media.removeEventListener("change", update);
  }, []);

  const visibleBanners = banners.filter((item) =>
    mobile ? item.showMobile !== false : item.showDesktop !== false,
  );

  useEffect(() => {
    if (visibleBanners.length < 2) return;
    const timer = window.setInterval(
      () => setActive((value) => (value + 1) % visibleBanners.length),
      6500,
    );
    return () => window.clearInterval(timer);
  }, [visibleBanners.length]);

  if (!visibleBanners.length) return null;

  const banner = visibleBanners[Math.min(active, visibleBanners.length - 1)];
  const mobileImageUrl = banner.mobileImageUrl || mobileFallback(banner.imageUrl);
  const showControls = visibleBanners.length > 1;

  function previousSlide() {
    setActive((value) => (value - 1 + visibleBanners.length) % visibleBanners.length);
  }

  function nextSlide() {
    setActive((value) => (value + 1) % visibleBanners.length);
  }

  return (
    <section className="mx-auto max-w-7xl px-4 pb-1 pt-3 sm:px-6 sm:pb-2 sm:pt-4 lg:px-8">
      <div className="relative">
        <article className="group relative h-[148px] overflow-hidden rounded-[12px] border border-white/10 bg-[#10131b] sm:h-[204px] sm:rounded-[14px] lg:h-[240px]">
          <picture>
            <source media="(max-width: 639px)" srcSet={mobileImageUrl} />
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={banner.imageUrl}
              alt={banner.title || "Banner LFAMILIA STORE"}
              className="absolute inset-0 size-full object-cover transition duration-500 group-hover:scale-[1.015]"
            />
          </picture>
          <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(5,8,14,.9)_0%,rgba(5,8,14,.53)_48%,rgba(5,8,14,.04)_100%)]" />

          <div className="relative z-10 flex size-full items-end p-4 sm:items-center sm:p-7 lg:p-9">
            <div className="max-w-[78%] sm:max-w-xl">
              <h1 className="text-balance text-lg font-black leading-tight tracking-[-0.035em] text-white sm:text-3xl lg:text-4xl">
                {banner.title}
              </h1>
              {banner.subtitle && (
                <p className="mt-2 hidden text-balance text-xs leading-5 text-white/75 sm:block lg:text-sm">
                  {banner.subtitle}
                </p>
              )}
              {banner.ctaLabel && (
                <Link
                  href={banner.ctaHref || "/catalog"}
                  className="mt-3 inline-flex h-8 items-center rounded-lg bg-[#b9ff35] px-3 text-[10px] font-black text-[#091006] transition hover:bg-[#d0ff75] sm:mt-4 sm:h-10 sm:rounded-xl sm:px-4 sm:text-xs"
                >
                  {banner.ctaLabel}
                  <ArrowRight className="ml-1.5 size-3.5 sm:size-4" />
                </Link>
              )}
            </div>
          </div>
        </article>

        {showControls && (
          <>
            <button
              type="button"
              onClick={previousSlide}
              aria-label="Banner sebelumnya"
              className="absolute left-2 top-1/2 z-20 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/85 backdrop-blur-sm transition hover:bg-black/65 sm:left-3 sm:size-9"
            >
              <ChevronLeft className="size-4 sm:size-5" />
            </button>
            <button
              type="button"
              onClick={nextSlide}
              aria-label="Banner berikutnya"
              className="absolute right-2 top-1/2 z-20 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-black/45 text-white/85 backdrop-blur-sm transition hover:bg-black/65 sm:right-3 sm:size-9"
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
  return imageUrl.replace(
    /-banner(\.[a-z0-9]+)(?:\?.*)?$/i,
    "-cover$1",
  );
}
