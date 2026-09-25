"use client";

import Image from "next/image";
import type { StorefrontSettings } from "@/lib/store-data";

export function StoreBrand({ settings, compact = false }: { settings: StorefrontSettings; compact?: boolean }) {
  return (
    <>
      <span className={`${compact ? "size-9" : "size-10"} grid shrink-0 place-items-center overflow-hidden rounded-[4px] border-2 border-[#2584ff]/55 bg-black shadow-[3px_3px_0_rgba(185,255,53,0.24),0_0_22px_rgba(37,132,255,0.28)]`}>
        <Image src="/icon-192.png" alt={`Logo ${settings.storeName}`} width={192} height={192} className="size-full object-cover" priority />
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
