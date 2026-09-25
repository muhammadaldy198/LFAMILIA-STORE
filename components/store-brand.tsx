"use client";

import Image from "next/image";
import { useState } from "react";
import type { StorefrontSettings } from "@/lib/store-data";

export function StoreBrand({ settings, compact = false }: { settings: StorefrontSettings; compact?: boolean }) {
  const logoUrl = settings.logoUrl || "/brand/lfamilia-logo-2026.jpg";
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  return (
    <>
      <span className={`${compact ? "size-9" : "size-10"} grid shrink-0 place-items-center overflow-hidden rounded-[4px] border border-[#2584ff]/55 bg-black shadow-[3px_3px_0_rgba(185,255,53,0.24),0_0_22px_rgba(37,132,255,0.28)]`}>
        <Image
          src={failedUrl === logoUrl ? "/brand/lfamilia-logo-2026.jpg" : logoUrl}
          alt={`Logo ${settings.storeName}`}
          width={40}
          height={40}
          className="size-full scale-[1.3] object-cover"
          unoptimized
          priority
          onError={() => setFailedUrl(logoUrl)}
        />
      </span>
      <span className="leading-none">
        <strong className="store-brand-name block max-w-36 truncate text-xs tracking-[0.1em] text-white">
          {settings.storeName.replace(/\s+STORE$/i, "")}
        </strong>
        <span className="store-brand-subtitle mt-1 block text-[9px] font-semibold tracking-[0.28em] text-white/40">STORE</span>
      </span>
    </>
  );
}
