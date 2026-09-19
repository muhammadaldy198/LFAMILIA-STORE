"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ChevronLeft, ChevronRight } from "lucide-react";
import type { HomeBannerRecord } from "@/lib/server/content";

export function HomeBannerCarousel() {
  const [banners, setBanners] = useState<HomeBannerRecord[]>([]);
  const [active, setActive] = useState(0);
  const [mobile, setMobile] = useState(false);
  const [loadError, setLoadError] = useState("");
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let mounted = true;
    void fetch("/api/home-content", { cache: "no-store" })
      .then(async (response) => {
        const data = await response.json().catch(() => ({})) as { banners?: HomeBannerRecord[]; error?: string };
        if (!response.ok) throw new Error(data.error || "Banner gagal dimuat.");
        return data;
      })
      .then((data) => {
        if (!mounted) return;
        setBanners(data.banners ?? []);
        setLoadError("");
      })
      .catch((reason) => {
        if (mounted) setLoadError(reason instanceof Error ? reason.message : "Banner gagal dimuat.");
      });
    return () => {
      mounted = false;
    };
  }, [reloadKey]);

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

  if (!visibleBanners.length) {
    if (!loadError) return null;
    return <section className="mx-auto max-w-7xl px-4 pt-3 sm:px-6 lg:px-8"><div className="flex items-center justify-between gap-3 rounded-xl border border-amber-300/15 bg-amber-300/[0.04] px-4 py-3 text-[10px] text-white/45"><span>{loadError}</span><button type="button" onClick={() => setReloadKey((value) => value + 1)} className="shrink-0 font-bold text-[#d8ff8d]">Coba lagi</button></div></section>;
  }

  const banner = visibleBanners[Math.min(active, visibleBanners.length - 1)];
  const mobileImageUrl = banner.mobileImageUrl || mobileFallback(banner.imageUrl);
  const showControls = visibleBanners.length > 1;
  const bannerHref = banner.ctaHref?.trim();

  function previousSlide() {
    setActive((value) => (value - 1 + visibleBanners.length) % visibleBanners.length);
  }

  function nextSlide() {
    setActive((value) => (value + 1) % visibleBanners.length);
  }

  return (
    <section className="mx-auto max-w-7xl px-4 pb-1 pt-3 sm:px-6 sm:pb-2 sm:pt-4 lg:px-8">
      <div className="relative">
        <article className="relative overflow-hidden rounded-[12px] border border-white/10 bg-[#10131b] sm:rounded-[14px]">
          {bannerHref ? (
            <Link
              href={bannerHref}
              aria-label={`Buka banner ${banner.title || "LFAMILIA STORE"}`}
              className="group block"
            >
              <BannerImage
                desktopImageUrl={banner.imageUrl}
                mobileImageUrl={mobileImageUrl}
                alt={banner.title || "Banner LFAMILIA STORE"}
              />
            </Link>
          ) : (
            <BannerImage
              desktopImageUrl={banner.imageUrl}
              mobileImageUrl={mobileImageUrl}
              alt={banner.title || "Banner LFAMILIA STORE"}
            />
          )}
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

function BannerImage({
  desktopImageUrl,
  mobileImageUrl,
  alt,
}: {
  desktopImageUrl: string;
  mobileImageUrl: string;
  alt: string;
}) {
  return (
    <picture className="block">
      <source media="(max-width: 639px)" srcSet={mobileImageUrl} />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={desktopImageUrl}
        alt={alt}
        className="block h-auto w-full transition duration-500 group-hover:scale-[1.01]"
      />
    </picture>
  );
}

function mobileFallback(imageUrl: string) {
  return imageUrl.replace(
    /-banner(\.[a-z0-9]+)(?:\?.*)?$/i,
    "-cover$1",
  );
}
