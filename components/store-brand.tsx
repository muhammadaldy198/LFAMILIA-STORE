"use client";

import Image from "next/image";
import { useState } from "react";
import type { StorefrontSettings } from "@/lib/store-data";

const defaultLogo = "/brand/lfamilia-logo-transparent-v2.png";

export function StoreBrand({ settings, compact = false }: { settings: StorefrontSettings; compact?: boolean }) {
  const logoUrl = settings.logoUrl || defaultLogo;
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  return (
    <>
      <span className={`${compact ? "size-8" : "size-9"} grid shrink-0 place-items-center`}>
        <Image
          src={failedUrl === logoUrl ? defaultLogo : logoUrl}
          alt={`Logo ${settings.storeName}`}
          width={320}
          height={320}
          className="size-full object-contain p-1"
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
